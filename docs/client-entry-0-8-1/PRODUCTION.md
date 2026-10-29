# Project Brief 0.8.1 — Client Entry UX: Jino update and rollback

Base installation: 0.8.0. Push, merge and production deployment were not performed by this release preparation.

## Preparation

Verify the ZIP SHA-256, byte size, CRC and internal `checksums.txt` against the external release report. Back up the database, public/private/vendor, `.env`, APP_KEY, working storage/uploads/branding/sessions, deployed `.htaccess` and current scheduler configuration. Keep independent rollback access. Do not change HTTPS origin/RP ID, MFA/passkeys, cookies or existing HTTP hardening flags; host-dependent bootstrap limits remain disabled until the separate Jino proxy/NAT preflight.

Rehearse on private staging using the actual Jino PHP 8.4/MariaDB configuration. Local PHP/SQLite, browser and SMTP evidence does not verify the host or delivery to a real inbox.

## Update

Pause external provisioning calls and existing scheduler/writes, drain active requests and enter maintenance in the private app:

```sh
php artisan down
```

Replace public/private/vendor with the complete ZIP version while preserving `.env`, APP_KEY, working storage and the maintenance marker. Follow `DEPLOY-JINO.md`; do not run `first-install`, seed, `migrate:fresh`, `db:wipe`, `key:generate` or security recovery on an existing installation.

```sh
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
php artisan migrate:status --no-interaction
php artisan account:security-diagnostics
```

**No new migration in 0.8.1.** The existing 0.8.0 foundation migration `2026_10_29_092022_add_project_provisioning_foundation` must already be complete. No dependency versions, credentials, scopes, request schema, membership rules, cron jobs or background processes change in 0.8.1. Keep the current `integrations:webhooks` schedule.

## Smoke

- Initial page: centered compact form with the existing site branding/theme, heading, email and Get a sign-in link action. Check RU/EN/TR/ZH-CN on desktop/mobile and both themes.
- Submit a valid-format email: the form and its initial heading/hint disappear; the mail icon, Check your email heading and conditional message appear. Unknown email has the same presentation. Another email returns to the form with focus and does not send a new request by itself. Repeated activation while pending yields one submission; 429/network errors leave a usable form without revealing membership.
- Provision a test order with `sendInvitation:true`: the existing 7-day invitation email has an Open project button. Following it goes straight to the authorized project with no extra email screen. Repeat access, expired/revoked links and another project's session must respect the existing checks.
- With `sendInvitation:false`, no initial letter/token is created. Opening the returned `projectUrl` as a guest allows a 30-minute login request; its email says 30 minutes, not 7 days. Repeat login and direct redirect still work.
- The selector in Integrations supplies the existing JSON boolean for the external system. It does not save a token policy or change an already provisioned order. Keep identical retry payloads; a changed boolean for an existing binding still returns 409.
- Check client mail branding and both HTML/text variants; other account/security emails retain their previous default button labels. Verify actual SMTP/inbox and external webhook delivery on the agreed staging environment.
- Confirm the 0.8.0 fixes still work: no large desktop gap; a client creates the first task and section in the existing task form. Existing API, projects, roles, exports, Live Sync, invitations and developer MFA/passkeys remain functional.

After smoke and authorization, run `php artisan up`, resume the existing scheduler/external calls and refresh open tabs to load new assets. Preserve client sessions and existing links.

## Rollback

Pause writes/external calls and enter maintenance. Restore the exact 0.8.0 public/private/vendor set, preserving `.env`, APP_KEY, working storage, `.htaccess`, sessions and maintenance marker. Clear/rebuild caches with the old code, smoke and resume the existing scheduler/HTTP.

There is no 0.8.1 schema to roll back; do not run a general `migrate:rollback`. Existing tokens and memberships are compatible. Emails already sent retain their actual expirations. Old code uses the previous invitation wording for public login emails; its 30-minute token lifetime remains unchanged. No database restore is needed solely to roll back this UI/mail change.
