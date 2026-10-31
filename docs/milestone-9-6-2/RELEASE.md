# Milestone 9.6.2 — Project Brief v0.9.8

Prepared locally on the v0.9.7 base, branch `codex/milestone-9-6-2-agent-privacy`. No push, deployment or production DB changes.

## Permissions and old credentials

Effective access is the intersection of active registration, valid token scopes, current agent permission ceiling, explicit owner project grant, project membership and exact object access. Original `agent:tasks:read` remains assignment-only. Five new read scopes provide explicitly enabled project task, comment, client discussion, team chat and attachment reads. All six expanded registration read flags default off. Task/discussion file permission alone never authorizes its parent, a private channel or the whole project storage. Lists paginate at 25 and omit author contacts, financial/private task fields and storage paths.

Existing writes use their original scopes and current registration ceilings. Disabling a permission immediately blocks old tokens and idempotent write replay. The existing workspace lock serializes agent requests against control changes. New scopes are never silently added to old tokens; enabling a permission requires explicitly issuing an appropriately scoped key. Token creation, rotation and revocation remain separate owner actions. Project superadministrators retain only assigned-project authority.

## Result visibility and publication

New agent comments, reports and files use the existing `team_only` boundary. Authorized project team members can read them; clients, client exports/API/live notifications, generic comment integrations and client file routes cannot. Internal task results also work in the team's task discussion/read tracking, while project team chat remains a separate channel. Read access to internal task comments/files by another external agent requires explicit team-chat permission/scope in addition to the task/comment/file parent permissions.

Historical comments retain their stored visibility. The additive migration adds only nullable JSON permissions and does not reclassify or rewrite existing comments, files, report URLs, authorship, history, membership, grants or token scopes. Historical registrations resolve null permissions to the original baseline.

“Share with client” requires the existing project structure authority (owner or authorized assigned developer/superadministrator). The dialog shows the source for review and starts with blank client text and no selected links/files. Publication creates a separate normal human message; explicitly selected attachments get new IDs and independent private-storage copies. The source remains internal. A submission UUID/fingerprint prevents duplicate publication on retry; foreign or unrelated private files cannot be selected.

## UI and compatibility

Agent editing keeps an 880px desktop dialog and fixed header/actions within 90dvh. Searchable project grants remain separate from team membership. Collapsible permissions include the sensitive team-chat warning and enabled-extra count. Connection/API keys appears on create/edit, explains external execution, and offers direct key issuance after save. Key metadata includes expiry, scopes, last use and valid count; the secret is shown once. Registration, API access and execution remain distinct; an assignment without usable credentials shows “Connection required” without changing API `pending`.

Team section/card/action spacing now follows the existing 16px grouping. Permission checkbox alignment and compact desktop scope columns remove excess height; mobile remains one column. Internal results have a lock marker and authorized publication action. The connection hint no longer sends owners searching for files in a production ZIP. The Node example uses the Fetch `response.status` property on errors, with a regression test.

Only the existing technical [OpenAPI contract](../milestone-9-6/openapi.json), [minimal API notes](../milestone-9-6/AGENT-API.md) and security notes were updated. No provider-specific help system, new integration wizard, AI provider, billing or SaaS entitlement work is included.

## Verification and artifact

Final suite results, archive SHA-256, extracted-ZIP E2E and sanitized QA metadata are recorded here after packaging. [Jino update and safe rollback](PRODUCTION.md).
