# ProjectBrief 0.5.0

Workspace owners can connect CRM/n8n/internal tools through scoped, revocable API
tokens and signed outgoing webhooks. A separate stateless REST API creates projects
with optional clients and creates ideas with existing section numbering and audit
history. Safe paginated reads and explicit estimate scopes control exposed data.
Persistent idempotency and workspace-scoped external project identity prevent
repeated creates.

The owner-only Integrations page supports one-time secret copy, scopes/expiry,
revocation, webhook creation/editing, enable/disable, rotation, queued test delivery,
attempt history and manual retry. RU/EN and light/dark/system use the existing UI.

Outgoing events are stored transactionally with business changes and delivered by
`integrations:webhooks` cron, with HMAC raw-byte signatures, public-IP validation,
DNS pinning, no redirects and bounded retries. Receivers must deduplicate event IDs.
A new additive migration and a cron entry are required. See PRODUCTION.md and
INTEGRATIONS/README.md in the archive before installation.

No production deployment, push, live CRM E2E, provider connector or two-way sync is
included. See the source verification report for local evidence and limitations.
