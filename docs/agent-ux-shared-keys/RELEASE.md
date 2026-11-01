# Project Brief v0.9.10 — Agent UX, shared API keys and workflow refinement

Based on the verified v0.9.9 working version. Separate branch:
`codex/agent-ux-shared-api-keys`. Milestone 10 plans/subscriptions remain separate.

This patch adds generation-bound shared agent credentials over the existing bearer
stack, compact API/webhook dialogs and agent key selection without losing drafts.
It removes revoked keys from normal lists, counts actual active project agents,
hides empty task AI surfaces and places assignment controls in the right column.
Private chat contains reports, files and inline long replies, with preserved legacy
history and explicit reviewed publication to the team or client. Developers can
edit the task brief before agreement, including rich text, with a server guard
against stale writes after client approval.

Contracts and compatibility: [API.md](API.md),
[OpenAPI](../milestone-9-6/openapi.json). Deployment/migration/rollback:
[PRODUCTION.md](PRODUCTION.md). Owner checklist:
[AGENT-LIVE-SMOKE-RU.md](../../AGENT-LIVE-SMOKE-RU.md).
Independent executor: [examples/collaboration.mjs](examples/collaboration.mjs).

Validation: 1056 Laravel tests / 15607 assertions on SQLite and MariaDB;
124 focused PHP 8.4 tests / 1703 assertions; 434 frontend tests. Full source browser
workflow passes with an independent deterministic Node executor, private file
authorization, signed real HTTPS delivery with 503 retry/deduplication and access
revocation, rich editing and shared-key revocation.

Detailed validation, model request accounting, visual checks and extracted-ZIP
evidence are in [QA.json](QA.json). Exact archive metadata is in
[RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json). Screenshots remain outside Git.
No push, deployment or production DB changes are part of this work.
