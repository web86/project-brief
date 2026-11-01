# Milestone 9.9 — collaboration contract

Baseline: v0.9.8. This implements the supplied Agent Collaboration brief before
Milestone 10 (plans/subscriptions). There is no billing or embedded LLM service.
The OpenAPI contract remains at [../milestone-9-6/openapi.json](../milestone-9-6/openapi.json).

## Access and credentials

Effective access still requires a valid credential, matching scope, registration
permission ceiling, owner project grant, project membership and current task
assignment. New scopes are never added to old tokens. An integration credential
cannot become an agent credential through scope selection or later rebinding.

`POST /api/admin/integrations/tokens` accepts `type=integration` (default) or
`type=agent`; agent creation also requires `agentId` (workspace agent UUID),
`name`, `scopes`, optional `expiresAt`. Existing owner-only fresh MFA/CSRF and token
limits apply. Integration source fields are rejected for agent keys. The response
contains a one-time secret; only its hash is stored. `GET /api/admin/integrations`
returns both types with agent/name/scope/expiry/activity/revocation metadata.
`DELETE /api/admin/integrations/tokens/{token}` revokes either type with audit.
Legacy `/integrations/agents/{agent}/tokens` endpoints remain compatible, but the
UI manages credentials exclusively in the API keys tab. Issuance does not silently
rotate other keys; explicit revoke remains available.

New project grants add existing `project_members` entries in the same workspace
transaction. Unchanged grants never recreate removed memberships. Removing a grant
removes membership, revokes assignments (including trashed tasks), cancels active
runs and pending/processing deliveries. Reports/messages/authorship remain.
Existing grant/membership discrepancies are not backfilled: an authorized owner
or project manager can explicitly add the missing membership through Team.
Project superadministrators cannot create owner grants or credentials.

## Instructions and context

Agent settings accept optional `instructions` (10,000 characters), readable by
that executor through `agent:profile`. Project settings expose
`GET/PATCH /api/admin/projects/{project}/agent-instructions` with `{instructions}`;
PATCH requires existing `manage_project_settings`, fresh proof and tenant access.
Project instructions are not included in public project/client representations.

Assignment `POST /api/admin/tasks/{task}/agents` additionally accepts optional
`instruction` (5,000 characters). A new assignment creates its initial queued run;
repeating the same assignment does not create another run.

Each run freezes context in this order: agent instruction, project instruction,
public task fields, run instruction, permitted history from the **same assignment**.
The SHA-256 `contextVersion` identifies that snapshot, not a live mutable prompt.
History is the latest 20 messages within a 40,000-character budget; the complete
history remains paginated in the dialogue APIs. No developer notes, estimates,
client contacts, credentials or closed integration contents are included.
All instruction text is untrusted relative to server authorization.

## Team endpoints

| Method / path                                                                     | Contract                                                                                                           |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `GET /api/admin/tasks/{task}/agent-collaboration`                                 | Authorized project team only; includes current/revoked assignments, latest 200 messages, latest 25 runs and counts |
| `POST /api/admin/tasks/{task}/agent-assignments/{assignment}/runs`                | `instruction`, UUID `submissionId`; existing `manage_structure` permission; one active run per assignment          |
| `GET /api/admin/tasks/{task}/agent-assignments/{assignment}/runs?page=N`          | Run history, 25/page; old assignments remain available to the team                                                 |
| `GET /api/admin/tasks/{task}/agent-assignments/{assignment}/messages?before=UUID` | Earlier messages, 200/page; nextCursor is the first returned message UUID                                          |
| `POST /api/admin/tasks/{task}/agent-runs/{run}/cancel`                            | Cancel an active run; same permission, idempotent for terminal runs                                                |

Same submissionId/instruction returns the original run; changed input with the
same ID or a distinct request while an active run exists returns 409. Workspace
locking serializes creation, cancellation, token/grant revocation and API writes.
Messages are distinct from client comments and project team chat. Agent result
comments/files remain team-only and supply the existing human publication flow.

## Agent endpoints and scopes

| Method / path                                                                 | Required scope                                                                                       |
| ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `GET /api/v1/agent/tasks/{task}/runs?page=N`                                  | `agent:runs:read`                                                                                    |
| `GET /api/v1/agent/tasks/{task}/runs/{run}`                                   | `agent:runs:read` + `agent:messages:read`; includes frozen context and private history               |
| `POST /api/v1/agent/tasks/{task}/runs/{run}/start`                            | `agent:runs:write`                                                                                   |
| `GET /api/v1/agent/tasks/{task}/messages?before=UUID`                         | `agent:messages:read`                                                                                |
| `POST /api/v1/agent/tasks/{task}/messages`                                    | `agent:messages:create`                                                                              |
| `GET /api/v1/agent/tasks/{task}/runs/{run}/attachments/{attachment}/download` | `agent:attachments:read`, registration taskAttachments, `agent:messages:read` and current assignment |

Run reads use the existing assignedTasks registration ceiling; start uses reports,
reply uses comments. A run from a previous assignment or another agent is never
readable through these routes, even with broad project/task/team-chat read scopes.
New dialogue-backed comments/files are excluded from the old general task-comment
API; authorized file retrieval uses the bound run route above.

All writes require the existing `Idempotency-Key`. Start body is
`{assignmentId,runId}`. Only queued→running is claimable; competing start keys get 409. Exact retries replay the response with `Idempotency-Replayed: true` and must
not trigger LLM execution again. A crashed claimed execution needs human review,
cancel/retry; there is no unsafe automatic takeover or hidden execution lease.

Existing reports/comments/attachments endpoints additionally accept `runId`.
Reports use existing state/summary/HTTPS result link fields; optional errorCode
is a bounded lowercase identifier, not a raw provider response. Dialogue replies
require `{assignmentId,runId,text}`. Upload files before the terminal report using
`{assignmentId,runId,text?,attachments[]}`. Terminal runs reject new results; the
same idempotency key still replays its original response while access remains valid.
Run states: queued, running, submitted, failed, cancelled. Webhook success records
deliveredAt only; it never asserts running. Task workflow/approval is unchanged.

Legacy executors without runId can finish the initial run of a new assignment;
pre-existing assignments with no run history retain the legacy reporting path.
A follow-up run requires an explicit runId to prevent a late old reply from being
associated with new work. Revoked credentials/grants/assignments deny old replays.

## Webhooks and external execution

Existing HMAC, SSRF/TLS validation, retry/outbox and delivery audit remain in use.
Initial `agent.task.assigned` includes runId; `agent.run.requested`,
`agent.message.created`, `agent.run.cancelled` contain identifiers only. Project
removal cancels pending deliveries, and delivery rechecks current access.
Existing endpoint subscriptions are not silently expanded; resaving existing
agent identity without changing URL does not change its subscribed event list.
The new example also supports REST discovery of queued runs without webhooks.

[examples/collaboration.mjs](examples/collaboration.mjs) is a one-shot independent
Node executor. Run outside Jino with PROJECT_BRIEF_URL, PROJECT_BRIEF_TOKEN and
agent scopes for tasks, runs, messages:read and reports. It claims a run, reads its frozen context,
returns a private report and skips replayed claims. Default deterministic output
needs no commercial model. Optional `AGENT_PROVIDER=groq` uses a disposable
GROQ_API_KEY and explicitly selected GROQ_MODEL on the executor machine. No provider
key is stored in Project Brief; logs contain outcome IDs only. Production client
content must not be used for controlled QA.

## Task formatting

Task create/update accepts optional `descriptionHtml` alongside the legacy text
field. The common server normalizer allowlists p/br/strong/em/ul/ol/li/a and safe
HTTP(S)/mailto links, strips other attributes and removes executable/embedded
content. It derives canonical plain description (list markers, link targets,
paragraphs), sanitized HTML and a text-source hash. Plain-only writes clear rich
formatting. If an older runtime changes the text, the source hash prevents stale
HTML from reappearing after re-upgrade. Legacy descriptions remain plain text;
literal angle brackets are not interpreted as stored markup.

Browser/client rendering uses the safe existing agreement block renderer, not
raw untrusted v-html. PDF/DOCX preserve rich spans/lists/links; notifications,
REST text and AI context receive canonical text. Existing drafts/save/version
checks remain. AI improvement starts on explicit button click without the former
pre-confirmation, preserves the proposal modal and requires Apply plus task Save.
Double clicks do not send twice; cancellation/errors do not overwrite the draft.
