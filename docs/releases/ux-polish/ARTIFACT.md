# Production artifact — v0.9.11

- Archive: `release/project-brief-v0.9.11-5685ea2.zip` (39,511,332 bytes).
- SHA-256: `a443e9af95e1cc77dcc2308ed02b42aa2a81240fa851ab69f106d3f07fc5ffb3`.
- Immutable application source: `5685ea2efadc419aef2c9c61132ef8b416600420`.
- Built: `2026-11-02T15:54:45.434Z` by the existing `./bin/build-release`.
- Layout: `public/` + `private/project-brief-app/`, locked no-dev vendor, `release.json`, `checksums.txt`, deployment/rollback instructions.
- ZIP integrity, extracted file checksums, permissions and secret/content exclusion: passed (8,969 validated files).

## Verification

- Full PHP 8.4.26 / MariaDB 11.8.9 regression: **1,107 tests, 16,263 assertions**, no skips/failures. Isolated QA schemas only.
- Frontend: **441/441 tests**, brand asset verification and normal Vite build passed. Demo entry chunk absent.
- Standard builder also ran PHP 8.5.10 / SQLite: 1,107 tests, 1,104 passed, three MariaDB-specific tests skipped; no failures. This does not replace the complete PHP 8.4/MariaDB run above.
- Actual extracted archive, PHP 8.4.26: signed WebAuthn enrollment/replay and recovery lifecycle; production-vendor plans/idempotent explicit initial seed/immutable publication; ordinary HTTP runtime/integration/SSO/provisioning/requests/feedback/files/cron and tenant isolation; existing APP_KEY preservation, first install, optimized routes and maintenance: passed.
- Archive privacy fixture: client history **1** public event; developer history **7**, including internal events; legacy rows preserved. Private agent comments/files/export content hidden. Anonymous demo classes/routes absent even with forged flags. No automatic plan seed, subscription or authority creation.
- Compiled archive, Chromium: real UV Passkey step-up, one team mutation, no duplicate dialog; profile/security/notifications branding; agreement publication/public document/acceptance gate; client cancel/no mutation and task completion: passed.
- Compiled archive, PHP 8.4.26, platform: **6** RU/EN × light/dark/system scenarios, desktop/mobile, sidebar/history, 1440/1280/1024/390/375 widths, plan revisions/conflict/publication/unsaved edits, no horizontal overflow or JavaScript errors. Production direct denial is server **403** before SPA, while Vite source mode uses router denial.
- Packaged export: Chromium + Firefox × 1440/390 × RU/EN/TR/ZH-CN = **16** scenarios; 16 PDF and four DOCX downloads passed. Mobile screenshot inspected.

Local evidence: `artifacts/releases/ux-polish/` and `artifacts/milestone-10-0-phase-2/browser/`. Reports/screenshots, temporary databases and QA factories are outside the ZIP. Physical devices and Safari were not tested. No Jino/production verification or deployment was performed.

No new migration or required `.env` parameter relative to installed Phase 2 (`3306015`). Do not run PlansFoundationSeeder, first-install, reset/backfill or authority reassignment during this update. Follow [PRODUCTION.md](PRODUCTION.md).

Later commits record evidence/test harness fixes and the separate Static Demo. They do not change this immutable archive or its recorded application-source commit.
