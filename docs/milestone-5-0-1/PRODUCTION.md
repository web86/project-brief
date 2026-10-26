# Milestone 5.0.1 — Jino migration and rollback

This is a local release only. No push, deployment, live Jino/CRM E2E or production
secret access is part of verification. Apply the usual private/public split from
`DEPLOY-JINO.md` and the inherited 5.0 operational requirements. Preserve `.env`,
APP_KEY, private files, current database and backups.

## Upgrade from 5.0

1. Back up database, private files and matching private app/public assets. Verify
   the archive SHA-256 against `RELEASE-ARTIFACT.json` / `SHA256SUMS.txt` in source.
2. Pause the existing `integrations:webhooks` cron and allow an in-flight attempt
   to finish. Check its lease/DB locks; do not kill a receiver call and assume it
   was never accepted. Deploy the complete matching application and frontend in a
   short maintenance window. Do not serve 5.0.1 code before migration completes.
3. From the private application directory, run the normal deployment commands:

   ```sh
   php artisan down
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan up
   ```

4. Resume the **same** cron command and schedule:

   ```cron
   * * * * * cd /home/ACCOUNT/private/project-brief-app && /usr/bin/php artisan integrations:webhooks --limit=20 --seconds=45 >> storage/logs/webhook-cron.log 2>&1
   ```

No extra service, queue worker, environment key, network handler or CRM connector is
needed. Existing HMAC verification and signing secrets remain valid.

`2026_10_26_081255_add_webhook_project_filters` adds a default-all mode column, an
endpoint/workspace unique index and `webhook_endpoint_projects` with composite FK
constraints to endpoint/workspace and project/workspace. Existing projects already
have a suitable id/workspace index. The migration preserves existing endpoint events,
secrets, keys, configuration versions, queued/delivered history and immutable event
payloads. All old endpoints match all workspace projects as before, including new
projects. No existing endpoint is opted in to comment events. No core data is deleted.
MariaDB/InnoDB supports these additive columns/indexes/FKs; index creation/ALTER may
briefly lock the small endpoint table. Run during the maintenance window with the
normal migration account and backups; SQLite checks are not a claim of a live Jino
MariaDB migration.

## Post-upgrade smoke

- Owner Integrations page loads; old endpoints show all projects, exact former
  subscriptions and unchanged secret/history. Owner/admin/client/guest separation
  and workspace switching still work. No webhook controls appear inside projects.
- Create an all endpoint, then a synthetic new project: it matches. For selected,
  choose at least two projects and confirm only these projects create deliveries.
  Empty selection and injected foreign UUIDs fail. Test delivery works independently
  of selection, with projectId=null.
- Editing mode/project set cancels all pending deliveries and increments version;
  old failed deliveries cannot be manually retried. Reordering the same UUID set
  should preserve version. The already running attempt completes before the update
  obtains its endpoint lock. Deleting a selected project cascades selection; an
  empty selected endpoint fails closed, and queued old events are cancelled.
- Explicitly select each new comment event only for a controlled receiver. Submit
  a synthetic client task comment and a general comment. Check comment UUID, taskId
  or null, actor client UUID, empty changes, origin ui and HMAC. Assert no text,
  names/email/filenames/private links. Repeat submissionId and verify one event.
  Admin comments create no new comment events.
- Verify ordinary mail/push/Telegram rules and unread counts still work. Webhook
  behavior is independent of notification preferences. Receivers must deduplicate
  IDs and tolerate retries/order as before.
- Confirm 320px UI, RU/EN, light/dark/system, searchable selection/count, error and
  empty states. Read logs for delivered/cancelled events; excluded-before-outbox
  projects intentionally have no delivery record.

Use synthetic comments, never confidential client text in an external integration
test. Actual receiver/TLS/DNS/CRM behavior must be checked by the deployment owner.

## Rollback: preserve filters and prevent broadened deliveries

1. Stop cron and incoming application writes; allow current delivery/transactions
   to finish. Take a new database backup including project selections and versions.
2. **Do not simply run the old 5.0 sender against selected endpoints.** Old code
   does not understand project filters or the new comment events and could deliver
   events from excluded projects. Before restoring 5.0, disable all webhook endpoints
   and cancel all pending deliveries under the normal endpoint locks (or disable
   each using the 5.0.1 owner UI while writes/cron are stopped). Preserve attempt,
   event and selection history and keep cron stopped initially.
3. Restore the previous matching private/public release and clear caches. Keep the
   new database column/table in place; the old core app can coexist with them. Keep
   affected endpoints disabled. Remove the two new event subscriptions before using
   any endpoint with 5.0. Re-enable only after explicitly accepting 5.0's **all-projects**
   semantics, or wait to re-upgrade to 5.0.1. API tokens remain workspace scoped.
4. Keep cancelled/old failed delivery IDs for deduplication evidence; do not reset
   old versions or queue records to force delivery. Already accepted remote effects
   cannot be undone by code/database rollback.
5. Permanent removal of only this migration is possible after backup: its down
   drops the selection table/index/column and preserves 5.0 endpoint/history tables.
   This **loses project restrictions**, so first disable endpoints/cancel pending
   and explicitly remove comment subscriptions as above. Do not broadly roll back
   unrelated migrations or drop the 5.0 outbox. Re-upgrade restores default all,
   not the deleted selection; restore selected UUIDs deliberately from your backup.
