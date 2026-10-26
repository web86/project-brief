# Milestone 5.0.2 — deployment and rollback

## Install

1. Verify the ZIP's SHA-256 against source `docs/milestone-5-0-2/SHA256SUMS.txt`
   and its packaged source commit in `release.json`. Keep a backup of the current
   database, private files and matching public/private release. Preserve the real
   `.env`, APP_KEY, signing secrets and existing cron configuration.
2. Follow the private/public layout in DEPLOY-JINO.md. Pause the existing webhook
   cron and let accepted attempts finish. Deploy matching public assets and private
   app together during the usual short maintenance window.
3. In the private application directory:

   ```sh
   php artisan down
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan up
   ```

   There is **no new 5.0.2 migration**. On an up-to-date 5.0.1 installation migration
   is a no-op. Upgrades from older versions must apply bundled migrations normally,
   including the 5.0.1 project-filter composite FKs. See the inherited
   GUIDES/milestone-5-0-1/PRODUCTION.md in the package (or the sibling milestone
   guide in source) for schema details. Use MariaDB/InnoDB with FK enforcement.

4. Resume the unchanged cron:

   ```cron
   * * * * * cd /home/ACCOUNT/private/project-brief-app && /usr/bin/php artisan integrations:webhooks --limit=20 --seconds=45 >> storage/logs/webhook-cron.log 2>&1
   ```

No new service, environment key, dependency or CRM connector is required.

## Post-install smoke

Use disposable keys and a controlled receiver. Confirm owner-only Integrations in
“My settings”, single upper All projects link, distinct Team/Clients icons and
working mobile drawer navigation. Check RU/EN, light/dark/system, and 320px width.

Create a test key, copy it, revoke it and verify immediate card disappearance and
401 using the revoked bearer. Reload and confirm it remains absent; the database
row and last-use metadata must remain. Test failed/aborted requests and confirm an
error toast without a success toast or record removal. Field errors remain inline.

Create a selected-project webhook. Test/save/toggle/rotate/retry should use the
global notifications; delivery/attempt errors remain in history. Before deleting a
disposable endpoint, read its named permanent-history warning. Cancel first and
confirm history stays; then delete and confirm its endpoint, delivery/attempt rows
and selection pivot are gone, while another endpoint/shared event remains. Let an
in-flight call finish before accepting deletion as complete. Existing external
receiver effects cannot be recalled. Do not use business-critical endpoints for
this destructive smoke check. Local tests use fake outbound HTTP; real receiver,
TLS/DNS and MariaDB/Jino behavior require deployment-environment verification.

## Rollback

Pause cron, allow accepted attempts to finish, and restore the matching 5.0.1 private
app/public assets; clear caches and resume the existing cron. Keep the database
schema; this patch has no migration to roll back. Revoked keys remain revoked and
must be replaced to regain access. Deleted webhook history cannot be restored by
code rollback; use a deliberate database backup restoration if required, considering
all writes since that backup. A database rollback cannot undo accepted remote work.

For rollback to 5.0 or older, follow GUIDES/milestone-5-0-1/PRODUCTION.md first:
disable endpoints/cancel pending before an old sender can ignore selected-project
filters or new subscriptions. Never reset versions or replay old deliveries just
to force work through. Take a fresh backup before any data restoration.
