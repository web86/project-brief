# Milestone 9.6.1 — Agent API UX Polish / v0.9.7

UX patch on v0.9.6. Five integration tabs now use the requested order and a short Agents label with robot icon. Agent editing uses an 880px dialog with fixed header/actions, a scrolling body, executor-specific hints, searchable explicit project grants, selected chips and dirty-draft confirmation. Request errors retain form data.

Registered cards group allowed-project counts, real API activity and known credential metadata. No persistent connection status is inferred. The compact five-step connection help references the existing independent Node example. Team assignment uses one Add executor selector. The task panel separates execution and registration, shows only the latest report with a three-line preview and links to its full comment.

Changed UI: `WorkspaceAgents.vue`, `TaskAgents.vue`, `AdminIntegrationsView.vue`, `AdminProjectTeamView.vue`, `agentPresentation.js` and four locale catalogs. Backend runtime, schema, API values/scopes, grants, signed webhooks, idempotency and task approval rules remain unchanged. Visibility assertions extend the existing API regression suite.

**Client visibility:** reports, comments, links and attachments can be read by authorized project clients. See [the factual audit and separate future proposal](RESULT-VISIBILITY.md). There is no silent privacy migration.

Release/test evidence: [RELEASE.md](RELEASE.md). [Jino update and rollback](PRODUCTION.md). Existing [API contract](../milestone-9-6/AGENT-API.md), [security](../milestone-9-6/AGENT-SECURITY.md), [webhooks](../milestone-9-6/AGENT-WEBHOOKS.md) and [independent Node executor](../milestone-9-6/examples/README.md) remain applicable.
