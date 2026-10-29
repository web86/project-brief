# Project Requests API v1

Base origin: `https://brief.web86.site`. All requests use the existing stateless v1 API, `Authorization: Bearer pb_…`, JSON, `Cache-Control: no-store`, safe error envelope, 120 requests/minute per IP and 60/minute per workspace. There is no browser-session or cookie authorization for v1.

## Explicit scopes and stable sources

| Scope              | Grants                                                                                                                                                                                                               |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `requests:create`  | Create requests; creation/replay returns only request UUID, status and createdAt, never internal decisions                                                                                                           |
| `requests:read`    | List/details, contact and request data, internal decision and approved result, within the token workspace                                                                                                            |
| `requests:approve` | Approve **or reject** requests. Approval includes project provisioning as its consequence. This scope does **not** grant direct `/api/v1/projects/provision` or `/api/v1/projects` creation, nor list/detail reading |

Tokens retain exactly their issued scopes. Existing tokens are neither upgraded nor attached to a source automatically. A new owner-issued token with `requests:create` must select or create a persistent integration source in Integrations. New scopes and source are displayed in the token form; a replacement token selects **the same source UUID**. Source names are labels; do not create a new source merely to rotate credentials. Read/decision tokens may act on requests from every source in their own workspace and do not need create scope.

Admin token creation extends its existing input with optional `sourceId` (existing source UUID) or `sourceName` (new source label), mutually exclusive. Its response and the one-time secret behavior remain unchanged. The Integrations response adds `sources: [{id,name}]` and token `sourceId`. A source is permanent audit/deduplication identity; this release has no source deletion/rename flow. Source limits: 100/workspace; token and webhook limits stay unchanged.

## Endpoints

| Method | Path                                      | Scope              |
| ------ | ----------------------------------------- | ------------------ |
| POST   | `/api/v1/project-requests`                | `requests:create`  |
| GET    | `/api/v1/project-requests`                | `requests:read`    |
| GET    | `/api/v1/project-requests/{uuid}`         | `requests:read`    |
| POST   | `/api/v1/project-requests/{uuid}/approve` | `requests:approve` |
| POST   | `/api/v1/project-requests/{uuid}/reject`  | `requests:approve` |

All POST endpoints require `Idempotency-Key` (1–128 ASCII characters `[A-Za-z0-9._:-]`). Keys are scoped to token/path as in v1. Reuse the exact normalized body and key after a timeout. Key reuse with another body is 409. Requests also have a permanent unique `(workspace, integration source, SHA-256(externalId))` binding, independent of token/key. A new key or replacement token of the same source recovers the original request; changed content is 409 and never updates it. New requests after rejection use a new externalId. Treat external IDs as opaque identifiers without personal data because they appear in webhook payloads.

### Create

```json
{
  "externalId": "web86-request-105",
  "client": {
    "name": "Иван Петров",
    "email": "ivan@example.com",
    "company": "Example Ltd",
    "website": "https://example.com"
  },
  "project": {
    "name": "Разработка корпоративного сайта",
    "description": "Нужен современный сайт с каталогом услуг",
    "language": "ru"
  }
}
```

201 for first creation (or replay preserving original status code); 200 for recovery by source/externalId. Response:

```json
{
  "data": {
    "id": "request-uuid",
    "requestId": "request-uuid",
    "status": "pending",
    "createdAt": "2026-10-29T12:00:00.000000Z"
  }
}
```

Replay returns the current status but never the internal decision or contact data through create-only scope. No project, workspace contact, membership or access token is created at submission. Owner notification and `project_request.created` happen once, only for first creation.

32 KiB JSON body limit; no unknown top/nested fields. Limits: externalId 160 chars, client name/email/company 255, http/https website 2048, project name 160, description 10000. Names/IDs cannot be whitespace-only. Trim name/project/externalId; normalize email with the same existing lowercase/trim routine. Optional company/website/description may be null. Language is `ru`, `en`, `tr`, `zh-CN`. External input does not verify mailbox ownership.

### List and detail

Query: `page` >=1 (default1), `perPage` 1–100 (default25), `status=pending|approved|rejected`, `search` <=255 chars (literal substring across name/company/email/project name), `sort=asc|desc` by createdAt then ID (defaultdesc). Empty search is allowed. `%`, `_`, `!` are literal search characters. Status is optional for API consumers.

List: `{"data":[…],"meta":{"page":1,"lastPage":1,"total":1,"pending":1}}`. Detail: `{"data":{…}}`. Resource keys:

```json
{
  "id": "request-uuid",
  "requestId": "request-uuid",
  "externalId": "web86-request-105",
  "source": { "id": "source-uuid", "name": "web86.site" },
  "client": {
    "name": "Иван Петров",
    "email": "ivan@example.com",
    "company": "Example Ltd",
    "website": "https://example.com"
  },
  "project": { "name": "Разработка сайта", "description": "Пожелания", "language": "ru" },
  "status": "pending",
  "createdAt": "2026-10-29T12:00:00.000000Z",
  "updatedAt": "2026-10-29T12:00:00.000000Z",
  "reviewedAt": null,
  "decision": null,
  "result": null,
  "projectId": null,
  "clientId": null,
  "projectUrl": null,
  "invitation": null
}
```

All operations resolve UUID inside the checked token workspace; foreign records are 404. Internal decision reasons and actor names are restricted information: use read/decision credentials only in trusted server code, never a public form.

### Approve

`POST …/{uuid}/approve`, body `{"sendInvitation":true}`. Omission defaults to true. Returns 200 with the full resource and final `approved` status. `reviewedAt` and immutable `decision.actor:{type,id,name}` record the original deciding owner/token. The token may be revoked/replaced later without losing the decision. `decision.sendInvitation` records its choice. Result:

```json
{
  "operationId": "provisioning-uuid",
  "projectId": "project-uuid",
  "clientId": "workspace-contact-uuid",
  "projectClientId": "membership-uuid",
  "projectUrl": "https://brief.web86.site/project/project-uuid",
  "invitation": "sent"
}
```

The same values also appear in top-level resource fields `projectId`, `clientId`, `projectUrl`, `invitation`. `clientId` is the existing workspace contact identity; `projectClientId` is the new project's membership. Existing contacts are matched within the workspace only; this does not grant existing or unrelated project access.

Decision, contact, project, membership, provisioning record and request webhook outbox commit atomically under the existing workspace lock. API authorization is rechecked inside that transaction; UI owner membership is likewise rechecked under the lock. Simultaneous UI/API approvals return the same result. Approve/reject races have one winner and one 409. A failed business write leaves the request pending and no partial project/contact/access/outbox. Repeating the same final choice recovers its result without creating another project; changing the invitation choice is 409.

Invitation delivery starts after the decision transaction commits, using existing statuses `pending`, `sending`, `sent`, `failed`, `not_requested`. SMTP failure does not undo approval. Replays may recover `pending`; uncertain `sending` and `failed` are not automatically resent. The owner may use the existing project Clients invitation action for an explicit resend; the request reads its current provisioning invitation status. Invitations last 7 days, public repeat login 30 minutes; `/access/…` and `/project/…` behavior is unchanged. No Magic Link appears in API responses, operation history or webhook payloads.

Approved result IDs/URL remain audit data if a project is later trashed/deleted. Normal project access/lifecycle checks still apply. Reapproval never resurrects or recreates that project.

### Reject

`POST …/{uuid}/reject`, body `{}` or `{"reason":"Внутренняя причина"}` (trimmed, max2000 chars, optional null). Returns 200 with status `rejected`, reviewedAt and original decision actor/reason; all result fields remain null. No project or access. Repeating the same reason is harmless; a changed final reason or later approval is 409. There is no reopen/edit status.

### Errors and operation history

Existing `{code,message,errors?}` envelope: 401 invalid/revoked/expired bearer; 403 insufficient scope; 404 absent/foreign record; 409 final-decision/body/external-ID conflict (`idempotency_conflict`); 413 oversized body (decision limit4 KiB); 415 JSON required; 422 invalid input/key/source; 429 rate limit with Retry-After; 500 internal error. After network/500 uncertainty retry the same operation; do not assume failure means no commit.

Integration operation history retains its existing fields and adds `operationType` (`project.provision`, `request.create`, `request.approve`, `request.reject`) and `requestId`; no contact, reason or body is logged. Existing entries default to direct provisioning. Retention remains the latest500 per workspace. Existing requests/contracts and token provisioning switches are unchanged.

## Webhooks

Subscribe explicitly to `project_request.created`, `project_request.approved`, `project_request.rejected`. Existing endpoints gain no subscriptions automatically. The v1 envelope, outbox, delivery IDs, HMAC over `timestamp.rawBody`, retry/backoff and `integrations:webhooks` cron are unchanged.

```json
{
  "version": 1,
  "id": "event-uuid",
  "type": "project_request.approved",
  "occurredAt": "2026-10-29T12:00:00.000000Z",
  "workspaceId": "workspace-uuid",
  "projectId": "project-uuid",
  "taskId": null,
  "requestId": "request-uuid",
  "status": "approved",
  "sourceId": "source-uuid",
  "externalId": "web86-request-105",
  "projectUrl": "https://brief.web86.site/project/project-uuid",
  "actor": { "type": "admin", "id": "owner-uuid" },
  "changes": {},
  "origin": "ui"
}
```

Before approval/rejection projectId/projectUrl are null. Payloads exclude names, email, company/site, title/description, rejection reason, tokens and Magic Links. `sourceId` is stable across token changes. Request events are workspace events: only subscriptions with `projectFilter:"all"` receive them. `selected` project subscriptions exclude all request events, including approval, consistently at enqueue/delivery/retry. Other event/filter semantics remain unchanged. Approval emits its dedicated request event rather than a duplicate generic `project.created`; invitation status events use the existing mechanism. A business replay produces no new business event.

The existing [v1 APIs](../milestone-5-0/openapi.json) and [direct provisioning extension](../milestone-8-0/openapi.json) remain valid. The supplied OpenAPI file includes both provisioning and request paths. Client and owner UI endpoints remain session/CSRF protected; they are not a public substitute for v1 authentication.
