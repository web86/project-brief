# Production verification

All fixtures are disposable and synthetic. Production/Jino was not accessed. Full backend regression runs on PHP 8.4.26 and MariaDB 11.8.9 in separate QA schemas; the existing local demo database is not used for regression resets.

The release builder verifies the actual extracted ZIP with no-dev vendor. Checks include signed WebAuthn enrollment, first installation, preservation of an existing APP_KEY, ordinary account/workspace/client/integration operations, plan setup and immutable publication, client-history allowlisting, developer internal history and absence of anonymous demo routes/classes even with forged demo flags.

Compiled-browser checks cover account security, administrators, agreement publishing/acceptance, client completion and branding. Platform scenarios exercise the archive's actual public assets and private runtime, RU/EN × light/dark/system, 1440/1280/1024/390/375 widths, browser history, active links, revision conflict and unsaved edits. Privacy fixtures include active/deleted agents and legacy public flags without modifying stored history.

Exact final results and checksums are recorded in `ARTIFACT.md` after successful packaging. Detailed local screenshots/reports stay under `artifacts/releases/ux-polish/` and are not uploaded in the production ZIP.

No new migration or required `.env` parameter exists relative to installed Phase 2. Existing update does not call PlansFoundationSeeder or alter PlanAuthority, plan assignments, subscriptions or rollout modes.
