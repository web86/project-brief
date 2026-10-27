# Passkey hotfix 0.7.1 — investigation and verification

Date: 2026-10-27. Branch: `codex/milestone-7-account-security-passkeys`. Baseline: `423fe92` (0.7.0 implementation `4248693`). No push, merge, production requests with credentials, deployment, or changes to user secrets.

## What is established

The supplied production 422 **does not establish that TOTP was reused**. In the 0.7.0 source, the exact generator of `errors.code` is `AccountSecurity::valid(false)`. It is used by factor verification and challenge validation; additionally, `AccountSecurityController::passkey()` called it from `catch (Throwable)`, covering deserialization, WebAuthn, database writes, events and session changes. A failed database insert therefore produced the same 422 as a replayed TOTP. The frontend then discarded status/body again in the Laravel client error wrapper and in component catches. These two diagnostic defects are reproduced by regression tests and corrected.

The original Touch ID failure on Jino remains **unattributed**. There is no production session/host access or new failure reference. Do not present this ZIP as proof that a particular production WebAuthn check or SQL error has been fixed. It fixes error handling and retry UX, and provides safe diagnostics to identify that gate if the failure persists. Installation and one reproduction with the new reference are operator actions described in [PRODUCTION.md](PRODUCTION.md#safe-jino-diagnosis).

## Chain and TOTP hypothesis

| Stage                              | Finding / regression evidence                                                                                                                                                                                                                                                                                           |
| ---------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TOTP setup and confirm             | Fortify pending secret is promoted only after monotonic TOTP verification. Accepted time step is persisted; repeating that code remains 422 `SEC_FACTOR_INVALID`.                                                                                                                                                       |
| Server proof                       | Confirming TOTP sets server-side `mfa` proof, user ID, security version and timestamp. Factor change updates the version in DB/current session and regenerates session/CSRF. Proof lasts ten minutes.                                                                                                                   |
| Recovery acknowledgement           | Requires fresh proof, marks saved; does not ask for or consume another TOTP, nor extend proof lifetime.                                                                                                                                                                                                                 |
| Registration options               | Requires fresh proof and owner prerequisites; official options require resident key and UV. Database challenge expires after two minutes and is bound to purpose, user, version and hashed session ID. New options replace the previous request for that session/purpose.                                               |
| Browser creation and serialization | The same locked SimpleWebAuthn serializer used by the former Laravel wrapper handles ArrayBuffer/Base64URL and optional browser APIs. Transport now uses `src/api/client.js`; no dependency/lock change, global fetch patch or native-toJSON dependency.                                                                |
| POST and CSRF                      | Same-origin cookies and existing Laravel request-forgery protection retained. No automatic POST retry or replay after 419/423/timeout. CSRF is reset for the next ceremony.                                                                                                                                             |
| Challenge and WebAuthn             | Consume atomically once; origin, RP hash, challenge, UV and official attestation verification remain enabled. Distinct rejection codes identify gates without exposing their inputs.                                                                                                                                    |
| Persistence and onboarding         | Official StorePasskey validation/uniqueness/persistence are preserved. A small subclass marks stages only. Transaction failure keeps factors and restricted state; persistence errors remain 500 rather than false TOTP 422. Successful commit revokes other sessions and returns fresh authoritative onboarding state. |

The exact production origin and RP ID pass both a signed PHP protocol test and the complete Chromium enrollment scenario. The browser sees `https://brief.web86.site` (no alternate port); Playwright routes **all** requests to local HTTPS/Laravel with disposable SQLite, local test secrets and database sessions. No live production traffic is sent by that scenario. A failed server challenge after real virtual-authenticator creation leaves zero server credentials and enrollment restricted; a second explicit Add Passkey obtains a new challenge and completes setup without re-entering the accepted TOTP.

## Changes

- Replaced blanket `Throwable → errors.code` conversion with typed validation handling and safe diagnostics. Database/runtime errors keep 500. HTTP 400/401/403/419/422/423/429/500/503 are distinguishable.
- Security error responses contain safe `code`, `stage`, random `diagnosticId` and a generic localized fallback; frontend maps codes to RU/EN/TR/ZH-CN user messages. Logs contain no request payload, challenge, cookie, secret, SQL bindings, exception message or trace. A real SQL error regression tests this boundary.
- Passkey and TOTP setup, confirmation, recovery, rename/remove, Passkey login and identity confirmation use the existing global toast store. Onboarding keeps contextual form messages and diagnostic references. Guest/expired proof refreshes authoritative status.
- Browser-created but unconfirmed credentials are explicitly explained. Refresh/check server device list, then start a new ceremony if absent. No old payload is automatically resent, no working credential is deleted, and no factor policy is relaxed.
- Added read-only `php artisan account:security-diagnostics`, with an allowlist of configuration/schema booleans. Production instructions cover effective cached origin/RP, session/CSRF, PHP-FPM vs CLI, reverse proxy metadata, DB/schema and safe failure evidence.

Owner mandatory TOTP + Passkey, recovery acknowledgement, proof expiry, UV, cryptographic checks, replay protection, session revocation, throttling, authorization, workspace boundaries and client authentication remain in place. No migration, dependency upgrade, secret reset or permissive trusted-proxy setting was added.

## Verification

Focused runs before release:

- PHP security suite: **33 tests / 507 assertions**, native PHP 8.5.10; PHP 8.4.26 with MariaDB 11.8.9 (final run recorded with artifact).
- Frontend: **20 focused tests**, including all requested failure statuses and Safari/Firefox/Chromium API shapes with the actual installed serializer. Full suite/build run by the canonical release builder.
- HTTPS browser: **11 groups**, including complete real TOTP → saved recovery → Chromium resident UV Passkey → authenticated state; cancellation; real challenge replacement after credential creation; safe retry; toast/form references; exact production origin/RP; cookie/session/CSRF lifecycle; UV login; unchanged developer/client flows; four locales/mobile/light/dark/system; Firefox engine serializer compatibility with a mock authenticator.
- MariaDB concurrency: **4 groups × 6 workers**; only one TOTP, recovery code or actual `passkey_register` challenge use succeeds; concurrent deletion retains one owner Passkey.
- PHP Pint and changed-file Prettier; `git diff --check`.

Canonical archive/build and full-suite results, SHA-256 and byte size are recorded in [PASSKEY-FIX-ARTIFACT.json](PASSKEY-FIX-ARTIFACT.json). Browser details are in [PASSKEY-FIX-BROWSER.json](PASSKEY-FIX-BROWSER.json). Historical 0.7.0 reports are retained.

## Limits

No physical Touch ID, Safari hardware ceremony, live Jino config/cookie/proxy/DB inspection, production smoke or SMTP test was performed. Firefox uses a real browser engine with a mock authenticator for serialization, not a physical credential. The installed special mac14 WebKit runtime failed before page creation with `Page.overrideSetting: Unknown setting: PushAPIEnabled`; that is a test-runtime limitation, not evidence of an application failure. Safari API shape tests pass; rerun `M7_TEST_WEBKIT=1` on a compatible Playwright host for the additional engine test. Current production root cause must be determined from one safe failure reference after an authorized installation.

Technical references: [SimpleWebAuthn browser API](https://simplewebauthn.dev/docs/packages/browser) describes the options → authenticator → server sequence. The implementation was checked against the **installed 13.3.0 source**, not newer API assumptions. [MDN toJSON](https://developer.mozilla.org/en-US/docs/Web/API/PublicKeyCredential/toJSON) documents Base64URL representation and older-browser availability limits; no native toJSON is required by this implementation.
