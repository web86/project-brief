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

| Check                                                  | Result                                                                                                                              |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- |
| Frontend                                               | 424 passed                                                                                                                          |
| Full Laravel / PHP 8.5.10 / SQLite                     | 1029 passed, 15251 assertions                                                                                                       |
| Full Laravel / PHP 8.5.10 / MariaDB 11.8.9             | 1029 passed, 15251 assertions                                                                                                       |
| Focused API/security/migrations / PHP 8.4.26 / MariaDB | 85 passed, 1239 assertions                                                                                                          |
| Source visual matrix                                   | RU/EN/TR/ZH-CN × 1440/390 × light/dark/system; 168 captures                                                                         |
| Formatter/build                                        | Pint, PHP lint, Prettier, production Vite build passed                                                                              |
| Canonical extracted runtime                            | REST/scopes/idempotency, SSO, files, invitations, platform isolation, migrations/maintenance, quiet cron and signed WebAuthn passed |

The real browser E2E covers explicit expanded reads, old tokens without new scopes, isolated public discussions/team chat, file parent checks, concurrent revocation, client API/download denial, human PR/text/file publication, UI key expiry/one-time secret/revocation and connection-required state. All previous independent Node executor, assignment generation, rotation/disable and real TLS/HMAC webhook retry/cancellation scenarios remain in the same harness. Requests retain the real API limits; phases pause to respect their windows.

The migration test upgrades an actual pre-permission schema with nonempty historical agent comments, report URLs, files, assignments, grants/members and tokens, asserting unchanged stored rows apart from the new nullable column. The browser harness also migrates its MariaDB schema from the pre-v0.9.8 state. Scope authorization is rechecked after owner control changes and before idempotency replay.

Screenshots remain outside Git under `/tmp/project-brief-m96-qa/`. Desktop/mobile, permission alignment, compact scopes, connection metadata, team spacing and publication dialogs were visually inspected. The specialized credential-revocation E2E also verifies that `pending`/terminal execution state is retained when a separate connection warning appears. No QA images or real secrets are committed.

[Jino update and safe rollback](PRODUCTION.md). A backup restore can restore formerly active token/agent/delivery flags too: reapply disable/revocation on the restored state and verify pending deliveries before reopening traffic. A plain v0.9.7 runtime rollback with active agents is unsafe. Production restore has not been attempted.

## Production ZIP

- [project-brief-v0.9.8-8e2795b.zip](../../release/project-brief-v0.9.8-8e2795b.zip), **39401396 bytes**.
- Source commit: `8e2795bc97ea13a4c9b9aab9089d922d44a731e5`. Subsequent evidence commits change only release reports.
- Built UTC: `2026-10-31T07:39:20.998Z`.
- SHA-256: `246d6c47c50ba03a82b9b38a5794f2fb619ccce488a1e6987ffdac99784350f0`.
- [SHA sidecar](../../release/project-brief-v0.9.8-8e2795b.zip.sha256), [artifact metadata](RELEASE-ARTIFACT.json).

Created only by `./bin/build-release`. Independently checked every ZIP entry CRC and all **8923** internal checksum records; the independent Node example exactly matches source and uses numeric Fetch status. Canonical validation passed production dependency/extension requirements and secret/runtime-artifact exclusions. Current minimal API contract and production guide are included under `agent-api/`, outside public web root; the UI does not use those files as its primary connection flow.

The final archive was extracted separately and its compiled UI/production vendor passed the full Agent API and privacy E2E on an exclusive MariaDB database. The independent Node process executed the exact example inside the ZIP. Authorized read scopes, concurrent revocation, private client API/download rejection, explicit reviewed PR/text/file copies, UI key revocation and separate connection/execution state all passed. The original complete executor/rotation/webhook regressions also passed. Sanitized machine-readable evidence: [QA.json](QA.json). All owned QA browser/backend processes and the disposable MariaDB container were stopped after testing. The extracted archive and screenshots remain local for review.

## Verification limits

No push, deployment, production DB changes, external AI provider or billing connection. Jino/FPM/TLS configuration, production load/backups, real SMTP/Telegram/Web Push delivery, physical devices and Safari/WebKit were not verified. All browser evidence is local Chromium emulation. The documentation/help stage and Milestone 10 remain separate work.
