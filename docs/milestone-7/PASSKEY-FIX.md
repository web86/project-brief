# Passkey hotfix 0.7.1 — investigation and verification

Date: 2026-10-27. Branch: `codex/milestone-7-account-security-passkeys`. Baseline: `423fe92` (0.7.0 implementation `4248693`). No push, merge, production requests with credentials, deployment, or changes to user secrets.

## What is established

The cause is **vendor pruning in the production ZIP**, not TOTP reuse. `forbiddenPath()` in `deployment/release-files.mjs` classified the WebAuthn runtime source `web-auth/webauthn-lib/src/Credential.php` as a secret file because its basename matched `credentials?`. `pruneVendor()` removed it before Composer optimized autoload generation. `PublicKeyCredential extends Credential`, so options generation and browser Touch ID could succeed, but server credential deserialization then failed with `Class "Webauthn\Credential" not found` before verification or storage.

The exact generator of the observed `errors.code` was `AccountSecurityController::passkey()`'s `catch (Throwable)` calling `AccountSecurity::valid(false)`. That converted the missing-class error into the same 422 as a failed factor check. The frontend discarded the remaining response information and displayed the generic error at the top of the panel.

**Causal reproduction on PHP 8.4.26 with production vendor:** the unchanged 0.7.0 ZIP `project-brief-v0.7.0-4248693.zip` lacks this file and the complete signed enrollment ends with `ValidationException`, status **422**, `errors.code`, `runtimeBasePresent:false`. In an isolated copy of that same old package, restoring **only the upstream Credential.php file**, with no controller/config/TOTP changes, makes the complete signed UV registration finish with `state=authenticated`; replaying the enrollment TOTP is still rejected. The before/after evidence is recorded in [PASSKEY-FIX-CAUSE.json](PASSKEY-FIX-CAUSE.json). The working checkout retained the class, explaining why ordinary PHP and HTTPS browser tests passed while the uploaded ZIP failed.

The fix narrowly retains that exact upstream source path (and the Laravel Console `Secret.php` runtime class affected by the same filename rule), adds it to required package contents, and makes a signed enrollment test against the **extracted ZIP with no-dev vendor** part of the canonical builder. Credential JSON, secret files, keys and sensitive directories remain excluded/scanned. No authentication requirement or cryptographic check is bypassed.

The live Jino filesystem has not been inspected. [PRODUCTION.md](PRODUCTION.md#safe-jino-diagnosis) includes a read-only command to verify this missing class in the installed 0.7.0 package before an authorized update. Proxy/cookie configuration can still be checked through safe diagnostics, but is not needed to explain the reproduced package failure.

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

- Fixed the overly broad vendor filename filter with an exact runtime-source exception; archive validation requires the class. Added `tests/account-security.package.mjs` / `.php`, invoked by the canonical builder after extracting the ZIP.
- Replaced blanket `Throwable → errors.code` conversion with typed validation handling and safe diagnostics. Database/runtime errors keep 500. HTTP 400/401/403/419/422/423/429/500/503 are distinguishable.
- Security error responses contain safe `code`, `stage`, random `diagnosticId` and a generic localized fallback; frontend maps codes to RU/EN/TR/ZH-CN user messages. Logs contain no request payload, challenge, cookie, secret, SQL bindings, exception message or trace. A real SQL error regression tests this boundary.
- Passkey and TOTP setup, confirmation, recovery, rename/remove, Passkey login and identity confirmation use the existing global toast store. Onboarding keeps contextual form messages and diagnostic references. Guest/expired proof refreshes authoritative status.
- Browser-created but unconfirmed credentials are explicitly explained. Refresh/check server device list, then start a new ceremony if absent. No old payload is automatically resent, no working credential is deleted, and no factor policy is relaxed.
- Added read-only `php artisan account:security-diagnostics`, with an allowlist of configuration/schema booleans. Production instructions cover effective cached origin/RP, session/CSRF, PHP-FPM vs CLI, reverse proxy metadata, DB/schema and safe failure evidence.

Owner mandatory TOTP + Passkey, recovery acknowledgement, proof expiry, UV, cryptographic checks, replay protection, session revocation, throttling, authorization, workspace boundaries and client authentication remain in place. No migration, dependency upgrade, secret reset or permissive trusted-proxy setting was added.

## Verification

Focused runs before release:

- PHP security suite: **33 tests / 508 assertions** on native PHP 8.5.10 and PHP 8.4.26/SQLite; **33 / 507** on PHP 8.4.26/MariaDB 11.8.9 before the extra read-only runtime assertion. The original-package causal test also passes on PHP 8.4.26 after restoring only the missing runtime class.
- Frontend: **20 focused tests**, including all requested failure statuses and Safari/Firefox/Chromium API shapes with the actual installed serializer. Full suite/build run by the canonical release builder.
- HTTPS browser: **11 groups**, including complete real TOTP → saved recovery → Chromium resident UV Passkey → authenticated state; cancellation; real challenge replacement after credential creation; safe retry; toast/form references; exact production origin/RP; cookie/session/CSRF lifecycle; UV login; unchanged developer/client flows; four locales/mobile/light/dark/system; Firefox engine serializer compatibility with a mock authenticator.
- MariaDB concurrency: **4 groups × 6 workers**; only one TOTP, recovery code or actual `passkey_register` challenge use succeeds; concurrent deletion retains one owner Passkey.
- PHP Pint and changed-file Prettier; `git diff --check`.

Canonical archive/build and full-suite results, SHA-256 and byte size are recorded in [PASSKEY-FIX-ARTIFACT.json](PASSKEY-FIX-ARTIFACT.json). Browser details are in [PASSKEY-FIX-BROWSER.json](PASSKEY-FIX-BROWSER.json). Historical 0.7.0 reports are retained.

## Limits

No physical Touch ID, Safari hardware ceremony, live Jino config/cookie/proxy/DB inspection, production smoke or SMTP test was performed. Firefox uses a real browser engine with a mock authenticator for serialization, not a physical credential. The installed special mac14 WebKit runtime failed before page creation with `Page.overrideSetting: Unknown setting: PushAPIEnabled`; that is a test-runtime limitation, not evidence of an application failure. Safari API shape tests pass; rerun `M7_TEST_WEBKIT=1` on a compatible Playwright host for the additional engine test. The packaging cause is reproduced. Live Jino file presence and successful physical Touch ID after an authorized update still require operator verification.

Technical references: [SimpleWebAuthn browser API](https://simplewebauthn.dev/docs/packages/browser) describes the options → authenticator → server sequence. The implementation was checked against the **installed 13.3.0 source**, not newer API assumptions. [MDN toJSON](https://developer.mozilla.org/en-US/docs/Web/API/PublicKeyCredential/toJSON) documents Base64URL representation and older-browser availability limits; no native toJSON is required by this implementation.

## Final artifact

Canonical build from `22db2f3d2e67fcaff5e5fee80b23d877a7250c37`: **725 PHP tests / 8757 assertions**, **273 frontend tests**, production build, checksums/content exclusion, extracted runtime and signed enrollment all passed. The exact extracted ZIP also passes signed enrollment with **PHP 8.4.26 and production vendor**. Archive: `project-brief-v0.7.1-22db2f3.zip`; 39145162 bytes; SHA-256 `71c873950cb35a1e893680d631052ebe045d108a0013cd1b760859869b350a01`. Both required runtime classes are present; 206 packaged PHP source files match the checkout byte-for-byte. No push, merge, deployment or user-secret change.
