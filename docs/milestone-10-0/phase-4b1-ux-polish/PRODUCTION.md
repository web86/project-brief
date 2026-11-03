# Dev2Done v0.9.16 — Jino UX update and rollback

Prepared instructions only. No deployment, Jino connection, production migration, public activation, push or merge was performed.

From installed v0.9.15: **no new schema migration, mandatory env key or operator backfill**. The prior **additive migration** `2026_11_03_081230_add_account_onboarding` remains required when upgrading an earlier base. Preserve the existing APP_KEY, `.env`, uploads/storage, sessions, database and custom branding. Do not seed or publish plans/policies, assign authority, run inventory or reset users for this UX update.

## Update under maintenance

1. Verify ZIP SHA-256/sidecar, release.json commit/version/UTC/PHP8.4 and checksums.txt against ARTIFACTS.md. Back up a consistent DB/private uploads and matching public/private code/vendor. Retain the installed environment and permissions; private/project-brief-app remains outside the web root.
2. Enter maintenance (`php artisan down`), pause existing processors using DEPLOY-JINO.md. Replace public and private runtime/vendor together. Restore retained environment/storage. Keep APP_ENV=production, APP_DEBUG=false, secure cookies/canonical HTTPS origin, `REGISTRATION_ENABLED=false`, `TRIAL_ACTIVATION_ENABLED=false`. Provider and Passkey origin/RP configuration is unchanged.
3. Run `php artisan optimize:clear`, inspect `php artisan migrate:status`; an installed v0.9.15 has no new pending migration. Earlier installations follow the Phase4B.1 runbook and apply only pending additive migrations with `php artisan migrate --force --no-interaction`. Never run migrate:fresh, migrate:rollback, demo reset or an automatic plan seeder on production.
4. Rebuild caches (`php artisan optimize`), bring up (`php artisan up`) and resume existing processors. Verify legacy owner login/MFA/passkey/recovery, invited developer/client/SSO, files/exports/API and custom branding under the existing deployment checklist.
5. Confirm public registration and Trial activation remain false. Closed `/register` must have no signup form. `/demo` and `/api/demo/status` remain404 on production. Primary registration diagnostics retain the existing PlanAuthority/fresh-MFA boundary.
6. On approved isolated staging, verify pending email → TOTP only → recovery only → server-confirmed completion. Reload and returning login must not regenerate codes or repeat completed steps. A prepared owner can open `/admin/account/security` and manage their own factors with existing fresh-MFA checks, while `/api/admin/me`, projects, workspaces, teams, notifications, integrations and exports remain denied by onboarding. Trial dates/claims must not change.

Hosting callback log redaction and provider setup remain as described in Phase4B.1 OAUTH-SETUP/SECURITY. No new cron, Redis, queue or worker is required. Real provider/SMTP/Jino checks are operator acceptance tasks, not claims made by local QA.

## Rollback

Enter maintenance, pause processors and restore matching v0.9.15 public/private code/vendor. Keep current schema, env/APP_KEY, user/factor/identity/claim data, audit and uploads; clear/rebuild caches, verify access, then resume. No DB rollback, recovery-code reset or old backup overwrite is needed. Factor/password/email changes performed through settings remain valid. v0.9.15 restores its previous inline settings UI and callback destination; workspace access remains gated.

Rolling farther back than v0.9.15 must follow Phase4B.1's special quarantine requirement for any self-service onboarding accounts: older code lacks that gate. Never expose such accounts through an earlier runtime without a reviewed plan.

Static Demo v4 deploys independently on its separate static origin/web root. Keep old hashed assets, no-cache entry/manifests and immutable hashed assets. It contains no PHP/database/auth server and retains the previous dataset. To roll it back, use preserved v3 files/ZIP independently; do not mix production PHP with static hosting. Follow docs/demo/static/HOSTING.md.
