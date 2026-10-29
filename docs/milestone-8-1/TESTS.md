# Milestone 8.1 / release 0.8.2 — security and testing

Local synthetic QA, 2026-10-29. Base `8c616fd`, completed Client Entry UX0.8.1. No production database, Jino host, real client credentials, external inbox, MODX installation or external webhook consumer was used. Full canonical release counts and exact artifact metadata are recorded after final packaging.

## Authorization and privacy

- Stateless bearer verification, existing IP/workspace budgets, safe v1 error codes and JSON body limits are reused. Create/read/decision scopes are separate; decisions recheck the active unexpired/unrevoked token and required scope inside the workspace transaction. `requests:approve` does not grant either direct project creation endpoint. Source assignment is owner-only and workspace scoped. No existing token is changed or granted new scopes.
- Request UUID, source, external identity, list, search, count, details and decisions remain workspace scoped. Foreign UUID reads/decisions return404. Application management requires an active owner at request time and again under the mutation lock; project creation grants to other roles do not grant request management. Create-only replay returns minimal metadata without internal reason/actor/contact data.
- Submission stores unverified form data without creating a project, contact, membership or access token. Approval reuses contact resolution and ProjectCreation/ProjectProvisioning; mailbox proof is still required for client access. Existing clients in another workspace are never reused.
- Webhook payloads contain request/source/external IDs, status/timestamp and approved project ID/URL only, within the existing envelope. No contact, title/description, reason, bearer or Magic Link. Operation logs contain type/UUID/status/outcome, never request bodies. SafeExceptionReporter excludes exception messages/SQL bindings. Examples keep credentials outside public, use HTTPS/cURL and generic public receipts/errors; privileged MODX handlers require Manager authorization, privileged ACL and CSRF for decisions.

## Atomicity and recovery

The existing workspace lock serializes permanent `(workspace, source, externalId hash)` deduplication and all UI/API decisions. Unique database binding protects duplicate submission independently of token/key; body fingerprints reject silent replacement. Credential rotation selects the same persistent source. Changed final choices/reasons and approve/reject races are409. No reopen state is added.

Approval commits request decision, normal business records, provisioning link and webhook outbox together. A forced membership creation failure rolls everything back to pending. SMTP starts after commit using existing invitation states: failed/uncertain sending is not automatically resent; pending can recover, and explicit owner resend uses the existing Clients UI. The decision author and result remain stable across retries/rotation. Business replays produce no new request webhook/owner notification.

Request notifications extend the existing notification delivery table with an optional request FK and nullable project FK; existing rows retain their values. Existing email/push/Telegram transports and personal channel preferences are used only for current workspace owners, with durable per-request/recipient/channel deduplication. There is no new service/worker/cron.

Requests append to the existing Live Sync journal; owners refresh count/visible requests through the same coordinator. Non-owner sync excludes request journal entries. A concurrency test exposed SQLite's read-to-write lock upgrade in journal append; it now reserves the writer before its first read, matching IntegrationWrites. This preserves serial event allocation and avoids a post-commit500 under concurrent decisions. Visible detail refresh retains its identity and local rejection draft; workspace changes discard pending private state. No new polling is added.

## Automated coverage and reproduction

- `api/tests/Feature/ProjectRequestsTest.php`: creation/no access, same/new key, changed body, same-source replacement, different source, final decision/reason, existing contact, SMTP failure, rollback, scope separation, tenant isolation, owner-only UI, validation/size/rate limit, literal search/empty search, source authorization, old-token invariance, notification preferences/dedup in4locales, owner sync/count, webhook privacy/filtering/HMAC retry and rollback guard.
- Existing integration/provisioning/client invitation/login, Live Sync, notification, migration, project/task/client, MFA/passkey suites remain in the full native run. All-scopes token tests now explicitly create a request source. The legacy migration comparison verifies the new request FK is null while retaining all old notification delivery values.
- `tests/project-requests.test.js`: pending decision duplicate guard, lost-response idempotency key, stale workspace results, server conflict refresh, preserving detail during sync, owner route/navigation and4locale controls. Existing translation parity and navigation tests remain intact.
- `tests/project-requests.browser.mjs`: real compiled UI, two PHP HTTP processes, disposable database and local SMTP. Runs the actual standalone cURL helper; create→owner notification→Live Sync→owner approval→provisioning→7-day invitation→direct client URL. Covers parallel submissions, UI/API simultaneous approval, approve/reject race, source/token replacement, SMTP failure, rejection confirmation, draft retention, escaping, roles, scopes/source UI, operation history and event/log secret/PII scan. Desktop/mobile, light/dark and RU/EN/TR/ZH-CN are selected combinations, not a full Cartesian matrix. SMTP acceptance is local, not inbox proof.
- `tests/provisioning.browser.mjs` continues the existing provisioning/client-entry flows. Its upgrade harness now defers all migrations after the old foundation together, so later request FKs are never applied before their referenced table on MariaDB. No production API behavior changes for this test accommodation.
- `tests/release-runtime.mjs` checks the extracted production ZIP with production vendor, signed WebAuthn, existing invitation/provisioning/quiet cron/HTTP/CLI/export flows and a new request create/approve/replay/conflict/scope-boundary check. Independent QA phases clear only disposable cache budgets.

```sh
# api/
php -d memory_limit=512M vendor/bin/phpunit
vendor/bin/pint --dirty --format agent
# repository root
npm test
npm run build
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/project-requests.browser.mjs
# Same browser suite against a disposable MariaDB (never a real database):
PROVISIONING_MYSQL_PORT=33383 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/project-requests.browser.mjs
```

The existing `api/tests/provisioning.php84.sh` selection also includes ProjectRequestsTest and uses a read-only repository mount in the available local PHP8.4 image. It targets only disposable `projectbrief_m80_qa`. Native SQLite covers destructive empty DDL exercises; MariaDB implicitly committing DDL is not treated as transactional rollback. Temporary database/container is removed after QA.

Full native/canonical test processes use test-only PHP memory_limit512M; runtime/production limits are unchanged. No dependency changes are needed. Canonical builder runs from a clean local source commit, repeats full tests/build, scans production contents and excludes source/tests/reports/screenshots/example credentials. ZIP CRC/internal checksums and SHA-256/byte size are independently verified after packaging.

## Verified pre-release results

- PHP8.4.26 / MariaDB11.8.9 focused selection: **115 tests / 1,203 assertions — PASS**.
- Compiled frontend with real HTTP/SMTP/Chromium: **8 scenario groups / 18 screenshots / 0 JavaScript errors — PASS on SQLite and on MariaDB**. Evidence: `artifacts/milestone-8-1-project-requests/sqlite/` and `mysql/`.
- Changed request/integration/migration/Live Sync selection: **54 tests / 677 assertions — PASS**. The complete canonical suites below remain the release gate.
- Previous client-project regressions: **23 checks / 0 JavaScript errors — PASS**, including no desktop gap, first task and first section through the existing client task form, existing projects and roles. Previous compiled provisioning/Client Entry: **11 scenario groups / 19 temporary screenshots / 0 page errors — PASS**. Summary: `artifacts/milestone-8-1-project-requests/previous-ux-regressions.json`; historical 0.8.0/0.8.1 screenshots were preserved.
- Frontend full Node suite: **362 tests — PASS**.
- Frontend request/i18n/integration selection: **31 tests — PASS**. All PHP integration examples pass syntax checks; the real CLI cURL helper runs in both browser/database scenarios. OpenAPI JSON and every local `$ref` resolve.

Desktop light request list and mobile dark RU details were visually inspected after CSS settled; search controls align and dialog actions wrap on narrow screens. All provided contact/description fields use escaped text, and website links are validated http/https with opener protection.

## Operational limits

Jino PHP/Apache/proxy/NAT, staging backup/restore, actual SMTP/inbox delivery, a live MODX deployment and a real external webhook consumer remain unverified. The CLI integration smoke requires no MODX user; installing an extra/plugin is outside scope. Hosts must follow PRODUCTION.md and preserve additive audit/deduplication data on rollback. Push, merge and deployment are not part of this work.
