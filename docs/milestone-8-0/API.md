# Provisioning API v1

## Authorization

Create an API token in Integrations with `projects:provision`. This explicitly permits contact and project creation in **that token's workspace** and initially enables its automatic-creation switch. `projects:write` alone does not authorize provisioning. The owner can disable/re-enable the switch or revoke the token using the existing settings UI; disabled tokens cannot replay provisioning requests. No browser session, workspace ID, client ID, role or permissions supplied by a caller can select another workspace.

`POST /api/v1/projects/provision` requires JSON (maximum 32 KiB), bearer authorization and `Idempotency-Key` (1–128 characters: letters, digits, `. _ : -`). Existing v1 IP/workspace limits apply: 120/minute per IP, 60/minute per workspace. Responses have `Cache-Control: no-store`; no session cookie is issued by the stateless API.

```sh
curl --request POST 'https://brief.example.test/api/v1/projects/provision' \
  --header "Authorization: Bearer $PROJECT_BRIEF_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Accept: application/json' \
  --header 'Idempotency-Key: studio-order-1042' \
  --data '{
    "externalId": "order-1042",
    "client": {
      "name": "Ada Client",
      "email": "ada@example.test",
      "company": "Ada Studio",
      "website": "https://example.test"
    },
    "project": {
      "name": "Studio website",
      "description": "Accessible multilingual website",
      "language": "en"
    },
    "sendInvitation": true
  }'
```

Keep tokens on the server and out of command histories, frontend bundles, browser storage and URL parameters. The shell example assumes a privately supplied environment variable. The [PHP function](provision-project.php) supports a WordPress/MODX server hook; CMS plugins are outside this release.

| Field                 | Rules                                                                               |
| --------------------- | ----------------------------------------------------------------------------------- |
| `externalId`          | Required, nonblank string, max 160; trimmed; unique per integration token/workspace |
| `client.name`         | Required, nonblank string, max 255                                                  |
| `client.email`        | Required valid email, max 255; normalized with existing email service               |
| `client.company`      | Optional/null string, max 255                                                       |
| `client.website`      | Optional/null HTTP(S) URL, max 2048                                                 |
| `project.name`        | Required nonblank string, max 160                                                   |
| `project.description` | Optional/null string, max 10000                                                     |
| `project.language`    | Required: `ru`, `en`, `tr`, `zh-CN`; sets the new membership's invitation locale    |
| `sendInvitation`      | Required boolean                                                                    |
| `templateId`          | Reserved; nonempty values rejected until templates are implemented                  |

Unknown top-level and nested fields are rejected. Existing contact metadata is retained when email matches; provisioning does not update that contact. This endpoint creates an empty project using existing defaults, without templates, tasks or sections.

## Response and recovery

First creation: **201**.

```json
{
  "data": {
    "operationId": "f61b9285-d468-4ade-87bc-e5d3633ddfc9",
    "projectId": "019a0000-0000-7000-8000-000000000001",
    "clientId": "019a0000-0000-7000-8000-000000000002",
    "projectClientId": "019a0000-0000-7000-8000-000000000003",
    "projectUrl": "https://brief.example.test/project/019a0000-0000-7000-8000-000000000001",
    "invitation": "sent",
    "created": true
  }
}
```

`clientId` is the workspace contact, `projectClientId` is its independent project membership. `projectUrl` is a permanent address, not a login credential. It opens after authentication and authorization. UUID examples above are illustrative.

- Same key and normalized data: stored result and original HTTP status with `Idempotency-Replayed: true`; `created` preserves the original result. Invitation state is refreshed and can differ from the initial response.
- New key with the same `externalId` and data: **200**, same identifiers/link, `created: false`. This recovers a lost response without requiring `projects:read`.
- Changed data with the same key or external ID: **409**, `code: idempotency_conflict`; no mutation or resend.
- Concurrent requests are serialized and constrained by database uniqueness. A process interruption cannot create a second project on replay. Existing deleted resources are not silently recreated; external-ID tombstones return **410**. A cached replay may return **404** for a trashed project or **410** after the existing purge service rewrites its cache entry.

Invitation states: `not_requested`, `pending`, `sending`, `sent`, `failed`. `sent` means the configured SMTP transport accepted the message, not proof of inbox delivery. `pending` is resumable on replay; `sending` can mean an interrupted/unconfirmed send, which requires explicit owner resend. `failed` is terminal for automatic replay: use the existing Clients invitation action or canonical-link login. Explicit owner resend updates the operation state and event. Initial invitations expire in seven days; public login links expire in 30 minutes and preserve previous sessions. No plaintext login URL is stored in the replay cache or returned by provisioning.

Error statuses: **401** invalid/expired/revoked token, **403** insufficient scope/disabled creation, **409** conflicting idempotency/external ID, **410** unavailable binding, **413** oversized body, **415** JSON required, **422** input/key validation, **429** rate limit, **500** internal failure. Error responses do not echo request bodies or exception messages. Use the existing `code` and `errors` envelope. For a network failure, 429 or temporary 5xx, retry with the **same token, exact data and key**; honor `Retry-After` where present. Never generate a new external ID merely because a response was lost.

## Settings and operations

Owner-only session endpoints reuse current CSRF and fresh-confirmation checks:

- `PATCH /api/admin/integrations/tokens/{uuid}` with `{"provisioningEnabled": false}`.
- Existing `DELETE /api/admin/integrations/tokens/{uuid}` revokes the token.
- `GET /api/admin/integrations/operations?page=1`: 25 items/page, latest 500 authenticated requests/workspace. Each item includes token name, `outcome` (`success`, `error`, `replayed`), `httpStatus`, `operationId`, current `invitation` and timestamp. No request/contact data or secrets.

## Webhooks

Subscribe through existing endpoint settings. `project.created` is reused and provisioning supplies `changes.projectUrl`, `clientId`, `projectClientId`, `operationId`, `externalId`, `invitation` and `title`. New `project.invitation_changed` carries `operationId`, `invitation` and `projectUrl` under `changes`. The top-level `projectId`/`workspaceId`, actor, origin, event ID, signature, retries, project filtering and delivery history keep the existing contract. Neither payload contains login tokens/URLs, contact email, description or company data. `externalId` is business data chosen by the caller: never put a credential in it.

Event records are inserted atomically with the operation/state transition; outbound HTTP runs only through the existing post-commit webhook processor. Replays do not emit duplicate creation events. Use the normal [v1/webhook documentation](../milestone-5-0/README.md) for signatures and delivery handling.
