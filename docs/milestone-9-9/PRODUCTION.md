# Project Brief v0.9.9 — Jino update and rollback

Prepared for a separately authorized deployment from v0.9.8. Push, deployment and
production DB changes are not part of this milestone. Use DEPLOY-JINO.md for the
existing PHP 8.4/extensions, HTTPS, APP_KEY, private/public layout and backup rules.

## Additive migration

`2026_11_01_074535_add_agent_collaboration_and_task_formatting.php` adds:

- external_agents.instructions and projects.agent_instructions;
- tasks.description_html / description_html_hash (nullable; no legacy conversion);
- agent_runs with immutable context/instruction, initiator, state/timestamps,
  result pointer and assignment/request-key uniqueness;
- agent_messages with run-bound private authorship and optional private comment;
- nullable agent_reports.agent_run_id, preserving all old reports.

There is no automatic grant/membership reconciliation or token scope expansion.
Existing credentials remain valid for their original operations. Instructions,
dialogue and run operations require newly issued explicit scopes. An old executor
can finish initial work; use the collaboration example for repeated runId-bound work.

## Deployment sequence

1. Verify the exact archive SHA-256/manifest and test the populated v0.9.8→v0.9.9
   upgrade on a disposable copy. Preserve a recoverable DB+private storage backup
   with APP_KEY and matching runtime; confirm available disk space/extensions.
2. Enter maintenance with `php artisan down`, stop the owned webhook cron briefly and let active requests
   finish. Replace matching private/public runtime files together, preserving
   .env, APP_KEY, storage and permissions. Do not upload tests or QA credentials.
3. From the private application directory run
   `php artisan migrate --force --no-interaction`, then `php artisan optimize:clear`
   and the existing production cache commands from DEPLOY-JINO.md. This additive migration checks existing columns/tables, but
   MariaDB DDL may implicitly commit and wait for metadata locks: inspect any
   interrupted update and foreign-key/index state before resuming.
4. Check sessions/MFA, workspace isolation, old credentials, agent membership,
   private initial/follow-up runs, public client comments/files and plain/rich task
   save/export. New run fields do not change human task status or approval.
5. Resume the existing bounded webhook cron. No daemon/LLM process runs on Jino.
   Keep provider credentials only on the independent executor machine.
6. Run `php artisan up` only after the smoke checks and separately authorized review.

## Rollback

Prefer a v0.9.9 forward fix. Retain the additive schema and all run/message history;
`down()` deliberately refuses destructive rollback. Stop new collaboration runs
and external delivery during investigation; cancel ambiguous claimed work rather
than re-executing it automatically.

A runtime-only downgrade to v0.9.8 disables the new collaboration API/UI and grant
automation. New scopes/routes are unavailable; old compatible token scopes still
work. The old runtime displays canonical plain descriptions and keeps private
agent result comments private. New dialogue tables remain inaccessible to its
public APIs. If old code edits plain task text, v0.9.9's source hash suppresses
stale formatting on re-upgrade. Do not delete columns/tables to make old code run.

A coordinated backup restore is a separate decision: preserve current state,
review the recovery point/loss of later changes, then restore matching DB/private
storage/runtime/original APP_KEY as one unit. Actual production recovery, Jino/FPM
load, real SMTP/Telegram delivery and production backup recoverability are not
established by local tests.
