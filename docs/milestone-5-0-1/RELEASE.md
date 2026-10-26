# ProjectBrief 0.5.0.1

Workspace owners can limit each outgoing webhook to explicitly selected workspace
projects or keep all projects, including future projects. A searchable project picker,
selected count and localized empty/error states are available in Integrations.

Two metadata-only client comment events are available by explicit opt-in:
`task.client_comment_created` and `project.client_comment_created`. Payloads contain
comment/project/task/client UUIDs and no comment text, contact information or files.
Existing subscriptions and old event payloads remain unchanged.

An additive migration defaults all old endpoints to all projects and adds strict
workspace-bound selection constraints. Filter changes cancel pending deliveries;
the cron sender and manual retry recheck current selection. HMAC, keys, retries and
the existing cron command are unchanged. Read PRODUCTION.md before upgrade or rollback:
old 5.0 senders do not enforce selected project filters.

No push, deployment, live Jino/CRM E2E, new connector, comment reader or project-scoped
API token is included. API specification and integration notes are in INTEGRATIONS/.
