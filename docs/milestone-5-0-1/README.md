# Milestone 5.0.1 — project-scoped webhooks and client comment events

Base: local `codex/milestone-5-0-integrations`, HEAD `1360ec5`, source commit
`5042b23`. The 5.0 archive's SHA-256, release.json commit/version and packaged
WebhookEvents, IntegrationController and CommentSubmission were checked against the
local sources before branching. GitHub main was not used. Feature branch:
`codex/milestone-5-0-1-webhook-filters`. No push or deployment.

## Selection and subscriptions

All management stays in workspace settings, `/admin/settings/integrations`, and
requires an active owner membership for every read/write. The UI includes all/selected
radio buttons, searchable checkboxes with project names and a selected count. New
endpoints still start with only `task.approved`; comment events require explicit
checkbox selection. Errors, loading, no-project/no-search-result/empty-selection
states are localized. Existing subscriptions, tokens and one-time secrets retain
their 5.0 behavior.

`projectFilter=all` includes every project in the workspace, including future ones.
`projectFilter=selected` requires 1–1000 unique, available workspace project UUIDs.
An explicit nonempty list with all is rejected. Owner GET returns the workspace
project catalog and public selection UUIDs. Partial updates without selection fields
leave the filter unchanged. Reordering the same set is a no-op. No client contact or
project details beyond UUID/name are added to management responses.

The new additive migration supplies `project_filter=all` for every old endpoint;
subscriptions are not expanded. `webhook_endpoint_projects` has composite foreign
keys referencing both endpoint/workspace and project/workspace. Cross-tenant links
are rejected by validation and the database. Parent project deletion removes the
selection; if selected becomes empty it matches no business event. All does not
silently replace an empty selected filter. No existing project transfer feature is
introduced; the composite FK prevents cross-workspace parent changes.

Selection happens **before** delivery rows are created. Excluded events have no
recipient delivery and do not appear in the delivery log. `webhook.test` bypasses
selection when enabled, keeping projectId=null. Endpoint filter changes increment
`secret_version` (the existing configuration version) and cancel **all** pending
rows, even if some would still match. Failed old-version deliveries retain history
but manual retry is blocked. The immutable event/body/IDs are never rewritten.

The processor holds the endpoint lock during its existing HTTP attempt; filter
mutation waits for that attempt to finish, then affects all subsequent attempts.
It cannot recall a remote acceptance already in progress. The cron lease remains
unchanged. Send and manual retry independently recheck project availability, tenant
and filter, including deleted projects. A queued delivery that no longer matches is
cancelled with safe error `project_filtered`. This is a defensive check in addition
to version cancellation, not a new network handler or worker.

REST API token access remains workspace scoped and governed by its existing scopes.
Webhook project selection never narrows or expands bearer token permissions.

## Comment event contract

Emission uses the shared `CommentSubmission::store()` path for task and general
comments. It runs after attachment writes and existing audit/notification dispatch,
inside the same database transaction. Outbox rows become visible only when commit
succeeds. A failed write/attachment, rollback, conflicting submissionId or replay
of the same saved submissionId creates no duplicate business event. Only
`author_type=client` emits these events. The new path neither changes nor consults
email/push/Telegram rules or personal preferences; old counters/notifications retain
their existing behavior.

Task comment example:

```json
{
  "version": 1,
  "id": "a276651b-dc84-4ac7-a14e-df98f25f14a7",
  "type": "task.client_comment_created",
  "occurredAt": "2026-10-26T08:30:00.000000Z",
  "workspaceId": "27ad771b-b17d-4ce0-91ee-02819e41eea0",
  "projectId": "fae68b15-6ba9-4bb1-a227-c48026e37b1c",
  "taskId": "072476e3-8f54-4be3-b493-00ed768d971a",
  "commentId": "1333b6b6-bcb4-4c2b-b4e9-8c393057d071",
  "actor": { "type": "client", "id": "7d7ba982-541d-451e-899e-07374f4c51f0" },
  "changes": {},
  "origin": "ui"
}
```

General discussion comment example:

```json
{
  "version": 1,
  "id": "f6189111-52e6-415b-b180-569946082c25",
  "type": "project.client_comment_created",
  "occurredAt": "2026-10-26T08:31:00.000000Z",
  "workspaceId": "27ad771b-b17d-4ce0-91ee-02819e41eea0",
  "projectId": "fae68b15-6ba9-4bb1-a227-c48026e37b1c",
  "taskId": null,
  "commentId": "208b2cfe-716f-43d7-a97b-ad83f9e64051",
  "actor": { "type": "client", "id": "7d7ba982-541d-451e-899e-07374f4c51f0" },
  "changes": {},
  "origin": "ui"
}
```

A general discussion comment has `type=project.client_comment_created` and
`taskId=null`; all other envelope fields use the same types, with that comment's
UUID in commentId. Payloads do not include comment text, names, email, files,
filenames, private URLs, secrets, developer notes, estimates or prices. No deep-link
or magic-link was added. Comment IDs are metadata only; this milestone adds no REST
comment reader. Existing event types retain their exact 5.0 envelope and fields.

HMAC headers, raw byte signing, retries and stable event/delivery IDs are unchanged.
Receivers must still verify signature/timestamp, deduplicate event IDs and return
2xx promptly. Default subscriptions do not include either new event. Opt in to both
only where needed:

```json
{
  "events": ["task.client_comment_created", "project.client_comment_created"],
  "projectFilter": "selected",
  "projectIds": ["fae68b15-6ba9-4bb1-a227-c48026e37b1c"]
}
```

Use the existing session/CSRF owner PATCH `/api/admin/integrations/webhooks/{uuid}`
(or the UI) to apply this. Switching to all uses `{"projectFilter":"all","projectIds":[]}`.
The canonical [integration README](../milestone-5-0/README.md) and
[OpenAPI](../milestone-5-0/openapi.json) include the additive contract.

## Limits and evidence

The existing HTTPS/public-IP/DNS pinning, response/time limits, six-attempt backoff,
at-least-once semantics, immutable bytes and retention limits remain. A filtered-out
business event has no delivery log row by design. A deleted-project selected endpoint
may remain enabled but matches nothing until its owner chooses available projects.

See [verification/screenshots](VERIFICATION.md), [production/rollback](PRODUCTION.md)
and the release artifact record. Tests use disposable SQLite and HTTP fakes. No live
Jino MariaDB, CRM or n8n E2E has been performed. Separate-process SQLite lock tests
exercise in-flight serialization, not remote networking or MariaDB row-lock behavior.
