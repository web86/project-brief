# Milestone 5.0.3 — Jino deployment, smoke and rollback

## Upgrade

Verify ZIP SHA-256/source commit against source RELEASE-ARTIFACT.json/SHA256SUMS.txt.
Back up the database, private files and matching public/private application; preserve
`.env`, APP_KEY and all real signing secrets. Use DEPLOY-JINO.md's split layout.
Pause cron and allow accepted attempts to finish before the normal maintenance
window. Install matching private code/vendor and public assets, then run:

```sh
php artisan down
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan up
```

There is **no new 5.0.3 migration** or environment variable/service. Existing 5.0.1
filter and prior attachment/comment migrations must already be applied (the bundled
migrate command applies missing ones). Verify on actual MariaDB/InnoDB/Jino; local
SQLite tests are not a claim of a live Jino migration or receiver E2E.

## Error-only cron and rotation

Keep the same minute schedule, using your actual private path and PHP binary:

```cron
* * * * * umask 077; cd /home/ACCOUNT/private/project-brief-app && /usr/bin/php artisan integrations:webhooks --limit=20 --seconds=45 >> storage/logs/webhook-cron.log 2>&1
```

Default mode has no routine stdout or stderr. Actual attempt/internal failures write
only sanitized stderr diagnostics and return 1. Do **not** add `--quiet`, `2>/dev/null`
or `|| true`; those hide useful failures. Keep PHP's normal error reporting and Jino's
failed-job notifications enabled. Errors contain timestamp/delivery UUID/attempt/
status/fixed code/HTTP status, never receiver URLs, bodies or secrets. `internal_error`
means inspect DB availability, FK/schema, disk and PHP configuration locally without
publishing secrets. Startup/OS errors remain detectable through nonzero exit/stderr.

For manual statistics only:

```sh
php artisan integrations:webhooks --limit=20 --seconds=45 --verbose
printf 'exit=%s\n' "$?"
```

Backoff means a failed endpoint logs once per actual attempt, not every cron minute;
up to six attempts per delivery. Watch disk usage. Rotate weekly or above 5 MiB,
keep four compressed archives with private permissions. If Jino offers user log
rotation, configure weekly/rotate 4/compress/missingok/notifempty (and copytruncate).
Otherwise, between runs with cron briefly paused, copy the log to a dated private
archive, truncate the active file, gzip the archive and remove archives older than
your retention policy. Resume the same cron. Never delete delivery DB history to
reduce log size. No permanent worker/daemon is needed.

## Jino smoke: text from webhook ID and protected files

Use a disposable project, synthetic comments/files and a controlled receiver.
Keep credentials private and avoid dumping actual comment/file contents to shared logs.

1. In the active workspace owner UI create a token with `comments:read`. Confirm
   the RU/EN privacy warning. Existing tokens must not gain this permission. Read
   `/api/v1/projects/PROJECT_UUID/comments/COMMENT_UUID` using projectId/commentId
   from an existing verified client-comment webhook. Confirm text, taskId/null and
   only safe attachment metadata; webhook itself still has no text/files. A token
   lacking comments:read gets 403; foreign project/comment gets 404; revoked token 401.
2. Create a replacement token with `comments:read`, `attachments:read`, `tasks:read`.
   Check all three new attachment routes from README. Direct task list excludes
   comment files. Metadata is only id/name/mimeType/size. Download must return bytes
   as Content-Disposition attachment with no-store/nosniff and no public redirect.
3. Remove attachments:read from a replacement token: download/list is 403. A token
   with attachments:read alone also gets 403 on an existing file. Task files require
   tasks:read; task/general comment files require comments:read. Foreign workspace,
   deleted task/comment, incorrect parent IDs and missing disk files are 404.
   Browser client/admin cookies alone cannot authorize v1; bearer is required.
4. Confirm perPage limits and cursor continuation with unchanged filters; create a
   newer comment between pages and confirm it appears only in a fresh traversal.
   Soft-delete a disposable task and verify its comments/files become inaccessible;
   restore only through the existing owner UI if needed.

## Jino smoke: actual log growth

Run with no concurrent manual/cron processor and use only the controlled receiver:

1. Note active log size (`wc -c < storage/logs/webhook-cron.log`, or 0 if absent).
   With an empty queue, run the exact default cron command redirected to that log.
   Check `$?` is 0 and size unchanged. Repeat with a held lease/no due work.
2. Queue a test webhook to a controlled 204 receiver; run default cron. Confirm
   succeeded history and attempt count, exit 0, unchanged log size. Repeat for a
   successful retry after its scheduled backoff/due time.
3. Use a disposable endpoint whose controlled receiver returns 503 (or 400), queue
   a test and run default cron. Confirm nonzero exit, one sanitized error line per
   failed attempt, and preserved retry/failed history. Confirm another healthy
   endpoint still succeeds. Do not deliberately break a business endpoint.
4. Run again before available_at: no output/new log growth. Once due, the next
   actual failure may add a line. Fix the receiver, retry through existing controls
   or wait until due, then confirm success is silent. A `-v` manual run may print
   statistics; do not put that flag in scheduled cron.
5. Revoke/discard disposable keys and delete only disposable endpoints after their
   permanent-history confirmation. Check log permissions/rotation and resume cron.

## Rollback

Pause cron, finish accepted attempts, restore matching 5.0.2 private/public release
and clear caches. Keep the DB schema and revoked-token state; no migration rollback
is needed. New read routes/scopes become unavailable, so stop CRM readers or issue
appropriate replacement tokens; do not broaden old tokens. The previous cron prints
routine counters again, so expect log growth until re-upgrade. Existing endpoint
filters, keys and deliveries remain compatible. Follow the inherited 5.0.1 rollback
rules before any older 5.0 sender can ignore project filters. Code/data restoration
cannot recall accepted remote effects or reconstruct deleted history without backup.
