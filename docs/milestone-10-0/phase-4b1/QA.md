# Validation evidence

Automated tests use synthetic accounts, disposable databases, mocked provider endpoints and ephemeral signed Google JWTs. No real Google/Yandex/GitHub credentials, SMTP delivery or Jino access were used. Final artifact identity and runtime evidence are recorded in ARTIFACTS.md.

## Security matrix

`OnboardingTest`: duplicate/generic registration, no auto-session/provisioning, owned one-time proof, replay, expiry/resend/address correction, pending tenant isolation, production hard gates, legacy unverified access, recovery without verification, primary aggregate privacy, invitation acceptance without owner/Trial, bounded attempt pruning, mandatory audit rollback.

`SocialAuthTest`: Google RS256 and issuer/audience/azp/iat/exp/nonce/sub rejection, signature/PKCE failure, state/provider/expiry/cancellation, GitHub numeric identity and verified primary email, mutable username, Yandex stable id/client binding with local email proof, collision without merge, fresh explicit linking, already-owned subject refusal, last-method unlink and missing-email fallback.

`TrialEligibilityTest`: exact 604800-second UTC/microsecond Trial, no Grace, published pinned targets, replay/no retiming, second workspace refusal, delete/re-register durable alias, appended email/provider aliases, legacy/import refusal, unconfigured retention/targets fail closed.

`OnboardingMigrationTest` and `WorkspaceMigrationTest`: fresh schema, populated legacy/default preservation, repeated migration, missing-index repair. `OnboardingConcurrencyTest`: independent MariaDB workers race normalized registration, duplicate callback, same subject on two actors, same-owner provisioning and test-only Trial activation.

## UI and package

`tests/onboarding.browser.mjs`: real local backend + temporary SQLite, captured mail and mock GitHub; email form → same-tab proof link → real TOTP/recovery acknowledgment → pending activation; connected methods; provider cancel/retry/PKCE; public login/register/verification, history navigation and primary diagnostics in RU/EN/TR/ZH-CN × light/dark/system, desktop/375/390. It also accepts UX_PACKAGE_ROOT for the actual extracted production build/vendor.

Existing branding/browser/security/package regressions cover custom branding priority, legacy owner/developer/client paths, passkeys, account recovery, agreements, team/agent privacy, API, SSO, exports and PHP8.4/GD runtime. `tests/onboarding.package.mjs` is part of the canonical builder and exercises production vendor, migrations, legacy password defaults, hard production gates, atomic proof/provisioning and absence of Trial periods/test activation runtime.

Static Demo validation checks the prior manifest pin, unchanged synthetic data, read-only routes, Developer/Client journeys and zero API/writes/external traffic. Additional public onboarding deep links must also remain inert.

## Final results

- Canonical source: PHP8.5.10/SQLite **1,179 tests /1,173 passed /16,889 assertions**,6 explicit MariaDB-only skips, no errors/failures. Frontend **453 passed**, brand check/build passed.
- Full PHP8.4.26/MariaDB11.8.9 release-commit run: **1,179 passed /17,047 assertions**, no skipped tests, failures or errors. It includes independent worker races, populated/reentrant migrations and the final atomic invitation-mismatch case.
- Both source and actual compiled ZIP: **15 browser scenarios /50 screenshots each**, zero console/page errors; mock OAuth only. Explicit linking exercises the actual MFA step-up and safe callback destination. The harness clears only its scratch cache between enrollment and linking/matrix cases to isolate rate-limit windows; production limits are unchanged.
- Actual ZIP on PHP8.4.26: signed WebAuthn plus full HTTP/runtime/API/SSO/upgrade/files/cron, plans, onboarding, privacy and absent-demo checks passed using production vendor. A separate fresh MariaDB11.8.9 database passed the packaged onboarding contract without Faker/factories/test activation code.
- Static Demo current preview: **87 screens**,0 API/write/external/console/page errors,143 byte-identical dataset/schema files, pinned manifest hash unchanged. Existing immutable v3 ZIP is reused. Branding browser9 groups passed.
- Final extracted package scan and checksums passed; previous production/static ZIPs remain unchanged. Migration runs include fresh MariaDB and populated legacy/reentrant/index-repair tests from the source suites.

Evidence is local/automated, not a claim of production verification. Real provider consent/credentials, SMTP, Jino, hardware TouchID and Safari/WebKit were not tested. The existing Vite >500kB chunk warning remains. See [artifacts](ARTIFACTS.md) for immutable ZIP identity and [runbook](PRODUCTION.md) for deployment/rollback boundaries.
