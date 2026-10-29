# Milestone 8.1 — Project Requests & External Approval API

Release **0.8.2** distinguishes this milestone from the completed Client Entry UX release 0.8.1. Base: `codex/milestone-8-1-client-entry-ux`, `8c616fd`. Work branch: `codex/milestone-8-1-project-requests`. No push, merge or deployment is authorized by this work.

A request remains `pending` until a workspace owner or an integration with `requests:approve` makes a final decision. It is not a project or client access. Approval reuses the existing ProjectProvisioning, ProjectCreation, workspace contact resolution and invitation state machine. Rejection is final. UI and API use the same decision code and workspace lock.

- [REST API and stable source identity](API.md)
- [OpenAPI extension](openapi.json)
- [MODX 2 / standalone PHP examples](MODX.md)
- [Security and test report](TESTS.md)
- [Jino deployment and rollback](PRODUCTION.md)
- [Verified release artifact](RELEASE-ARTIFACT.json) · [SHA-256](SHA256SUMS.txt)

Owners have a Requests link beside Projects, a pending counter, status filters, search/date sorting, details, invitation choice and reject confirmation with an optional internal reason. Existing Live Sync refreshes the counter and visible requests; there is no new polling coordinator, cron, Redis or WebSocket. Four interface/mail locales and existing themes/branding remain supported.
