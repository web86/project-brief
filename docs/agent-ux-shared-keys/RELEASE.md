# Project Brief v0.9.10 — Team, reviews and simpler agent setup

Final refinement of the local v0.9.10 candidate; branch
`codex/release-0-9-10-team-reviews`, based on `400e603`.

- One Team modal, maximum 900px, reuses existing assignment and email-invitation
  APIs. It closes and refreshes after success; the existing toast is retained.
- Owner-only workspace Reviews uses existing submitted feedback, including
  completed and archived projects. Date bounds are inclusive in the display
  timezone. Archived links open the existing recycle bin.
- Global personal settings reuse the project's collapsible navigation component.
- Agent form has one shared-key select, optional at creation, no kind chooser,
  permission grid or key-creation action. Active is the last field. New kind is
  `agent`; existing kinds remain unchanged.
- Credential scopes are the sole operation ceiling. Existing hashes/scopes,
  bearer namespaces, identity/binding, workspace, grant, membership, assignment
  and object/file checks remain. No automatic key creation or database migration.

See [API.md](API.md) and [PRODUCTION.md](PRODUCTION.md) for the scope-only policy
change and upgrade/rollback implications. In particular, review broad existing
keys which previously relied on restrictive registration flags before installing.

Available checks: 1059 Laravel tests / 15657 assertions on SQLite and MariaDB;
127 focused PHP 8.4 tests / 1753 assertions; 434 frontend tests; production Vite
build and Pint; source browser workflow with actual local SMTP delivery of a
synthetic invitation. No external AI requests are needed for this refinement.

Final package/visual evidence is recorded in [QA.json](QA.json), with exact ZIP
metadata in [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json). Screenshots stay outside
Git and the production package. No push, deployment or production DB changes.
