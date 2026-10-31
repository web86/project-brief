# Project Brief v0.9.7 — Jino update and rollback

This UX patch updates installed v0.9.6. Deployment requires separate authorization. Local QA does not verify Jino/FPM, production backups, TLS, cron capacity or physical devices. Hosting requirements remain in DEPLOY-JINO.md.

## Update from v0.9.6

1. Verify the published ZIP SHA-256 and CRC. Keep the matching v0.9.6 private/public runtime and a coordinated DB/private-storage backup, original `.env` and APP_KEY. Test restore in isolated staging with external delivery disabled.
2. Enter maintenance with `php artisan down`, wait for requests to finish and replace matching private/public files with v0.9.7. Preserve `.env`, APP_KEY, storage and file permissions; do not copy QA fixtures. No new schema migration is introduced by this patch.
3. Run the normal `php artisan migrate --force --no-interaction` check (no pending migrations expected from v0.9.6), then `php artisan optimize:clear` and the existing production cache commands. Retain the bounded `integrations:webhooks` cron. No new daemon, polling, provider or additional permissions are introduced.
4. On staging, verify five integration tabs, all executor types, searchable explicit owner grants, save/error/dirty-dialog behavior, team and task assignment, latest report and links. Run the supplied `agent-api/examples/agent.mjs` externally with a staging token. Verify token revocation, registration disable and denied foreign/unassigned tasks. No task approval or workflow status may change from an agent report.
5. Verify RU/EN/TR/ZH-CN, light/dark/system, desktop/mobile, modal keyboard/focus and visible Save/Cancel actions. Confirm the existing public agent-comment behavior: authorized clients can read reports/result links and download their attachments. This patch does not make technical results private. Do not put secrets into reports, comments or result URLs.
6. Verify existing API keys, SSO, signed webhooks, human team access, private team chat and downloads. After separately approved deployment smoke checks, run `php artisan up` and refresh browser/PWA assets.

## Rollback to v0.9.6

This patch changes UI and release documentation only; no production API, scopes or schema changes. Enter maintenance, preserve current DB/storage/.env/APP_KEY, restore the matching verified v0.9.6 private/public runtime, clear/rebuild caches and repeat isolation, task/report/download and authentication smoke checks before `up`. Do not restore an older DB merely to undo this UI patch: retain new work and agent audit data.

Do not use `migrate:rollback`, drop agent tables or downgrade to pre-v0.9.6 runtime on the agent schema. For an older coordinated downgrade use the v0.9.6 production guide in `agent-api/PRODUCTION.md` and a separately reviewed backup restoration plan. Production rollback/restore has not been executed as part of local release preparation.
