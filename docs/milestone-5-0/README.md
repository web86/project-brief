# Milestone 5.0 — Integrations API & outgoing webhooks

Baseline: `projects-manager/main` and local HEAD both `2c0b80f` (includes Milestone
4.9.1; issue originally referenced its ancestor `e6899ba`). Implementation lives
on `codex/milestone-5-0-integrations`. No push or deployment is part of this work.

## Architecture and authorization

`routes/api.php` uses Laravel's stateless API group. Bearer authentication does not
read browser sessions, selected workspace, client links or CSRF cookies. The
middleware checks token syntax, SHA-256 hash, revocation and expiry; it records
last use only for an authenticated request. Scopes are checked explicitly before
resource lookup. Every project query includes the token workspace, including
external identity lookups. Cross-workspace and integer-ID lookups return 404.

Owners manage integrations at `/admin/settings/integrations` using the existing
session/CSRF-protected admin API. An active owner membership in the selected
workspace is required for **every** management action. Admins, clients and guests
have neither the menu item nor management access. Workspace switching clears the
page and one-time secret. Secrets are never persisted in browser storage.

API tokens contain 32 cryptographically random bytes (256 bits), prefixed `pb_`.
Only their hash is stored. The complete secret is returned on creation once.
Tokens can expire or be revoked; scopes cannot be edited, so create a replacement
when rights need to change. Expiry input accepts a future date within two years,
or null for no expiry. UI dates use the displayed date's end in UTC. Up to 100
stored token records and 20 webhook endpoints per workspace are supported.
Webhook secrets contain 32 random bytes, prefixed `whsec_`, and are encrypted with
Laravel's APP_KEY because signing requires the secret. Back up APP_KEY securely.

| Scope            | Allows                                                                            |
| ---------------- | --------------------------------------------------------------------------------- |
| `projects:read`  | Project list and detail, including section metadata                               |
| `projects:write` | Project creation and workspace-scoped external identity resolution                |
| `tasks:read`     | Paginated task list for a project in the token workspace                          |
| `tasks:write`    | Create an idea with the ordinary initial status and audit history                 |
| `estimates:read` | Add `price` and `estimateHours` to task GET responses; also requires `tasks:read` |

`ProjectCreation` and `TaskCreation` are shared by the UI and REST API, including the standard five
sections, optional associated client, task section ordering and audit creation. `ProjectStructure` assigns task sections,
positions and canonical numbers. UI task audit records and API task creation feed
`WebhookEvents`. UI project updates emit only when public project fields change.
Estimate/private-note-only edits emit no webhooks. Repeated approval and no-op
status/content/project changes emit no events. The API does not create magic links.

## REST API v1 contract

All URLs are relative to the application origin. Send `Authorization: Bearer …`.
POST also requires `Content-Type: application/json` and `Idempotency-Key`.
Only the five methods below are implemented; no CRM-specific connectors or
incoming webhook routes are provided. See [OpenAPI](openapi.json) for machine-readable
request and response schemas.

| Method | Path                            | Scope            | Success                                  |
| ------ | ------------------------------- | ---------------- | ---------------------------------------- |
| GET    | `/api/v1/projects`              | `projects:read`  | 200, `{data: Project[], meta}`           |
| GET    | `/api/v1/projects/{uuid}`       | `projects:read`  | 200, `{data: Project}`                   |
| POST   | `/api/v1/projects`              | `projects:write` | 201 new / 200 existing external identity |
| GET    | `/api/v1/projects/{uuid}/tasks` | `tasks:read`     | 200, `{data: Task[], meta}`              |
| POST   | `/api/v1/projects/{uuid}/tasks` | `tasks:write`    | 201, `{data: Task}`                      |

Lists accept `page` (positive integer, default 1) and `perPage` (1–100, default 25).
`meta` contains `page`, `perPage`, `total`, `lastPage`. Projects sort by internal
creation order; tasks sort by section, position and creation order. UUIDs identify
all public resources. `managerUrl` is a relative `/admin/projects/{uuid}/brief`
path and requires the manager's normal login and project access.

Project POST accepts **only** `title` (required nonblank string, ≤160), `website`
(null or HTTP/HTTPS URL, ≤2048), `currency` (`RUB` default / `USD` / `EUR` / `TRY`),
`clientName` (null/string ≤255), `clientEmail` (null/email ≤255), and optional
`sourceSystem` + `externalId` together. `sourceSystem` is 1–64 ASCII letters,
digits, `.`, `_`, `-`; `externalId` is a nonblank string ≤160. The exact trimmed,
case-sensitive pair is uniquely linked to a project within the workspace. A new
key with an existing pair returns the existing project, without changing its
client or title. Different workspaces may use the same pair. No update/sync endpoint
is supplied in this milestone.

Task POST accepts **only** `title` (required nonblank, ≤160), `location` (required
nonblank, ≤2048), `section` (optional nonblank name, ≤2048), `description` (required
nonblank, ≤10000), `priority` (`low`/`normal`/`high`, required), `expectedResult`
(null/string ≤10000). The exact trimmed section name is reused or appended;
without `section`, `location` selects/creates the section. Status starts at `new`,
client approval is false. No attachments, prices, hours or developer notes can be
written. Body size is capped at 32 KiB, including JSON encoding.

Project responses allow only id, title, website, currency, status, managerUrl,
sections (id/uuid/name/position), createdAt and updatedAt. Task responses allow
only id, projectId, sectionId, number, title, location, description, expectedResult,
priority, status, clientApproved, clientApprovedAt, and scope-gated estimates.
Client contact fields, histories, comments, private files and invitation/link
secrets are not included. Task POST never includes estimates.

### Idempotency and limits

Use a stable 1–128 character key containing ASCII letters/digits/`.`/`_`/`:`/`-`.
A key is scoped to workspace, API token and endpoint path. The server takes a
workspace row lock, checks a canonicalized top-level JSON body hash, creates the
record and stores its safe response in the **same** transaction. Object key order
is ignored; normal Laravel trimming/null normalization applies. Same key and body
replay the original body and status with `Idempotency-Replayed: true`. Different
body returns 409. Failed validation/writes do not reserve the key. Keys persist;
no automatic expiry can silently allow an old create to run again. Replacing a
token changes the idempotency namespace; use external project identity across
credential rotations. Task creation has no external identity: retain keys and
reconcile tasks before retrying through a replacement token.

Limits are 120 requests/minute per source IP (including invalid authentication)
and 60/minute per authenticated workspace across its tokens. HTTP 429 includes
`Retry-After`. Browser management is limited separately to 30/minute per session/IP;
test/retry requests also have a 5/minute limit. Responses use `Cache-Control: no-store`.

Errors are JSON `{code, message, errors?}`. Codes: `invalid_token` (401),
`insufficient_scope` (403), `not_found` (404), `method_not_allowed` (405 when route
method matching raises it), `idempotency_conflict` (409), `invalid_content_type`
(415), `payload_too_large` (413), `validation_failed` (422), `rate_limited` (429),
`internal_error` (500). Unknown v1 endpoints return 404. Do not parse human messages.
The management API keeps the existing application's error envelope and CSRF rules.

### curl examples

Set `BRIEF_URL` to your deployment and read the one-time token privately; do not
paste real secrets into source files, shared scripts, issue comments or logs.

```sh
BRIEF_URL=https://brief.example.com
# Set PB_TOKEN privately in your shell/session.
curl --fail-with-body -H "Authorization: Bearer $PB_TOKEN" \
  "$BRIEF_URL/api/v1/projects?perPage=25&page=1"

curl --fail-with-body -X POST "$BRIEF_URL/api/v1/projects" \
  -H "Authorization: Bearer $PB_TOKEN" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: crm-deal-812-project-v1' \
  --data '{"title":"Store redesign","currency":"EUR","clientName":"Anna","clientEmail":"anna@example.com","sourceSystem":"crm","externalId":"deal-812"}'

# PROJECT_UUID is returned in data.id; manager URL is data.managerUrl.
curl --fail-with-body -X POST "$BRIEF_URL/api/v1/projects/$PROJECT_UUID/tasks" \
  -H "Authorization: Bearer $PB_TOKEN" -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: crm-deal-812-idea-1-v1' \
  --data '{"title":"Larger photos","location":"Product page","description":"Let customers see the product clearly","priority":"normal","expectedResult":"Product details are readable"}'

curl --fail-with-body -H "Authorization: Bearer $PB_TOKEN" \
  "$BRIEF_URL/api/v1/projects/$PROJECT_UUID/tasks?perPage=100"
```

## Outbox, delivery and event contract

Outbox rows and initial delivery rows are written in the same database transaction
as the business mutation. They become visible to the cron connection only after
commit; rollback removes both. No HTTP request is made in a user request or an
after-commit callback. An endpoint receives events only while enabled and subscribed
at event creation time. Creating/enabling an endpoint does not replay old changes.

`php artisan integrations:webhooks --limit=20 --seconds=45` processes persisted
outbox deliveries. Defaults: at most 20 attempts and 45 seconds between attempts;
absolute caps: 50 attempts/45 seconds. A request already started may finish after
the time budget. DNS uses the host resolver and its system timeout; configure a
reliable bounded resolver on the host. Connect timeout is 3 s; total HTTP timeout
8 s; response body is limited to 16 KiB. Ordinary payload fields bound event JSON
below 128 KiB. No response body or third-party exception text is stored.

A database lease serializes cron runs across processes. Each attempt locks and
renews that lease and locks the endpoint row through DNS/signing/HTTP/result storage,
so URL/secret changes are serialized against an in-flight delivery. A crashed
transaction rolls back its claim; the lease expires after 90 s. An endpoint update
may briefly wait for the current attempt. Host DNS timeout can extend that wait.

2xx succeeds. 429, 5xx and transport/timeout errors retry at 30, 60, 120, 240, 480 s
(up to six automatic attempts; capped exponential formula at 3600 s). Other statuses,
including redirects and permanent 4xx, terminate immediately. Unsafe DNS destinations
terminate immediately. States: `pending`, `processing` (inside an attempt transaction),
`succeeded`, `failed`, `cancelled`. Each attempt logs only status, HTTP code, safe
error code, duration and timestamp. Manual retry starts a fresh six-attempt budget
for a failed delivery with the same event/delivery IDs and immutable raw body;
previous attempt records remain. A successful, pending or cancelled delivery cannot
be retried through the UI/API.

Delivery is **at least once**: a receiver can accept a request immediately before
the sender crashes and records success. Receivers must deduplicate by `id` and
acknowledge duplicates with 2xx. Event ordering across retries is not guaranteed.
Changing URL/events, disabling, or rotating a secret increments the endpoint version
and cancels queued deliveries; previously failed deliveries cannot be revived under
a different version. Deleting an endpoint cascades its delivery/attempt history.
Already accepted remote side effects cannot be recalled. No automatic log cleanup
is provided; monitor storage and archive terminal logs under your retention policy.
Idempotency records must be retained if replay guarantees are needed.

Envelope (`version: 1`):

```json
{
  "version": 1,
  "id": "event-uuid",
  "type": "task.approved",
  "occurredAt": "2026-10-26T09:00:00.000000Z",
  "workspaceId": "workspace-uuid",
  "projectId": "project-uuid",
  "taskId": "task-uuid",
  "actor": { "type": "client", "id": "client-uuid" },
  "changes": { "clientApproved": true, "clientApprovedAt": "2026-10-26T09:00:00.000000Z" },
  "origin": "ui"
}
```

`actor.type`: `admin`, `client`, `integration`, or `system`; id may be null.
`origin`: `ui`, `api`, or `test`. Times are UTC. Test deliveries use `webhook.test`,
null projectId/taskId, empty changes object, and the actual workspace UUID.

| Event                                | `changes` allowlist                                                           |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| `project.created`, `project.updated` | Changed title, website_url, currency, status (create includes initial values) |
| `task.created`, `task.updated`       | Public metadata snapshot: title, location, priority, sectionId                |
| `task.updated` on section move       | number: `{from, to}`                                                          |
| `task.status_changed`                | status: `{from, to}`                                                          |
| `task.approved`                      | clientApproved: true, clientApprovedAt                                        |

`task.approved` is client agreement, independent of technical status `approved`.
The webhook body never contains full task descriptions/results, prices, hours, developer notes, client contact details,
attachments/private paths, magic-link tokens or invitation secrets. For full task details or permitted
estimates, perform a task GET using both `tasks:read` and `estimates:read`.

### Signature verification

Headers:

- `X-ProjectBrief-Event`: event type.
- `X-ProjectBrief-Delivery`: stable delivery UUID.
- `X-ProjectBrief-Timestamp`: Unix seconds for this attempt (changes on retry).
- `X-ProjectBrief-Signature`: `sha256=` + lowercase hexadecimal HMAC-SHA256.

Compute HMAC over the **unchanged** bytes `timestamp + "." + raw_body`, using the
complete `whsec_…` string as the HMAC key. Verify in constant time before parsing
JSON. Reject timestamps outside a short window (e.g. 300 s), then deduplicate event
ID transactionally and return a quick 2xx. Retries get a fresh timestamp/signature.
See [verify-webhook.php](verify-webhook.php) for an executable CLI/server example:

```sh
# Save raw HTTP bytes as body.json; WH_SECRET is set privately.
PB_TIMESTAMP=1793005200 PB_SIGNATURE=sha256=... \
  php docs/milestone-5-0/verify-webhook.php < body.json
```

The sample verifies authenticity/freshness; your receiver must persist event IDs and
implement its own transactional side effects. There is no real CRM E2E claim.

### SSRF controls

Only HTTPS URLs on port 443, without credentials or fragments, are accepted.
Hosts are ASCII DNS names or IPv4 literals. Public IPv6 destinations are supported
through DNS AAAA records; bracketed literal URLs are intentionally rejected.
All A/AAAA answers must pass the public-IP allowlist; loopback/private/link-local,
metadata, multicast, reserved/documentation, CGNAT, mapped IPv4 and special IPv6
ranges are blocked. DNS is checked at setup and again on every attempt. The chosen
validated address is pinned with cURL `CURLOPT_RESOLVE`, retaining hostname TLS
verification. Proxies are disabled and redirects are never followed. A rebind to a
private address fails without HTTP. A non-public address mixed with a public DNS
answer also fails. cURL is required (already in deployment prerequisites).

## n8n → CRM recipe

1. In the workspace owner's integrations page, create a minimally scoped token:
   `projects:write` for CRM project creation, `tasks:read` for task details, and
   `estimates:read` only if the studio explicitly wants costs/hours in the CRM.
   Add `tasks:write` only if CRM automation must create ideas.
2. Store the token in an n8n credential. Trigger the workflow when a deal reaches
   “Prepare specification”. Send project POST with a deterministic key based on
   deal ID and operation revision. Use `sourceSystem` and `externalId` for project
   reconciliation across token changes. Persist returned UUID/managerUrl in the deal.
3. Create an HTTPS n8n receiver and subscribe to `task.approved` (and any needed
   additional events). Save the one-time signing secret in a private credential.
   Receiver must have raw request bytes; verify signature/time **before** parsing.
4. Store event IDs uniquely, acknowledge duplicates, and return 2xx quickly. Schedule
   CRM work separately so slow CRM calls do not cause sender timeouts. Match workspace
   and project UUID to the stored CRM record. Follow paginated task GET to find taskId.
5. Update the deal with client agreement and permitted task information. Retry 429
   according to `Retry-After`; fix permanent 4xx/scopes before retrying. An outbound
   test queues a `webhook.test` event; it needs the cron processor to actually send.
6. Rotate credentials deliberately and update both sides. This milestone supplies
   the foundation; no Bitrix24/amoCRM connector, OAuth marketplace or two-way sync
   is implemented or externally verified.

## Owner management endpoints

All below use the existing session cookie, CSRF header for mutations, and selected
workspace owner authorization. Root: `/api/admin/integrations`.

| Method/path suffix                              | Input / result                                                               |
| ----------------------------------------------- | ---------------------------------------------------------------------------- |
| GET `/`                                         | scopes, events, safe token and webhook lists                                 |
| POST `/tokens`                                  | name, nonempty scopes[], optional expiresAt; returns id and one-time secret  |
| DELETE `/tokens/{uuid}`                         | revoke; `{ok:true}`                                                          |
| POST `/webhooks`                                | name, url, nonempty events[], optional enabled; returns id + one-time secret |
| PATCH `/webhooks/{uuid}`                        | any of name/url/events/enabled; autosave enabled or explicit edit form save  |
| DELETE `/webhooks/{uuid}`                       | delete and cascade delivery/attempt history                                  |
| POST `/webhooks/{uuid}/rotate`                  | one-time replacement secret                                                  |
| POST `/webhooks/{uuid}/test`                    | queue test, 202 `{eventId,queued:true}`                                      |
| GET `/webhooks/{uuid}/deliveries?page=1`        | 25 deliveries/page with safe attempt history and canRetry                    |
| POST `/webhooks/{uuid}/deliveries/{uuid}/retry` | failed, enabled, current-version only; 202 `{queued:true}`                   |

Names are required nonblank strings up to 100 characters. Event and scope arrays
must be unique, nonempty and drawn from their respective catalogs. Secrets, secret
hashes, encrypted secrets and raw third-party responses are excluded from lists/history.
