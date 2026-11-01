# Milestone 9.9 — Agent Collaboration, Unified API Credentials & Task Editor UX

The supplied 10.1 collaboration brief is implemented first as v0.9.9 on v0.9.8,
as requested. Plans/subscriptions remain the separate Milestone 10 architecture;
no plan enforcement, payments, Network or production changes are introduced.

New grants automatically add project membership; unchanged settings do not restore
an intentionally removed agent. API keys now have one UI with separate immutable
integration/agent identities and existing security ceilings. Agent/project/run
instructions and private run-bound dialogue support repeated execution with
immutable snapshots, claim/idempotency checks, history and human publication.
The task editor reuses agreement editing/rendering, adds server HTML sanitization
and rich PDF/DOCX output; AI improvement starts directly but still requires explicit
proposal application and task save.

Technical contracts: [API.md](API.md) and the updated
[OpenAPI](../milestone-9-6/openapi.json). Migration/deployment/rollback:
[PRODUCTION.md](PRODUCTION.md). Standalone executor:
[examples/collaboration.mjs](examples/collaboration.mjs).

Final validation and exact artifact metadata are recorded in QA.json and
RELEASE-ARTIFACT.json after release checks. QA screenshots remain local outside Git.
No push, deployment or production DB change is authorized by this release work.
