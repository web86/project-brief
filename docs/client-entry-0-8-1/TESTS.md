# Project Brief 0.8.1 — testing and security report

Local QA on 2026-10-29. Base `a533aca` / 0.8.0. No production data, Jino migration, SMTP credentials, external webhook consumer or deployment was used.

## Changes and boundaries

- The login page conditionally renders either its initial heading/form or the sent-state mail icon/heading/message. The old heading and email instructions are absent after acceptance. Another email returns to the form and focuses its field without a new request. The entered email is not echoed in the sent message.
- An in-flight guard and disabled input/button prevent duplicate sends. Route changes/unmount invalidate pending UI callbacks. Both unknown and known addresses show the same conditional text; the API response remains `{"accepted":true}`. 429 and network failures keep a usable form.
- Initial invitations retain the existing 7-day issuance. Public login retains 30 minutes and `revokeExisting:false`. Only the delivery context changes: login uses new `client_login` mail translations rather than `client_invitation`. Both HTML/plaintext variants have a localized Open project label. Other account mail falls back to its original heading label.
- The same access-token verification, project/member/session checks, mail availability guard and safe exception reporting remain in place. No Magic Link is returned in API/history/webhook payloads or logged. No new migration, table, credential system, scope, assurance policy, dependency or background process is added.
- The integrations selector changes a displayed example of the existing `sendInvitation` boolean; it writes no setting and grants no permission. Actual true/false API behavior and replay conflicts remain covered by the 0.8.0 provisioning suite.

## Focused results

| Check                                                            | Result                                                       |
| ---------------------------------------------------------------- | ------------------------------------------------------------ |
| Client login/invitations/provisioning/branding feature selection | PASS, including all four mail locales                        |
| Account/profile and branding regression                          | PASS                                                         |
| PHP 8.4.26 / MariaDB 11.8.9 selection                            | **85 tests, 991 assertions — PASS**                          |
| Frontend Node suite / Vite build                                 | **351 tests — PASS; build PASS**                             |
| Real HTTP/SMTP/Chromium, dev frontend                            | **11 scenario groups, 19 screenshots, 0 page errors — PASS** |
| Same HTTP/SMTP/Chromium flow, compiled frontend                  | **11 scenario groups, 19 screenshots, 0 page errors — PASS** |

Feature tests freeze time to whole seconds and assert the exact 7-day / 30-minute expiry, localized heading/body/button, HTML/text rendering, repeat entry, expiry rejection and redirect to the correct project. Initial invitation tests also confirm a 7-day link is not a 30-minute login email. Generic account mail keeps its old CTA. Branded client login and invitation mail retain the same branding, accent and escaped company text; platform security mail remains unbranded.

Browser checks cover a provisioning invitation opening the project directly with no email field; canonical guest login; a second submit while the first is pending producing one HTTP call; distinct sent/initial headings; another email/focus; identical known/unknown presentation; repeated Magic Link; foreign-project denial; revoked membership; SMTP failure; idempotency and concurrent provisioning; both selector values and no settings writes. Horizontal centering, vertical centering within the workspace below the header, and max width are measured at capture; overflow is rejected. Each locale is tested with mobile dark initial/sent states, desktop dark sent state and recoverable 429/network errors. English additionally has desktop light initial/sent states. This is a selected matrix, not a full Cartesian product.

Sent-state mobile RU, initial desktop EN and integration-mode mobile screenshots were visually inspected. The existing site header and CSS theme/branding tokens are preserved. A flex column scoped to the client-login route centers the card in the available workspace; project layouts retain their normal flow. Unauthenticated entry continues to use platform branding without exposing private workspace information; authenticated project branding and email branding retain their existing policy.

Browser evidence: `artifacts/client-entry-0-8-1/sqlite/`. SMTP messages and synthetic login secrets stay in memory and are scanned against application logs/history/webhook payloads. The compiled run replaces the same scenario's screenshots/JSON; its report has `productionBuild:true`. No external inbox-delivery claim is made.

## Reproduction

```sh
# api/
php artisan test --compact tests/Feature/ClientProjectLoginTest.php tests/Feature/ClientInvitationsTest.php tests/Feature/ProjectProvisioningTest.php tests/Feature/WorkspaceBrandingTest.php
vendor/bin/pint --dirty --format agent

# repository root
npm test
npm run build
CLIENT_ENTRY_PRODUCTION_BUILD=1 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/provisioning.browser.mjs
```

The browser uses temporary SQLite/storage/cache, two local PHP processes and a local SMTP sink. Independent presentation/error cases clear only this disposable QA cache to isolate rate-limit budgets; the actual server throttling contract remains tested by Laravel. 429 and network UI failures are injected by the browser; SMTP/auth/provisioning/redirect flows use real local HTTP.

The existing `api/tests/provisioning.php84.sh` runs against a disposable `projectbrief_m80_qa` MariaDB with read-only repository mount. DDL rollback stays covered separately by SQLite because MariaDB DDL implicitly commits. Its temporary database/container is removed after testing. New application dependencies are not installed for browser QA.

The canonical ZIP builder repeats full Laravel/frontend tests and validates production vendor, secret/artifact exclusions, internal checksums and extracted runtime. Full native Laravel QA uses a temporary test-only PHP `memory_limit=512M`, as in 0.8.0; application/production limits are unchanged. Final counts, ZIP byte size and SHA-256 are recorded in RELEASE-ARTIFACT.json after the build.

## Hosting boundary

Local PHP 8.4/MariaDB coverage does not verify Jino proxy/NAT/Apache settings, staging backup/restore, external SMTP/inbox or webhook consumer. Complete the smoke in PRODUCTION.md on the agreed host before deployment. Push, merge and deployment are not part of this work.

## Final canonical release

**PASS: 867 Laravel tests / 12,773 assertions; 351 frontend tests; production frontend build.** npm installation/audit reported 0 vulnerabilities. The full suites were repeated by the canonical builder from the clean source commit recorded in RELEASE-ARTIFACT.json.

The extracted ZIP passed production vendor/platform, signed WebAuthn, provisioning/contact/membership, failed SMTP durability, idempotency/external-ID recovery, canonical project access and existing API/invitation/export/CLI/CSRF/maintenance checks. The new public-login mail context and all four client mail locale keys are present; the dedicated login CSS asset is included. All ZIP CRCs and internal manifest hashes were independently checked after the build. No tests, screenshots, reports, frontend sources, node_modules or development vendor are in the ZIP.

Exact size, SHA-256, source commit and evidence inventory are in `RELEASE-ARTIFACT.json` and `SHA256SUMS.txt`. Prior 0.8.0 archives are preserved. No push, merge or deployment was performed.
