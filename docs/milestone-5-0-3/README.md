# Milestone 5.0.3 — Comments / Attachments Read API and quiet cron

Issue [#5](https://github.com/web86/projects-manager/issues/5), including the requested
Attachments Read API extension. Baseline `dd0d27f` includes `728b1f1`; branch
`codex/milestone-5-0-3-read-api-quiet-cron`. No push/deployment. Webhook event names,
payloads, HMAC, selected-project filters and at-least-once delivery remain unchanged.

## Read permissions

Existing tokens keep their existing permissions. New scopes are opt-in in the
owner-only token UI, with RU/EN labels and a privacy notice. Text, filenames and
files may contain personal information: grant these permissions only to a trusted
CRM. Create a replacement token to add scopes; do not expose bearer secrets in
URLs, shared logs or client code. Browser login/client cookies and selected workspace
do not authorize API v1. All endpoints share existing expiry/revocation checks,
120 requests/min/IP and 60/min/workspace limits, JSON errors and no-store responses.

| Endpoint                                                                | Required scopes                                                                                 |
| ----------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `GET /api/v1/projects/{projectUuid}/comments`                           | `comments:read`                                                                                 |
| `GET /api/v1/projects/{projectUuid}/comments/{commentUuid}`             | `comments:read`                                                                                 |
| `GET /api/v1/projects/{projectUuid}/tasks/{taskUuid}/attachments`       | `attachments:read` + `tasks:read`                                                               |
| `GET /api/v1/projects/{projectUuid}/comments/{commentUuid}/attachments` | `attachments:read` + `comments:read`                                                            |
| `GET /api/v1/attachments/{attachmentUuid}/download`                     | `attachments:read` + `tasks:read` for a direct task file, or `comments:read` for a comment file |

Task-comment files count as **comment files**. The task attachment list includes
only direct task files, so it cannot bypass comment permissions. Comment responses
always include safe file metadata with `comments:read`, even without
`attachments:read`; that scope is required for dedicated lists and file bytes.
`projects:read` is not additionally required for these routes; every project/parent
lookup still validates the token's workspace. Missing/foreign/integer-ID resources,
soft-deleted tasks, comments beneath them and inconsistent parent links return 404.
Comments have no private-note flag; the API uses existing public discussion visibility
and only client/admin author types. Legacy task comments with null project_id resolve
through their active parent task; mismatched non-null project_id is rejected.

## Comment responses and pagination

Responses use `{data: ...}`. Each comment is an allowlist:

```json
{
  "id": "COMMENT_UUID",
  "projectId": "PROJECT_UUID",
  "taskId": null,
  "author": { "type": "client", "id": "CLIENT_UUID" },
  "text": "Plain comment text",
  "createdAt": "2026-10-26T10:00:00.000000Z",
  "attachments": [
    { "id": "ATTACHMENT_UUID", "name": "report.pdf", "mimeType": "application/pdf", "size": 1024 }
  ]
}
```

`taskId` is null for general discussions. Client author IDs are public client UUIDs
or null; admins have no public UUID in this model, so their ID is null. No user/DB IDs,
names/email/phones as separate fields, internal notes/read counts, paths, magic links,
public/signed URLs or file contents are included. Comment text itself may contain
personal details or markup. Treat it as untrusted plain text; JSON hex-escapes HTML
characters without changing the decoded text. `updatedAt` is omitted because this
read API makes no claim about comment editing. No comment/file write routes are added.

List query parameters are strictly allowlisted:

- `kind=all|tasks|general` (default all).
- `taskId=<UUID>` requires `kind=tasks` and a visible task in this project.
- `authorType=client|admin`, optional.
- `perPage=1..100` (default 25), `cursor=<opaque continuation>`, optional.

Unknown/invalid/inconsistent filters and invalid cursors are 422. Lists sort by
creation sequence ascending, independent of timestamp ties. The first page captures
an upper creation watermark; later inserts are excluded from that continuation.
Removals and soft deletes are rechecked and do not shift offset/duplicate results.
This is a bounded keyset traversal, not a persistent database snapshot; updates to
existing records/visibility remain live. Start again to collect newer comments.
`meta` is `{perPage, hasMore, nextCursor}`; null nextCursor ends traversal. The
cursor encrypts internal positions and binds token UUID, project, normalized filters
and perPage. Keep those values unchanged on continuation. New token or APP_KEY
rotation invalidates old cursors (422); restart pagination. Do not parse a cursor.

## From webhook ID to text/file

Use your own deployment origin and set PB_TOKEN privately. The existing verified
webhook provides projectId/commentId; no list scan is needed. Never log file bodies
or real tokens. curl examples deliberately use synthetic UUID placeholders.

```sh
BRIEF_URL=https://brief.example.com
# Set PB_TOKEN, PROJECT_UUID, COMMENT_UUID from your private environment/verified event.
curl --fail-with-body -H "Authorization: Bearer $PB_TOKEN" \
  "$BRIEF_URL/api/v1/projects/$PROJECT_UUID/comments/$COMMENT_UUID"

curl --fail-with-body --get -H "Authorization: Bearer $PB_TOKEN" \
  --data-urlencode 'kind=tasks' --data-urlencode "taskId=$TASK_UUID" \
  --data-urlencode 'authorType=client' --data-urlencode 'perPage=25' \
  "$BRIEF_URL/api/v1/projects/$PROJECT_UUID/comments"
# Repeat the same filters/perPage; append --data-urlencode "cursor=$NEXT_CURSOR".

curl --fail-with-body -H "Authorization: Bearer $PB_TOKEN" \
  "$BRIEF_URL/api/v1/projects/$PROJECT_UUID/comments/$COMMENT_UUID/attachments"
curl --fail-with-body -H "Authorization: Bearer $PB_TOKEN" \
  "$BRIEF_URL/api/v1/projects/$PROJECT_UUID/tasks/$TASK_UUID/attachments"
curl --fail-with-body -H "Authorization: Bearer $PB_TOKEN" \
  --output downloaded-file.bin \
  "$BRIEF_URL/api/v1/attachments/$ATTACHMENT_UUID/download"
```

Downloads stream the original private local file after bearer, scopes, workspace,
visible parent, relationship consistency and existence checks. Only the attachments
storage subtree is eligible. Responses force attachment disposition with nosniff,
sandbox CSP, no-referrer and no-store. No public storage symlink, signed URL, redirect,
preview/original endpoint or in-memory base64 API is added. Missing stored bytes are 404. Files are not transformed; the consumer must handle untrusted content safely.

## Quiet cron and diagnostics

Default `integrations:webhooks --limit=20 --seconds=45` emits **zero stdout/stderr**
for empty queue, occupied lease, no due work, delivered 2xx and successful retries.
For each actual failed attempt it emits one sanitized timestamped stderr line with
safe delivery UUID, retry/failed, attempt, fixed error code and HTTP status. No URL,
endpoint name, request/response body, headers, secret or exception stack is printed.
Logs are written after the transaction commits. One receiver's failure does not
block other endpoints. There is no new endpoint-wide suppression that could hide
errors: existing exponential backoff (30 seconds through 1 hour) and six attempts
bound repeated lines; idle/backoff minutes remain silent.

Exit code is 0 for a normal successful/no-op run; 1 if any attempt fails or an
internal/DB/lock failure occurs. Internal failures emit a fixed `code=internal_error`
line without raw exception details. History transactions still roll back on failure;
lease acquisition/release, limits, retries and timings are unchanged. No shell wrapper
is needed to conceal stderr or force exit 0. PHP/OS startup failures remain nonzero
and visible via stderr; use the normal production PHP configuration, never debug
startup credentials in command arguments. Only explicit `-v` / `--verbose` adds
processed/succeeded/errors/retry statistics on stdout. UI history remains the full
per-delivery record. See [Jino smoke/deployment](PRODUCTION.md),
[verification](VERIFICATION.md), [release notes](RELEASE.md) and the canonical
[OpenAPI](../milestone-5-0/openapi.json).
