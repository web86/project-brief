# Project Brief v0.9.10 — shared credentials and private agent chat

Baseline: v0.9.9. This patch does not introduce plans/subscriptions or a server LLM.
The external contract is [OpenAPI](../milestone-9-6/openapi.json).

## Credential identity

`POST /api/admin/integrations/tokens` with `type=agent`, `name`, `scopes` and optional
`expiresAt` creates an **unbound shared agent credential** when `agentId` is omitted.
It may precede all agent registrations. Agent scopes remain a separate namespace;
ordinary integration credentials cannot be rebound or used as agent credentials.
The token type and scope ceiling are immutable. Existing owner/fresh MFA, CSRF,
expiry and token limits remain. Only a hash is stored; the secret is revealed once.

For compatibility, the existing `type=agent, agentId=UUID` issuance and legacy
`/integrations/agents/{agent}/tokens` still create individual credentials. They
retain their original request format and scopes. Individual credentials are not
silently converted into shared keys or attached to additional agents.

Agent registration/update accepts nullable `credentialId`, referencing a current,
nonexpired shared agent credential in the same workspace. One current shared-key
binding per agent; a key may have many agents. Omission preserves the binding;
null explicitly detaches. Replacement changes the binding generation. Historical
operations and individual tokens remain; a secret is never re-revealed.

Every shared-key request requires:

```http
Authorization: Bearer pb_...
X-ProjectBrief-Agent-ID: <linked external-agent UUID>
```

The header is a **claimed identity**, not cryptographic proof of which program
holds the secret. Any holder can impersonate any linked agent. Share a key only
inside one trust boundary; independent providers should receive separate keys.
The server requires an active, same-workspace link and identity, matching scope,
owner grant, project membership and object/assignment access.
No prompt text can replace those checks. General integration routes reject agent
credentials; general credentials cannot use Agent API by adding the header.

Audit retains the credential ID, validated claimed UUID (including rejected valid
UUID claims), resolved actor where available, operation and status. No secrets,
prompts, files or request bodies are recorded. Shared write idempotency includes
agent and binding generation, so the same key/string cannot replay another
agent's response or a previous detached binding. Existing individual dedupe keys
remain unchanged.

Revoke denies all uses of that key immediately. Detach denies that agent's use
without affecting other links. Active lists/selectors hide revoked keys; database
records, usage, operations, reports and idempotency records are preserved. If an
agent has no other usable credential, pending webhook deliveries are cancelled.
Identity deactivation/removal detaches it without revoking the other agents' key.

## Chat and files

Existing assignment creation remains compatible. The compact UI sends optional
`deferRun=true` so assignment alone does not execute an empty instruction. The first
chat message creates the run. Optional `replace=true` switches assignments in one
workspace transaction, preserving previous histories and cancelling previous work.
Both operations require existing `manage_structure` permission.

`POST /api/admin/tasks/{task}/agent-assignments/{assignment}/runs` accepts JSON
`instruction, submissionId`, or multipart with `attachments[]`. The existing
SafeAttachment policy applies: at most five files, 20 MiB each, 40 MiB total. Text
is optional only when files are present. The submission fingerprint includes file
names and bytes; same retries return one run/message/file set, changed bytes with
the same submission ID return 409. Storage uses the existing atomic/private
comment infrastructure, with a private human message bound to that run.

Team collaboration/messages responses include private `commentId`, report/link
metadata and attachment metadata. Prior private reports without a run-backed
message are presented virtually in `legacyMessages`; no data/authorship backfill
occurs. Historical public reports keep their existing client visibility and are labelled
as already public in the team chat; this patch does not retroactively hide them.
Team-only legacy pagination:
`GET /api/admin/tasks/{task}/agent-collaboration/history?before=UUID`.
All current/revoked generations remain readable by the authorized project team,
including after a registration is soft-deleted. New assignments never inherit
other assignments' private conversation.

Agent `GET /api/v1/agent/tasks/{task}/messages` includes files only with
`agent:messages:read` and `agent:attachments:read`.
Download still requires those permissions, a current assignment, and an exact
run/comment/task/file binding at
`GET /api/v1/agent/tasks/{task}/runs/{run}/attachments/{file}/download`.
Uploading in the human UI grants no read permission. Clients, general integrations
and unrelated agents cannot read the originals through task, export, notification
or download surfaces. Frozen contexts omit file bodies; authorized file retrieval
is separate. All three instruction levels update only future snapshots.

Reports are chat answers. Long answers expand inline; technical run IDs/history
are not part of ordinary chat UI. Explicit human publication reuses
`POST /api/admin/tasks/{task}/agent-results/{comment}/share`. Optional `audience=team`
creates a separate private project-team message; omitted/`client` preserves the
existing reviewed client-copy behavior and old retry fingerprints. Text, links
and files must be selected explicitly. Originals remain private.

## Webhooks and project summaries

Unified webhook creation optionally accepts `agentId` for an active workspace
agent. Identity is immutable; only the existing bound agent's event namespace is
accepted. Ordinary endpoints keep their existing event set. One agent receiver
per registration matches the existing agent endpoint model. Editing keeps the
same endpoint and existing HMAC/secret-version/retry/SSRF/TLS mechanisms.
A test event contains minimal IDs and never starts the executor. Resaving agent
instructions without `webhookUrl` preserves its receiver and disabled state.

`agentsCount` in the existing admin project list is a SQL count of active actual
external-agent team members (all supported kinds), not workspace registrations or
owner grants. Client project representations omit this private count.
`hasAgentHistory` controls task chat visibility for teams. Projects with neither
available agents nor task history have no empty AI blocks.

## Editing an unagreed task

Authorized developers see the existing rich task edit route before client
agreement. Server `canEditBrief` also checks `manage_structure`. The same condition
is checked under the update lock; public title/description/location/result/priority
and section edits are rejected after `client_approved` or status `approved`, even
if a stale form remains open. Existing edit-version conflict handling remains.
Status, estimates and private notes remain independent operations.

## Independent executor

[examples/collaboration.mjs](examples/collaboration.mjs) has no npm dependencies.
Set PROJECT_BRIEF_URL, PROJECT_BRIEF_TOKEN and, for a shared key,
PROJECT_BRIEF_AGENT_ID. Existing individual credentials need no ID header.
Default deterministic execution needs no provider. Optional AGENT_PROVIDER=groq
uses GROQ_API_KEY/GROQ_MODEL only on the external machine.

Optional `AGENT_READ_TEXT_FILES=1` reads only explicitly authorized current-run
UTF-8 text/plain attachments, up to five and 64 KiB total, through the protected
API. No permission means no file metadata/body; denial after revocation stops
execution. Other formats stay available through the authorized API for an external
executor that supports them. File reading is off by default and never uploads
files to a provider merely because a user selected them in Project Brief.

## Final v0.9.10 refinement

New agents always use `kind=agent`. The UI omits kind and registration permission
fields. Editing retains existing `ai`, `automation`, `service` values; stored
legacy permission JSON is retained for compatibility but is no longer an
authorization ceiling. `/agent/me` reports permissions derived from the actual
credential scopes. Existing credential scope arrays are never widened or rewritten.
A caller cannot gain a missing scope by changing registration fields.

**Access policy change:** credentials are now the sole operation-permission ceiling.
If a previously broad key relied on restrictive registration flags, review and
replace/rebind that key with the intended scopes before updating. Shared keys have
one trust boundary. Active identity, current binding, workspace, owner grants,
project membership, assignment and exact object/file checks remain mandatory.
Registration without a key creates no credential and grants no API access.

The Team page uses the same member-assignment and email-invitation endpoints in
one modal. The invitation form uses the existing SMTP delivery action instead of
requesting a manual link. No new invitation or account mechanism is introduced.

`GET /api/admin/reviews` is owner-only and workspace-scoped. It reads submitted
`project_feedback` with a bounded page of 25, newest first. Optional `from` / `to`
are inclusive dates in the validated `timezone` (UTC by default); the UI supplies
its existing display timezone without another setting. Completed and soft-deleted
projects remain represented while feedback exists. Archived project links lead
to the existing recycle bin. No new feedback table or collection workflow exists.
