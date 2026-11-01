# Project Brief v0.9.8 — Jino update and rollback

Update from installed v0.9.7. This guide prepares a separately authorized deployment; no production access, push, deployment or DB change is part of local release QA. Existing PHP 8.4/extensions, private/public layout, backups, storage, HTTPS and bounded cron requirements remain in DEPLOY-JINO.md.

## Update

1. Verify ZIP CRC and SHA-256. Back up the matching v0.9.7 runtime, DB, private storage, `.env` and APP_KEY; test a coordinated restore on isolated staging. Disable external deliveries in staging and use distinct keys/sessions.
2. Enter maintenance with `php artisan down`, let requests finish, and replace matching private/public runtime files with v0.9.8. Preserve `.env`, APP_KEY, storage and permissions. Do not copy tests or QA credentials.
3. Run `php artisan migrate --force --no-interaction`. Migration `2026_10_31_065854_add_permissions_to_external_agents` adds only nullable JSON `external_agents.permissions`. It does not update comments, files, reports, history, grants, membership or token scopes. Null historical values resolve to original assigned-task/write capabilities; all six expanded read flags resolve to false. Assess ALTER/locking time against a staging copy of the actual Jino table.
4. Run `php artisan optimize:clear` and existing production cache commands. Keep the existing bounded `integrations:webhooks` cron; no new scheduler, daemon or provider is added.
5. On staging, verify owner-only settings, unchanged superadministrator project boundaries, old tokens without new scopes, explicit permission+scope reads, independent project grants/membership and immediate revocation. Run the independent example externally with a staging key. Check token expiry/create/rotation/revocation without automatic scope expansion.
6. Verify new technical comments/reports/files are internal to the assigned project team. Client task/discussion API, exports, live notifications, generic integrations and file download routes must exclude the originals. Publish a reviewed human copy with no default selected links/files, then verify only selected data becomes client-visible and source visibility remains unchanged. Historical public results remain public; this update does not retroactively hide them.
7. Smoke-check RU/EN/TR/ZH-CN, 1440/390, light/dark/system, fixed modal actions, permissions/connection sections, team spacing, task state and one-time secrets. Verify existing SSO, human security, generic API, private chat and webhooks before separately approved `php artisan up`. Refresh old browser/PWA tabs.

## Rollback

Prefer a compatible v0.9.8 forward fix, preserving current DB, storage, permissions and token state. Enter maintenance, stop external deliveries, back up current state, validate the fix on a copy, replace matching private/public files and clear caches before smoke checks and `up`.

**A runtime-only downgrade to v0.9.7 with active agents is unsafe:** that version does not enforce registration permission ceilings and creates public agent output. Do not run `migrate:rollback` or drop the permission column; the migration deliberately refuses `down` to avoid erasing security decisions. Older agent migrations also have rollback restrictions.

If a coordinated downgrade is unavoidable, obtain separate owner approval for the exact recovery point and loss of post-backup changes. While v0.9.8 is still available, disable agents/revoke credentials and stop webhook delivery; preserve the current DB/storage separately. Restore the reviewed pre-v0.9.8 DB + matching private storage + verified v0.9.7 runtime + original APP_KEY as a coordinated unit. Keep external agents disabled until their permissions and result visibility are reviewed for that version. Check isolation, file authorization, sessions/MFA/SSO and queued deliveries before reopening traffic. Actual production restore has not been performed in this release task.

Local MariaDB/PHP/browser results do not establish Jino/FPM load, production backup recoverability, real SMTP/Telegram/Web Push delivery, hardware MFA or physical mobile/Safari behavior.
