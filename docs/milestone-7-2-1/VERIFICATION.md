# Milestone 7.2.1 verification — 27 October 2026

Branch: `codex/milestone-7-2-flexible-account-security`; baseline local 0.7.2 HEAD `72db56a`. All test accounts, factors, sessions and databases are disposable. No production credentials/data/configuration changed. No push, merge, issue mutation or deployment.

| Check                                              | Result                                                                                 |
| -------------------------------------------------- | -------------------------------------------------------------------------------------- |
| PHP 8.5.10 full suite / SQLite                     | 751 passed, 9075 assertions                                                            |
| PHP 8.3.36 focused security / SQLite               | 59 passed, 818 assertions                                                              |
| PHP 8.4.26 focused security / SQLite               | 59 passed, 818 assertions                                                              |
| PHP 8.4.26 / MariaDB 11.8.9 security + new step-up | 57 passed, 789 assertions                                                              |
| Frontend suite                                     | 297 passed                                                                             |
| HTTPS browser E2E                                  | 23 scenario groups passed, exact production origin/RP redirected entirely to localhost |
| MariaDB races                                      | 5 groups × 6 processes; recovery/TOTP/challenge one use, factor deletion, revoked ACL  |
| UI                                                 | RU/EN/TR/ZH-CN; 1440 light / 390 dark, no horizontal overflow; system theme follows OS |
| Formatting                                         | Pint changed PHP, Prettier changed source/tests/docs, diff whitespace check            |

New feature tests verify typed pre-mutation denials leave DB untouched, real UV confirmation preserves account/workspace/version while rotating the session, exact permission persistence, challenge replay rejection, actual 300-second proof lifetime/context invalidation, TOTP/recovery one-use, password alternative policy, current ACL and resource-specific irreversible confirmation. A generic 423 has no replay promise.

Frontend tests verify synchronous native invocation, prepared options bypassing network awaits, no-key alternatives, cancellation/unavailability, lost activation fallback, safe error references, context/expiry/invalidation, background challenge preparation, immutable nested drafts, in-flight deduplication, one typed continuation with refreshed CSRF, second-denial termination, no retry after network/HTTP 400/401/403/419/422/500, and ordinary/client/bearer path exclusions. Existing Safari/Firefox/Chrome serializer-shape regressions and locale parity remain in the full suite.

Chromium uses a real resident, user-verified virtual authenticator and actual Laravel cookies/CSRF. Team Save changes five permissions, records `navigator.userActivation.isActive === true` at native get, zero application `showModal` calls and one PATCH even on double click. A valid proof saves without another ceremony. Native cancellation sends no mutation/critical toast and retains the exact draft. Unsupported provider -> allowed recovery -> original action once; explicit retry uses a new actual UV assertion. Original owner TOTP -> saved recovery -> fresh proof -> real signed registration remains covered, including created-but-unsaved credentials and safe fresh registration. Client session and voluntary developer-factor scenarios pass.

Firefox runs the actual browser engine and installed serializer with a minimal mocked authenticator. Native get is invoked with real click activation and no preceding app dialog; a cancelled provider falls back to real server recovery and saves the original team draft. This is not a successful hardware Touch ID assertion. The current bundled mac14-special WebKit probe reported `Page.overrideSetting: Unknown setting: PushAPIEnabled` and hung for over two minutes before termination; native WebKit/Safari cannot be certified on this host. Physical Touch ID and live Jino FPM/proxy/filesystem/database behavior were not tested.

Sanitized BROWSER-RESULTS.json and CONCURRENCY-RESULTS.json plus eight fallback screenshots are retained here. Runtime logs, credentials, payloads, failure captures and QA materials are excluded from the production ZIP. The first parallel MariaDB test attempt interfered with the shared disposable test schema; final race results above were rerun alone after PHP tests completed. The final 57-test PHP/MariaDB suite was then also rerun alone on a fresh disposable server and passed. No product workaround was made for that test scheduling error.

## Reproduce

```sh
cd api
php artisan test --compact
php artisan test --compact tests/Feature/AccountSecurityTest.php tests/Feature/FlexibleAccountSecurityTest.php tests/Feature/FlexibleSecurityMigrationTest.php tests/Feature/SeamlessSecurityConfirmationTest.php
vendor/bin/pint --dirty --format agent
cd ..
npm test
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs M7_TEST_PRODUCTION_ORIGIN=1 M7_EVIDENCE_DIRECTORY=/private/tmp/project-brief-m721-evidence node tests/account-security.browser.mjs
```

Races require a dedicated disposable MariaDB named `projectbrief_m7_security_qa`, connection variables and `node tests/account-security.concurrent.mjs`. Do not run PHP RefreshDatabase suites against that same schema concurrently. Do not run this test against an installed site. The local PHP 8.4 container lacks GD; its focused security pass is not a full runtime certification.

For the clean-tree canonical package verification:

```sh
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs RELEASE_SECURITY_BROWSER_QA_DIRECTORY=/private/tmp/project-brief-m721-package-evidence ./bin/build-release
```

Test service workers are blocked, HTTP is locally routed, and Vite HMR uses a harness-only socket; WebAuthn and server verification are real. The optional browser hook serves only extracted ZIP assets/vendor on an isolated database and locally routes the exact production origin. It performs actual built-UI registration and the direct native step-up/team-save scenario. Final results and artifact identity follow below.

## Final extracted ZIP

Source commit: `05d44141ce135f5142792280b8b764a5a7b5e067`. The canonical builder passed the full tests, brand check, production build, no-dev vendor installation/pruning, secret/artifact exclusion, ZIP CRC, 8807 manifest entries and actual extracted HTTP/CLI smoke. The smoke covers first-install and repeated install with preserved APP_KEY, optimized routes, CSRF, admin/client sessions, bearer boundaries and scoped deletion.

The actual extracted compiled UI passed real virtual-UV registration, then Team Save with real click activation, zero application dialogs, exactly one PATCH and five changed permissions persisted on the server. No source/Vite modules are served by this package check. See [packaged browser result](PACKAGED-BROWSER-RESULTS.json) and [screenshot](screenshots/packaged-team-success.png).

A fresh extraction passed signed enrollment on PHP 8.4.26 using only the ZIP's production vendor. Both `Webauthn\Credential` and `Illuminate\Console\View\Components\Secret` autoload. TOTP replay remains rejected and registration clears fresh proof. All 207 non-generated application PHP sources match checkout byte for byte. CRC validation covers all 8808 archive file entries; the manifest verifies 8807 files and excludes its own checksum file. See [PHP 8.4 result](PACKAGED-WEBAUTHN-PHP84.json).

Archive: `project-brief-v0.7.3-05d4414.zip` — 39149306 bytes.

SHA-256: `86f507c330d4bec938b84aa2c68a95084b39f609185354dc89afe0dc9c9182a9`.

[Artifact metadata](RELEASE-ARTIFACT.json), [checksum file](SHA256SUMS.txt), [Jino update/rollback](PRODUCTION.md), [implementation](IMPLEMENTATION.md). The original 0.7.2 ZIP remains with its original verified checksum. Final report-only commits do not change the archive's runtime source commit. No deployment or publication was performed.

## Fallback screenshots

| Locale | Desktop light                                     | Mobile dark                                    |
| ------ | ------------------------------------------------- | ---------------------------------------------- |
| RU     | [1440](screenshots/fallback-ru-1440-light.png)    | [390](screenshots/fallback-ru-390-dark.png)    |
| EN     | [1440](screenshots/fallback-en-1440-light.png)    | [390](screenshots/fallback-en-390-dark.png)    |
| TR     | [1440](screenshots/fallback-tr-1440-light.png)    | [390](screenshots/fallback-tr-390-dark.png)    |
| ZH-CN  | [1440](screenshots/fallback-zh-CN-1440-light.png) | [390](screenshots/fallback-zh-CN-390-dark.png) |
