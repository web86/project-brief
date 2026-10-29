# Milestone 8.1 / Project Brief 0.8.2 — Jino deployment and rollback

Prepared locally from completed Client Entry UX0.8.1. No push, merge or deployment was performed. Local SQLite/MariaDB/SMTP evidence is not Jino preflight or actual inbox delivery.

## Before updating

Verify exact archive bytes/SHA-256 against RELEASE-ARTIFACT.json/SHA256SUMS.txt and ZIP/internal checksum integrity. Rehearse on private Jino PHP8.4/MariaDB staging. Back up database, public/private/vendor, actual `.env`, APP_KEY, working storage/uploads/branding/sessions, deployed `.htaccess` and scheduler settings. Preserve independent recovery/rollback access. Do not replace origin/RP ID, MFA/passkeys, session policy or host security flags; HTTP_BOOTSTRAP_LIMIT_ENABLED remains disabled pending Jino proxy/NAT preflight.

Pause external form/provisioning/CRM calls, writes and the existing `integrations:webhooks` schedule, drain requests, then enter maintenance in the private app:

```sh
php artisan down
```

Replace the full public/private/vendor set from the ZIP, preserving `.env`, APP_KEY, working storage and maintenance marker. Follow DEPLOY-JINO.md. Never use first-install, seed, migrate:fresh, db:wipe, key:generate or account recovery for an existing installation.

## Migration

The existing foundation `2026_10_29_092022_add_project_provisioning_foundation` must be complete. One new additive migration:

`2026_10_29_123816_create_project_requests_foundation`

It creates `integration_sources` and `project_requests`, adds optional token/source identity, adds a nullable request link and permits null project_id in existing notification deliveries, and extends existing operation history with type/request UUID (legacy entries default to direct provisioning). It does not upgrade existing token scopes, create projects/clients/access for pending requests or change old notification data. Source and request FKs retain audit records. MariaDB DDL implicitly commits: take the backup first and inspect actual schema/migration status after any failure; do not rerun blindly against partially applied DDL.

```sh
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
php artisan migrate:status --no-interaction
php artisan account:security-diagnostics
```

No new dependency, Redis, WebSocket, queue worker, cron job or service. Resume the existing webhook cron only after the new code and migration are coherent. Live Sync uses the existing journal/coordinator.

## Staging smoke

- Existing owners/developers, projects, client links, 30-minute repeat login, TOTP/recovery and optional developer Passkeys work. Existing scoped tokens behave exactly as issued.
- Owner sees Requests beside Projects; non-owners get no navigation or backend read/decision access. Check all4locales, themes, desktop/mobile.
- Create a new source and create-only token; no new scope is selected/granted automatically on old tokens. Submit a synthetic request via the standalone server PHP helper. Pending count updates through existing Live Sync; enabled owner notification channels receive one notification.
- Pending creates no project/contact/membership/access. Repeat the same externalId/body/key, then a new key and replacement token of the same source: same request, no repeat event/notification. Changed body is409. Foreign workspace reads/decisions are404; missing scopes are403; revoked token is401.
- Owner approves with invitation enabled: one project/contact or reused contact/membership, approved request, correct permanent URL and initial7-day email with Open project. Confirm invitation skips email entry, repeat link access and public30-minute sign-in.
- Approve without mail: no link issued; canonical URL requires existing mailbox proof. Reject with optional reason: final state, no access. Repeat same decision recovers; approve/reject conflicts are409. Verify concurrent UI/API approval does not duplicate projects.
- Simulated SMTP failure preserves approved project and reports failed; automatic replay never resends failed/uncertain mail. Existing explicit project Clients resend updates invitation state.
- Subscribe to request events with all-project filter, verify HMAC/retry with a trusted endpoint, confirm no contact/title/description/reason/login secrets. Selected-project subscriptions still behave as before and exclude all request events. Operation history distinguishes requests/direct provisioning and contains no request/contact body.
- Preserve previous desktop-gap and client first-task/first-section fixes, exports, discussions, task permissions and Live Sync drafts.

After smoke and explicit deployment authorization, `php artisan up`; resume existing scheduler and external calls. Refresh clients to load new assets. This document does not authorize deployment.

## Rollback

Pause writes/external calls and the webhook scheduler, enter maintenance, restore the exact completed0.8.1 public/private/vendor set while preserving `.env`, APP_KEY, storage/sessions, branding, `.htaccess` and maintenance marker. Keep the additive0.8.2 schema/data. Clear/rebuild caches with old code, smoke existing projects/client/MFA flows, then reopen HTTP only after review.

Do not run general migrate:rollback or drop request/source/decision/deduplication records. The migration refuses destructive down once a source/request exists. Approved projects and clients are normal existing business records and must be retained. Requests UI/API is unavailable under old code. **Keep external request callers and `integrations:webhooks` paused while old code runs:** the old processor does not understand request events without a project and could cancel pending deliveries. Preserve the outbox for forward upgrade/recovery. Database backup restoration is a separate reviewed recovery requiring all writes paused and a decision about new business data; it is not a default application rollback.
