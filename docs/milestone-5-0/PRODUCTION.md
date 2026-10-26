# Milestone 5.0 — deployment and rollback

Prepared locally; nothing was pushed, uploaded, installed or sent to a real CRM.
Use the normal private-app/public-root split in `DEPLOY-JINO.md`. The release ZIP
contains this milestone's code, production Composer dependencies, compiled frontend,
additive migration, this runbook, release notes and integration API documentation.
Keep existing `.env`, APP_KEY, private storage, sessions and user-uploaded files.

## Before installation

1. Back up the database, private files and current application/public directories.
   Record the current release commit and archive hash. Preserve APP_KEY, which also
   encrypts webhook signing secrets. Do not copy local test credentials or `.env`.
2. Verify the ZIP SHA-256 and unpack outside the live root. Check PHP 8.3+ with cURL,
   OpenSSL and the existing required extensions using `php bin/check-server` from
   the deployed private application. cURL must support CURLOPT_RESOLVE; no insecure
   HTTP transport fallback is provided. Confirm DNS resolution and HTTPS egress to
   your intended public receiver on port 443 from Jino. Private URLs, proxies,
   self-signed TLS and redirects are unsupported.
3. Make sure CLI cron uses the same PHP, database, APP_KEY and timezone configuration
   as the web application. Default app/database times should remain UTC. Confirm
   the host has a reliable DNS resolver with a reasonable system timeout. The HTTP
   timeout is bounded separately; DNS resolution uses PHP's host resolver.
4. Do not enable cron against old application code or run migrations with an old
   deployment's script. Stop existing release operations before switching files.

## Migration and rollout

From the private application directory, during a brief maintenance window:

```sh
php artisan down
php artisan migrate --force --no-interaction
php artisan optimize:clear
# Switch the complete private application and matching compiled public assets.
php artisan up
```

Follow the existing deployment process for ordering file switches and CLI scripts.
The additive `2026_10_26_065515_create_integration_tables` migration adds:
`integration_tokens`, `integration_requests`, `integration_project_links`,
`webhook_endpoints`, `webhook_events`, `webhook_deliveries`, `webhook_attempts`,
`integration_locks`. Existing projects/tasks/clients/memberships are unchanged.
Tables use foreign keys and portable MySQL/MariaDB/SQLite column types; identity
and idempotency hashes keep unique indexes short. No production secrets or external
services are needed for migration. The release runtime test installs/migrates a
fresh disposable SQLite database; SQLite feature tests also verify rollback/reapply
without deleting pre-existing application records. A real Jino MariaDB migration
and receiver reachability remain deployment smoke checks.

## Cron on shared hosting

Create a Jino cron job once per minute, with the actual PHP binary and private
application path. Example (replace both paths):

```cron
* * * * * cd /home/ACCOUNT/private/project-brief-app && /usr/bin/php artisan integrations:webhooks --limit=20 --seconds=45 >> storage/logs/webhook-cron.log 2>&1
```

No permanent queue worker, daemon or extra server is required. Cron output contains
only a count; it does not contain URLs, keys, payloads or receiver response bodies.
Use filesystem permissions and normal log rotation for cron output. The database
lease allows only one processor at a time across hosts sharing the same database;
a new attempt checks ownership and renews the lease under a row lock. Stale leases
expire after 90 seconds. Another cron invocation skips a live lease. An attempt
already running can finish after `--seconds`, and DNS can add host-resolver delay.
Start with the default limit; never overlap alternate custom webhook processors.

2xx succeeds; 429/5xx/connection failure retries with bounded backoff up to six
automatic attempts. Sender side effects are at least once. Configure receivers to
verify HMAC/timestamp and persist unique event IDs before applying effects. Retries
retain event ID, delivery ID and raw body. Manual retry retains old attempt history
and starts a fresh six-attempt budget. Failed destinations require owner attention;
there is no silent fallback URL or provider. UI test delivery is queued, not sent
synchronously. Verify the first actual cron result in delivery history.

## Smoke checklist after deployment

- `/api/health`, ordinary owner/admin/client login, task creation/edit/approval,
  project settings and old notifications still work.
- Only a workspace owner sees Integrations in desktop and mobile navigation.
  Direct management requests by assigned admins/clients/guests fail. Switch to a
  workspace where the same user is admin and confirm integrations access disappears.
- Create a minimal expiring token, save it once, close the dialog, reload and confirm
  the secret is absent. GET project list works without browser cookies. Expired and
  revoked tokens fail; estimate fields require explicit `estimates:read` + task read.
- Use synthetic project/client data in curl. Repeat the same idempotency key/body;
  verify no duplicate project/task. Change body with the same key; verify 409. Confirm
  section creation, numbering and task audit history. Confirm managerUrl is a login
  path, never a client access link. Check a second workspace UUID returns 404.
- Configure a controlled public HTTPS receiver and the one-time webhook secret.
  Queue a test, run cron once, verify HMAC over raw bytes, then see success/history.
  With a synthetic receiver, check 429 → retry → 2xx and event ID deduplication.
  Repeated approval/no-op status changes must not create duplicate business events.
- Disable the endpoint or rotate its secret; queued deliveries become cancelled.
  Updating URL or subscriptions also cancels queued deliveries. A delivery already
  accepted at the receiver cannot be recalled. Install the replacement secret on
  the receiver before enabling new events.
- Check light/dark/system and RU/EN at desktop and 320px width. Test secret copy,
  close, token revoke, delivery history pagination and manual failed retry.
- Check database storage/cron logs. Outbox/history and idempotency have no automatic
  retention cleanup in MVP; plan private backups/archival, keeping replay records
  as long as old idempotency keys may be retried. Monitor pending and failed counts.

Do not claim a CRM workflow is verified until the actual n8n/CRM receiver, signature
verification, credentials, pagination, deduplication and error recovery have been
exercised. Local tests use HTTP fakes, not real CRM endpoints.

## Rollback

1. Stop this cron job first. Disable endpoints and revoke integration tokens if
   external callers should stop; allow an in-flight attempt to finish or wait for
   the lease/DB lock. Preserve a database snapshot of event/delivery IDs.
2. Restore the prior matching private app/public assets and clear config/route/view
   caches. Existing core app tables/data are compatible with the previous release.
   Keep the new integration tables in place so rollback does not destroy replay,
   credential and delivery evidence. Old code does not emit new integration events.
3. Inform receiver operators of the delivery pause through your normal workflow.
   Already delivered events/CRM effects cannot be undone by a code rollback.
4. On re-upgrade, decide deliberately whether to continue pending deliveries or
   cancel them via disabling/rotating their endpoints before restarting cron.
   Reuse the same APP_KEY; changing it makes stored webhook secrets unreadable.
5. Only for deliberate permanent removal after backups and stopping all callers,
   the migration's `down` removes **only integration tables**, including all tokens,
   external links, replay records and event/delivery history. This destroys replay
   guarantees and can cause duplicate creates if old requests are later sent after
   reinstalling. Do not run a broad migrate:rollback on production as a code rollback.
