# Milestone 8.2 — security and test evidence

Local synthetic QA,2026-10-29, base completed0.8.2 `03932e0`. No live Jino, CMS installation, real external account, production DB or actual inbox was used. No push, merge or deployment. Exact final archive/source metadata is recorded after canonical packaging.

## Security decisions

- Existing stateless bearer authentication/IP/workspace limits; new explicit clients:sso scope and permanent workspace/source required. Existing tokens receive no scope. Owner source editing uses existing sensitive-action assurance, workspace checks and workspace mutation lock. Source must be enabled, have exact HTTPS Origin and explicit project grant. The SSO-only scope does not authorize provisioning/request decisions.
- Active existing project/client membership and verified normalized email checked at issue and consume. Platform owner/developer identities are excluded. First binding stores only SHA-256 of external ID and normalized email; both unique per workspace/source. Rotation preserves identity; changed identity conflicts rather than silently reassigning. Email hashes are identifiers, not anonymization. No business client/project/membership is created.
- Code is32CSPRNG bytes, DB SHA-256 only,60-second expiration. Workspace transaction/row lock and conditional consumed_at update permit exactly one consumer, including two real HTTP workers. Credential/source/scope/grant/member changes are checked again. Raw response is never put in idempotency/history tables, logs or webhook outbox. API retries issue separate unused expiring codes.
- Exact browser POST is the only new CSRF exception. Code + unexpired trusted source + exact Origin compensate. Existing mutation CSRF remains enforced (real419 browser check). The external handler itself requires its existing login and CSRF. No arbitrary redirect field exists; canonical project UUID is loaded server-side. Failure exposes a generic localized message and normal project email link.
- Cross-site POST omits existing Lax cookies. Starting a new session there would silently replace another user's cookie. Entry is stateless and places only a host-only encrypted HttpOnly pending cookie; a same-origin minimal document returns existing cookies before completion. Other active clients/staff/pending MFA sessions refuse handoff and survive. Successful completion regenerates session ID/CSRF, clears pending cookie and303redirects. The code is absent from final DOM, URL, browser storage/history state, logs and webhook payloads. Intermediate POST data may remain browser-managed until navigated away; it is no-store, short-lived and unreusable, and is never a reusable URL/history link.
- External helper's Referrer-Policy:origin preserves Origin (Chromium emits null under no-referrer) while sending no path/secret. PB bridge uses no-referrer. Both relay documents use nonce CSP, no external resources, frame denial and no-store. Cookie behavior was measured through two different HTTPS sites, not inferred from a unit request. Local self-signed CA is verified by actual cURL; ignoreHTTPSErrors is browser QA-only, never production helper.
- SSO sessions use existing access-token/session machinery and configured session lifetime. Each request checks the marker's original source/token/binding/grant/member. No expiry/revocation weakening of ordinary Magic Links. Source configuration changes permanently revoke all its handoffs, including consumed ones, so re-enable cannot resurrect sessions. Missing handoff row fails closed; marker intentionally survives deletion. Destructive migration down also refuses dangling access markers even after bindings/handoffs were deleted. Rollback must revoke marked access tokens before old code; see PRODUCTION.md.
- No dependencies, new cron, registration, JWT/OIDC framework, external provider settings or infrastructure. Examples are server-only; unverified input never becomes email proof. Fixed-profile mock-login exists only in guarded disposable QA and is excluded from release.

## Reproduction

```sh
# api/
php -d memory_limit=512M vendor/bin/phpunit
vendor/bin/pint --dirty --format agent
# repository root
npm test
npm run build
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/client-sso.browser.mjs
# Disposable MariaDB projectbrief_m80_qa ONLY; this harness clears that DB:
PROVISIONING_MYSQL_PORT=33383 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/client-sso.browser.mjs
```

Use the existing disposable MariaDB11.8.9 loopback recipe in milestone-8-0/TESTS.md. Run api/tests/provisioning.php84.sh in the existing PHP8.4 image with a read-only repository mount and only the guarded QA database, after browser testing finishes (feature tests migrate:fresh). It includes SSO, Live Sync, requests, provisioning, invitation/repeat login, read APIs and webhook regression. MariaDB implicit-commit DDL exercises are excluded; SQLite covers empty schema rollback. Test-only memory_limit512M leaves production settings unchanged.

## Coverage and results

ClientSsoTest covers real database issuance/consumption, no business writes, code hash/TTL/replay, strict boolean proof, role/scope/grant/workspace boundaries, unique binding and rotation, revoked/expired tokens, disabled source/project/member, removed grant/scope, staff/other-client sessions, regeneration, exact CSRF exception/Origin, safe fallback, unknown/deleted markers, defaults/rollback guard, payload and rate limits. Existing legacy migration tests assert null marker and unchanged old record fields; all-scopes token count changes only for explicitly requested scopes.

The frontend regression renders fallback in RU/EN/TR/ZH-CN, rejects arbitrary redirect values and retains old Magic Link errors. The actual PHP helper render is checked for fixed POST target, no bearer in HTML, nonce and no-JS fallback. Existing translation parity and complete frontend suites remain release gates.

Real compiled frontend + PHP/cURL HTTPS browser run passes **11 scenario groups,9 screenshots,0 JavaScript errors on SQLite and on MariaDB**. It covers upgrade from0.8.2, direct provision and approved request projects, owner grant controls, one-click external session entry, same-client project switch, parallel redemption on two workers, replay/expiry, actual local SMTP Magic Link fallback, other-client/MFA-owner cookie preservation, scope/tenant/identity conflicts, token replacement/revocation, inactive membership, no-JS buttons and4locale desktop/mobile dark controls plus light client project. This is selected locale/theme/viewport combinations, not a full Cartesian product. Evidence: `artifacts/milestone-8-2-client-sso/{sqlite,mysql}/results.json` and PNGs. Desktop/mobile screenshots were inspected after settling; mobile source settings wrap without horizontal overflow.

Final canonical release from clean source `3117883`: **923 Laravel tests / 13,229 assertions — PASS**, **370 frontend tests — PASS**, production build and branding checks — PASS. Focused SSO: **25 tests / 206 assertions — PASS**. Final PHP8.4.26/MariaDB11.8.9 selection: **147 tests / 1,520 assertions — PASS**. The existing MFA freshness boundary test freezes its clock to remove real-second rollover flakiness; production MFA logic is unchanged. All PHP examples pass syntax checks and all55local OpenAPI references resolve. The shared cURL helper runs against the actual API in both browser database scenarios; real CMS execution is not claimed.

The extracted ZIP passed signed WebAuthn enrollment/replay, existing invitation/provisioning/request flows and the new SSO-only bearer → stateless POST bridge → own client session → canonical303 scenario, code replay refusal, scope boundaries and secret absence in file/stderr logs. Existing Read APIs, quiet webhook cron, exports, CSRF, CLI/first-install and maintenance checks passed. No dependency changes; npm installation/audit reported0vulnerabilities.

Independent ZIP CRC and **all8,860 internal SHA-256 hashes** passed. Archive: `release/project-brief-v0.8.3-3117883.zip`, **39,252,082 bytes**, SHA-256 `c956a7b0990f7066e22d63eb792c804e12b2a51c72bf2ece419eef92ba72b0c1`. It contains8,861files including the manifest,10,106entries including directories. No tests, frontend source, reports, screenshots, example credentials, node_modules or development vendor are shipped. [Exact release manifest](RELEASE-ARTIFACT.json), [SHA256SUMS](SHA256SUMS.txt). Previous release archives remain intact; temporary QA MariaDB containers were removed. Working tree is committed locally; no push, merge or deployment.

## Boundaries

Jino PHP/Apache/proxy headers, session hosting configuration, backup/restore, real TLS/DNS, SMTP inbox delivery and real external account/CMS integration remain deployment smoke. The task provides a local existing-session simulation because web86.site has no existing client account. Source owner settings, credential storage and verified-email lifecycle are trust boundaries; do not enable an unaudited public form as an SSO source.
