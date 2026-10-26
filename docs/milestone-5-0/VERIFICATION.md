# Milestone 5.0 — local verification

Date: 26 October 2026 (Europe/Istanbul). Baseline `projects-manager/main` and local
HEAD were both `2c0b80f`; `e6899ba` from issue #1 is an ancestor. Work is isolated
on `codex/milestone-5-0-integrations`. No push, deployment or live CRM delivery.

## Automated checks

- Full Laravel suite: **630/630**, **6551 assertions**. The final release builder
  repeats the full suite before packaging.
- New integration/backend coverage: **25 tests**, **325 assertions** after final
  transport assertions. Scopes, missing/invalid/revoked/expired tokens, owner/admin/
  client/guest, two-workspace IDOR, session/CSRF independence, private field filtering,
  explicit estimate scopes, pagination, strict inputs and body/content limits.
- Creation checks: safe manager URL, default sections and associated client,
  canonical task numbering/audit history, idempotency replay/conflict and external
  project identity reuse within one workspace and separation across two workspaces.
- Webhook checks: transaction rollback, no-op suppression, UI/API events, technical
  status distinct from client agreement, immutable bytes and HMAC, stable event and
  delivery IDs over retries, 429/500/timeout → bounded retries, 2xx, permanent 4xx,
  redirects, failed manual retry, selection/disable/rotation/URL cancellation,
  encrypted one-time signing secrets, safe history, lease exclusion and cron limits.
- Destination checks cover private/link-local/metadata/reserved/mapped IPv4 and IPv6,
  mixed DNS answers, rebinding at delivery time, cURL IP pinning, disabled proxies,
  explicit TLS verification, no redirects, no response decompression and response
  size cutoff. All feature-test HTTP requests use fakes; no real receivers are called.
- Migration down/up test preserves existing project data; all tests/browser fixtures
  use disposable SQLite. Production/Jino MariaDB rollout remains an operator check.
- Full frontend suite: **238 tests**, including owner sidebar/direct-route guards,
  workspace role changes, and executing the documented PHP HMAC verifier against
  valid raw bytes, tampered bytes and stale timestamps.
- Production Vite build, Prettier check, Laravel Pint and branding validation pass.
- Existing appearance regression suite: **11/11** groups passed (including system theme, OS preference changes, manual override, RU/EN at 320/360/390px, light/dark desktop/mobile and production startup). Output stays under ignored
  `artifacts/milestone-5-0/appearance/` so previous milestone evidence is preserved.

## Browser smoke

`tests/integrations.browser.mjs` boots local loopback Laravel/Vite servers with a
new disposable SQLite database and synthetic identities. It verifies:

1. Owner navigation; token scopes/expiry creation, one-time secret display, copy to
   clipboard, close removes secret from DOM, no localStorage persistence, actual
   bearer GET, revoke followed by HTTP 401.
2. Webhook creation, one-time signing secret, queued test delivery, history refresh,
   failed delivery manual retry and queued status. Network delivery behavior is
   covered separately with backend HTTP fakes. Browser setup performs public DNS
   validation only; it does not POST to a remote receiver.
3. RU/EN, light/dark, 1440px desktop and 320px mobile, no horizontal overflow, mobile
   drawer integration link and navigation.
4. Switching the owner into a workspace where they are admin removes integration
   visibility and direct access. Independent admin/client/guest contexts cannot
   access management. Client access uses a real synthetic access-link session.
5. No browser runtime errors. Full result: [browser-results.json](browser-results.json).

Screenshots contain synthetic non-secret data; one-time dialogs are closed before capture.

| View                                 | Screenshot                                                       |
| ------------------------------------ | ---------------------------------------------------------------- |
| Desktop tokens / RU / light          | [desktop-tokens-ru-light.png](desktop-tokens-ru-light.png)       |
| Desktop webhooks / EN / dark         | [desktop-webhooks-en-dark.png](desktop-webhooks-en-dark.png)     |
| Desktop delivery history / EN / dark | [desktop-deliveries-en-dark.png](desktop-deliveries-en-dark.png) |
| Mobile webhooks / 320px / EN / dark  | [mobile-webhooks-en-dark.png](mobile-webhooks-en-dark.png)       |
| Mobile drawer / EN / dark            | [mobile-navigation-en-dark.png](mobile-navigation-en-dark.png)   |
| Mobile tokens / 320px / RU / light   | [mobile-tokens-ru-light.png](mobile-tokens-ru-light.png)         |

## Reproduction

```sh
npm test
npm run build
npm run format:check
npm run brand:check
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
cd ..
PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/integrations.browser.mjs
APPEARANCE_OUTPUT_DIR=../artifacts/milestone-5-0/appearance/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs npm run test:appearance
./bin/build-release
```

Release runtime validation uses only the packaged production vendor (no development
factories), the split private/public layout, disposable SQLite and synthetic credentials.
It additionally exercises packaged v1 bearer authentication without session cookies,
project/task create, idempotent replay/conflict, tenant IDOR, section numbering,
private/estimate filtering, the cron command and included OpenAPI document. The
builder scans for local secrets and forbidden files, checks platform requirements,
prunes vendor development fixtures, validates runtime, records checksums and makes
an atomic ZIP. Exact archive path, packaged commit and SHA-256 are recorded in
`RELEASE-ARTIFACT.json` after the clean source commit is packaged.

## Known limits

- No real Jino cron, MariaDB deployment, receiver TLS/DNS reachability, n8n, Bitrix24
  or amoCRM E2E was performed. These are explicit production smoke steps.
- Delivery is at least once and retries may arrive out of order; receivers must
  persist unique event IDs and reconcile through scoped GET. Remote side effects
  cannot be rolled back. There is no automatic retention/archival in MVP.
- Host DNS resolver timeout can extend a running attempt past the command's soft
  time budget. HTTP connect/total timeouts are 3/8 s; database lease/endpoint locks
  serialize attempts and endpoint changes. A receiver must answer promptly.
- Only public HTTPS on port 443; no redirect/proxy/private receiver support. IPv6
  literals are rejected; public AAAA records are supported and pinned.
- Token expiry is optional; maximum chosen expiry is under two years. Stored token
  records cap at 100/workspace; endpoints cap at 20. Idempotency records persist and
  are namespaced per token; external project identity survives token rotation.
- No update endpoint, task external identity, attachments, CRM-specific connector,
  marketplace OAuth or two-way synchronization is included.
