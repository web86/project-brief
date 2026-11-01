# Agent API v1 — Project Brief 0.9.8

A provider-independent REST contract for Codex, Yandex AI Studio, GigaChat, n8n and independently implemented PHP/Node.js/Python executors. No AI provider is connected and no supplied code runs on the Project Brief server. MCP can later adapt this contract; no MCP server or billing is implemented.

## Registration and authentication

Only the workspace owner manages registrations, credentials, allowed project grants and webhook destinations under Integrations. Existing owner session/MFA/fresh confirmation applies. Project superadministrators may add an owner-approved existing agent to an assigned project's existing Team; they cannot read credentials or change global integration settings. Any active human project participant can assign tasks to an active agent already in that team.

Agents have no user account, email, password, Magic Link, TOTP or Passkey. Token authentication uses the existing integration credential table and middleware. Credentials use 32 cryptographically random bytes, `pb_` followed by 64 hexadecimal characters, stored as SHA-256 hashes. The full token is returned only at issue/rotation. Send `Authorization: Bearer <token>` over HTTPS, never URL parameters. Responses use `Cache-Control: no-store`.

The original independent scopes retain their assignment-only semantics:

| Scope                      | Operation                        |
| -------------------------- | -------------------------------- |
| `agent:profile`            | Identity and allowed projects    |
| `agent:tasks:read`         | List/read assigned tasks         |
| `agent:tasks:report`       | Submit execution reports         |
| `agent:comments:create`    | Post comments to assigned tasks  |
| `agent:attachments:create` | Upload results to assigned tasks |

All agent credentials are denied every generic `/api/v1` integration route. Generic integration tokens cannot be issued these scopes. A successful read requires the current registration permission, active registration, nonrevoked/unexpired token, matching workspace, live active project, explicit owner grant, current project membership and the appropriate object access. Original task reads/writes require an active assignment; project-wide reads require their separate new scope and permission. Scope alone never grants object access.

**Connecting an external AI provider transfers assigned task content to that provider. The owner must consciously authorize this integration and project selection.** Names/descriptions and task bodies are untrusted content. Production credentials must never be included in task descriptions.

## Endpoints

Base path `/api/v1/agent`; UUIDs are opaque stable identifiers. No session/CSRF is required for bearer API requests.

| Method and path                    | Scope                      | Body / response                                                                          |
| ---------------------------------- | -------------------------- | ---------------------------------------------------------------------------------------- |
| `GET /me`                          | `agent:profile`            | `{data: {id,name,description,kind,active,lastActivityAt,workspaceId,scopes,projectIds}}` |
| `GET /tasks?page=1`                | `agent:tasks:read`         | `{data: Task[], meta: {page,perPage:25,lastPage,total}}`; assignment ID ascending        |
| `GET /tasks/{taskId}`              | `agent:tasks:read`         | `{data: Task}` with description, expectedResult, location, priority                      |
| `POST /tasks/{taskId}/reports`     | `agent:tasks:report`       | assignmentId, state, summary; optional pullRequestUrl/resultUrl                          |
| `POST /tasks/{taskId}/comments`    | `agent:comments:create`    | assignmentId, text                                                                       |
| `POST /tasks/{taskId}/attachments` | `agent:attachments:create` | multipart assignmentId, optional text, attachments[]                                     |

Task list fields: id, assignmentId, projectId, title, taskStatus, executionState, updatedAt. Details additionally contain description, expectedResult, location, priority. No client records/contact details, other tasks, chat, comments, source attachments, notes, financial data, personal settings or integration secrets are included. Additional data is available only through the explicitly enabled read routes below; these fields never widen the original task response.

Example report:

```http
POST /api/v1/agent/tasks/00000000-0000-4000-8000-000000000001/reports
Authorization: Bearer <secret>
Idempotency-Key: execution-123-report
Content-Type: application/json

{"assignmentId":"00000000-0000-4000-8000-000000000002","state":"submitted","summary":"Implemented and tested; please review.","pullRequestUrl":"https://github.com/example/repo/pull/1"}
```

Writes return HTTP 201: `{data:{id,assignmentId,commentId,executionState,attachments:[{id,name,size}]}}`. Report IDs identify an immutable report linked to an existing task comment. New results are team-only agent-authored task comments; historical comments keep their stored visibility. An authorized human may publish a separate reviewed client message with explicitly selected links/files; the internal source is unchanged. Private files require authorized download routes; report URLs/states are shown in the human task executor panel. The API does not fetch external URLs. URLs must be HTTPS without userinfo/control characters and are opened with noopener/noreferrer. Text is rendered escaped.

summary/text maximum 5000 characters. Reports require state and nonblank summary. Attachment requests accept 1–5 files, 20 MiB per file, 40 MiB aggregate, with existing extension/MIME/content checks (JPG/PNG/WEBP/PDF/DOC/DOCX/TXT/ZIP); existing image dimension and private storage rules apply. Unknown fields are rejected. See machine-readable `openapi.json` for schemas.

## Execution and retries

States `pending`, `running`, `submitted`, `failed`, `cancelled` describe an assignment's execution, independently of Project Brief task statuses. Reports update that assignment state, never the task status, client approval or review workflow. An executor may post progress/retry reports while it retains the assignment; the last report determines execution state. A human reviews results and changes task workflow using existing authorized UI.

Each POST requires `Idempotency-Key` (1–128 ASCII letters/digits/period/underscore/colon/hyphen). Keys are scoped to credential and route. Same key/body returns the original HTTP 201 with `Idempotency-Replayed: true`; changed body returns 409. File fingerprint includes name and content hash, not temporary paths. After unassignment or project revocation even a replay is denied. Reassignment creates a new assignment UUID; an old assignmentId returns 409. Credential rotation creates a new key namespace; external executors should persist their own work IDs.

One request at a time per workspace for agent access and control mutations provides a consistent revocation boundary. Work accepted before a completed revocation remains in audit; requests after revocation fail. Default rate limits: existing integration IP 120/minute, workspace 60/minute and agent credential 60/minute. No tariff logic. Honor HTTP 429 Retry-After, bounded exponential backoff and jitter. Fetch pages sequentially and avoid frequent polling; five-minute external scheduling is adequate for the demo.

## Errors

Stable `{code,message}` envelope; 422 may include field errors. 401 invalid_token (disabled/deleted/revoked/expired); 403 insufficient_scope/function denied; 404 not_found (unassigned or foreign object without existence disclosure); 409 idempotency_conflict (changed input or old assignment generation); 413 payload_too_large; 422 validation_failed (including missing/invalid Idempotency-Key); 429 rate_limited; 500 internal_error. Unknown routes return 404; unsupported methods return 405. Error responses contain no task data or secrets.

## Administration contract

Owner session routes: `GET/POST /api/admin/integrations/agents`, `PATCH/DELETE /agents/{agentId}`, `POST /agents/{agentId}/tokens` (scopes, optional expiresAt/replace), `DELETE /agents/{agentId}/tokens/{credentialId}`. Save fields: name, description, kind (`agent|ai|automation|service`, new UI registrations use `agent`), active, projectIds, optional instructions and nullable shared credentialId. Saves omit webhookUrl; nonempty values are rejected. Webhooks are managed independently under `/api/admin/integrations/webhooks` and new endpoints have no agent binding. Issuance expiry can be up to two years. Replace=true revokes all earlier credentials. Disabling revokes all credentials, cancels pending deliveries and task assignments; reactivation requires fresh credentials and task assignments. Deletion hides registration but preserves its identity and historical authorship.

Project Team routes: `POST/DELETE /api/admin/projects/{projectId}/team/agents/{agentId}` under manage_team plus fresh confirmation. Removing a team membership cancels task assignments immediately and leaves owner grants/registration intact. The existing project detail response includes safe team agent identities in `agents` for human administrators. Task assignment: `POST/DELETE /api/admin/tasks/{taskId}/agents` with agentId. These extend existing project/team access checks.

## v0.9.8 additive read contract

All six expanded read flags default off. Registration permission keys are `assignedTasks` (baseline, on), `projectTasks`, `taskComments`, `clientDiscussions`, `teamChat`, `taskAttachments`, `discussionAttachments`; existing writes use `reports`, `comments`, `attachments` (baseline on). Owner-only registration `permissions` is an optional boolean map; omitted keys retain existing values on update. Historical registrations with null permissions use baseline defaults.

| GET route under `/api/v1/agent`                                 | Additional scope            | Registration permission                                                |
| --------------------------------------------------------------- | --------------------------- | ---------------------------------------------------------------------- |
| `/projects/{project}/tasks`, `/projects/{project}/tasks/{task}` | `agent:projects:tasks:read` | projectTasks                                                           |
| `/tasks/{task}/comments`                                        | `agent:comments:read`       | taskComments plus parent task read access                              |
| `/projects/{project}/discussions`                               | `agent:discussions:read`    | clientDiscussions                                                      |
| `/projects/{project}/team-chat`                                 | `agent:team-chat:read`      | teamChat                                                               |
| `/tasks/{task}/attachments`                                     | `agent:attachments:read`    | taskAttachments plus parent access                                     |
| `/discussions/{comment}/attachments`                            | `agent:attachments:read`    | discussionAttachments plus parent discussion/chat scope and permission |
| `/attachments/{attachment}/download`                            | `agent:attachments:read`    | Exact file and parent permissions, rechecked for each download         |

Lists use `page` and the existing 25-item pagination envelope. Project-wide task records omit assignmentId/executionState and contain only the original safe task context. Comment records contain id/text/authorType/teamOnly/createdAt, without author email, phone or account details. Attachment records contain id/name/size/type and a protected relative download route.

Task comment reads need either the old assigned-task scope and current assignment, or the new project-wide scope and permission. Internal task comments also require the explicit teamChat permission and scope; public task comments do not imply internal access. Task comment files additionally require the comment permission/scope. Discussion files require the corresponding public-discussion or team-chat permission/scope. File permission alone never authorizes a parent or the entire project storage.

Existing token scopes are never mutated or rotated when permissions change. New permissions require a separately issued token with the matching scope. Disabled registration permissions block old tokens immediately, including write replays. The existing workspace lock serializes these checks with control changes. There is no team-chat write scope or new task workflow authority.
