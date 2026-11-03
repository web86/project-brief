# Validation evidence

Automated tests use synthetic accounts, disposable databases, mocked provider endpoints and ephemeral signed Google JWTs. No real Google/Yandex/GitHub credentials, SMTP delivery or Jino access were used. Final counts and artifact verification are recorded below after completion.

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

Pending completion of full source/package/browser runs. See ARTIFACTS.md for immutable release identity; this section will be finalized before delivery.
