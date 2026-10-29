# Minimal Client SSO — Trusted Handoff (0.8.3)

Built on completed0.8.2 (`03932e0`) in `codex/milestone-8-2-client-sso`. [Russian guide](README.md), [OpenAPI](openapi.json), [security/test evidence](TESTS.md), [Jino deployment/rollback](PRODUCTION.md), [release manifest/SHA-256](RELEASE-ARTIFACT.json).

Correction branch `codex/fix-sso-source-projects`, based on completed0.8.3 (`e816abd`), reuses existing provenance with no new migration/table. Linked release metadata describes the original0.8.3 ZIP, not this correction.

An already authenticated external client clicks Open project and reaches an existing authorized Project Brief project. No project, account or membership is created. Invitations, Magic Links and staff authentication remain available. No OAuth/OIDC, provider framework, extra infrastructure or registration is introduced.

## Configure

In Integrations, issue a separate `clients:sso` token for a persistent integration source. Configure that source's exact HTTPS account origin and enable SSO once, even before any projects exist. Projects provisioned by that source or approved from its requests are allowed automatically. Manual Additional projects remains available for existing projects without reliable provenance; an empty manual list is valid. Defaults are off; existing tokens gain no scope. Rotate credentials against the same source. Provision-only token creation exposes the same source selector; it remains optional for provisioning without SSO. Store the bearer, helper and saved project UUIDs in existing owned order/account records outside the document root. Use the result of provisioning/request approval, never a browser-supplied project UUID.

The external handler must verify its existing login and CSRF and load the immutable ID and verified email from its own database. A logged-in MODX/WordPress account alone does not prove email ownership. Clear verification on email changes; protect proof fields from all public profile/REST updates. Never accept emailVerified, email or user/project IDs from submitted form data. An order_id may select a record only with an explicit current-user ownership predicate.

POST `/api/v1/client-sso/handoffs`, bearer authorization, JSON body:

```json
{
  "projectId": "<saved UUID>",
  "externalUserId": "site-user:42",
  "email": "verified@example.com",
  "emailVerified": true
}
```

201 returns `data.code` (256 random bits,64hex), `data.postUrl` (fixed PB `/client-sso/enter`) and `data.expiresAt` (60seconds). JSON max2048bytes; external ID max160characters, email255. emailVerified must be boolean true. Responses are no-store; exclude both request/response bodies from proxy/APM/application logs. No idempotency key: each retry issues an independent short-lived code without business writes. Never persist the response in an idempotency cache. Existing API IP/workspace budgets apply; browser entry additionally has60requests/min/IP.

401 invalid/revoked/expired token;403 insufficient scope, disabled source, missing project grant or invalid client;404 project outside this workspace;409 identity conflict;413/415/422 invalid payload;429 rate limit. See OpenAPI for the exact contract. No handoff/code is emitted to webhooks or operation history.

First issuance pins source + external ID + normalized email (hashes only). A changed email or competing external ID conflicts; rotation preserves the binding. No automatic rebinding. Clients must already be active members of the active selected project. Platform staff identities are refused. Other client/staff/MFA sessions are preserved and refuse silent identity changes; use normal logout or a separate browser context. The same client may switch source-linked or manually permitted projects within its workspace.

## Browser protocol

Use [the cURL helper](examples/ProjectBriefClientSso.php), which verifies TLS and renders a nonce-CSP, no-store auto-POST form without third-party resources. Code is only in POST body, never URL/referrer/storage. External `Referrer-Policy: origin` preserves the exact Origin header while exposing no path or secret. `no-referrer` on this external form makes Chromium send Origin:null and is refused. Exclude body capture from analytics/APM. Do not replace the fixed POST target with user input.

The stateless PB POST validates code and exact source Origin and sets only an encrypted HttpOnly host-only one-minute cookie. It does not start/replace the PB session. A minimal same-origin bridge restores existing SameSite cookies before identity checks. Completion consumes the code atomically, regenerates session ID/CSRF and clears the pending cookie, then303redirects to the server-owned project URL. This prevents silent account switching when cross-site POST omits a Lax/Strict session cookie. PB bridge uses no-referrer. With JS enabled this is one click without a visible extra step; without JS, Continue buttons complete the protocol, although the Vue application itself requires JS.

Disabling/changing the source permanently revokes its handoffs and SSO sessions. Revoked/expired tokens, removed scope/grant, inactive clients/projects and changed email also deny access. Missing handoff rows fail closed. Ordinary Magic Links are independent. Session expiration continues to use configured PB session lifetime; no staff assurance is bypassed.

## Provenance and order flow

Automatic access reuses existing project_provisionings → original integration token → permanent source. Approved requests use their own integration_source_id and project_provisioning_id; the approving credential does not reassign their source. Creator token revocation/rotation retains provenance. The active SSO credential is checked independently. No source inference from email and no duplicated grant records/backfill are needed. Do not physically delete provenance or reassign credentials; rotate by revoking and issuing against the same source.

The updated examples select each owned order in the existing database, read pb_project_id, and support multiple projects per user. There is no config project array. Map illustrative fields/table/model to the existing order repository. If no project exists, [order-project.php](examples/order-project.php) uses the [existing provisioning helper](../milestone-8-0/provision-project.php), then persists the UUID and commits before SSO. Use transactional storage and lock the owned order. The immutable provision_input snapshot comes from the order, never browser JSON. A pending/rejected request cannot fall through into direct provisioning: persist its approved project ID from API/webhook instead.

A separate projects:provision credential may be used, but select the same source as the SSO credential. Same externalId/body recovers the existing project across source-token rotation; changed body conflicts. Source-less tokens retain existing per-token deduplication. Preserve the exact order snapshot/key for uncertain network/save retries. The SSO helper supports PHP7.4 syntax; first-open provisioning uses the existing PHP8+ helper (tested on PHP8.4). Local CA override is optional and keeps TLS verification enabled.

## Integration examples and local reproduction

Examples require PHP7.4+ syntax support, cURL and a valid CA trust store; the application runtime targets PHP8.4. See [private config](examples/config.example.php), [plain PHP](examples/plain-php.php), [MODX2/3](examples/modx-snippet.php), [WordPress](examples/wordpress.php). Integrate before any output in the existing authenticated account flow. MODX snippet is uncached and uses its web-context login; protected profile extended `pb_verified_email`/`pb_verified_at` must come from the site's existing mail proof. WordPress uses authenticated admin_post plus nonce and protected verification meta. These are small server examples, not CMS plugins or a new registration system.

web86.site has no ready client account. The local browser test simulates an existing authenticated user with fixed server data, two HTTPS origins, real PHP/cURL, disposable SQLite/MariaDB and local SMTP. The mock route is guarded QA-only code and excluded from production. Native CMS installations, real external accounts, Jino and actual inbox delivery were not exercised. See TESTS.md for evidence and reproduction.
