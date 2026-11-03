# Dev2Done v0.9.14 — Phase 4A Jino update / rollback

Prepared instructions only. No deployment, push/merge, production migration, operator backfill or Jino connection was performed for this release. Phase 3 installation/backfill success is supplied by the operator.

**From installed v0.9.13: no new migrations and no mandatory operator command.** Phase 4A reuses previews/items/events and accounting schema. No new env keys, colors backfill, seeder, authority reassignment, subscription pin, Trial, downgrade or suspension activation is required. Configured `enforce` still has no commercial executor. Avoid running inventory simply because code was updated.

## Update under maintenance

1. Verify the ZIP SHA-256 sidecar, release.json exact source commit/version/build UTC/PHP8.4, checksums.txt and separate static artifact identity. Backup consistent DB/private uploads and current public/private code/vendor. Keep installed `.env`, APP_KEY, storage/uploads, session cookie, paths and permissions; no demo/QA credentials may be installed.
2. Enter maintenance (`php artisan down` in current private runtime), pause existing scheduled processors by the existing DEPLOY-JINO procedure. Replace public and `private/project-brief-app` together. Private runtime stays outside web root; restore retained `.env`/storage and permissions.
3. In the new PHP8.4 private runtime run `php artisan optimize:clear`. Inspect `php artisan migrate:status`: installed Phase 3 should have no pending migration. If upgrading an older installation, use the Phase 3 runbook for its **additive migration** `2026_11_02_220732_add_plan_inventory_accounting` and prerequisites, with a separate reviewed operator backfill. A normal `php artisan migrate --force --no-interaction` only applies pending schema; no new 4A schema exists. Never use migrate:fresh/rollback, reset/demo seed or automatic PlansFoundationSeeder on production.
4. Rebuild caches with `php artisan optimize`; bring up with `php artisan up` and resume existing processors. Confirm health, login/MFA/passkey/recovery, owner/client/team privacy, agreements/PDF/DOCX/downloads, shared agent credentials, full API and external SSO. `/demo` and `/api/demo/status` must remain404 in production.
5. Primary: inventory counts/compatibility remain consistent; owner: published public target → resource selection → freeze → recheck. No apply action. Confirm no new suspension cycles/blocked epochs or trial claims were created. Custom workspace colors persist; empty colors use #5b4bd6. Review action is an intentional accounting write and requires actual operator evidence/fresh security, never a deployment checklist auto-action.

Previously incomplete/stale accounting may need a separate explicit inventory refresh under the Phase 3 runbook; that is not a prerequisite for serving authorized core pages. Missing measurements show incomplete, not zero. Keep APP_KEY: accounting HMAC key rotation requires its own migration/review procedure.

Webhook receivers must dedupe the existing delivery UUID header. Network calls now occur after durable claim and before a separate completion transaction; a crash/DB failure can leave processing and later redeliver the same UUID (at-least-once). A concurrent revoke cannot unsend a request already in flight. Existing quiet cron remains nonzero/sanitized on operational failure and otherwise quiet.

Static Demo v3 installs independently on the separate static origin/web root. It contains no PHP/backend/database/env, preserves the exact prior JSON dataset, and sends no API/writes/external traffic. Do not upload its files over production or upload production PHP into the static origin. Keep old hashed assets when updating the static site; honor no-cache entry/manifest and immutable hashed assets. Follow docs/demo/static/HOSTING.md.

## Runtime rollback

Maintenance and pause processors; restore matching v0.9.13 public/private code/vendor together. Preserve the current DB schema, accounting observations, previews/audit/history, APP_KEY, env, uploads and user data. Clear/rebuild caches using the previous runtime, verify access, then resume. No destructive DB rollback or old DB backup overwrite over newer data. 4A never creates commercial holds/blocked epochs; an intentional accounting review remains recorded and should be investigated from audit rather than manually editing auth tables. Static v3 rollback uses the preserved v2 ZIP separately, with old hashed assets retained.
