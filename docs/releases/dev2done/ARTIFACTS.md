# Dev2Done release artifacts

## Production v0.9.12

- ZIP: `release/dev2done-v0.9.12-e0ea457.zip` (39,706,411 bytes).
- SHA-256: `306b9d71be4d168fef8adea8bd99ee2affd9a4db8403d830ca7e97a7645304b2`.
- Application source: `e0ea4573a1e0ee87805961c67ac560072d6e460d`.
- Built: `2026-11-02T20:13:08.385Z`, existing `./bin/build-release`.
- Standard layout preserved: `public/` + `private/project-brief-app/`, no-dev vendor, release manifest, checksums, update/rollback instructions.
- Actual ZIP integrity/CRC, 8,970 runtime file checks, extracted checksum verification, secret/demo exclusion, PHP compatibility and ordinary HTTP lifecycle/integration tests passed.
- Actual extracted ZIP, PHP 8.4.26: signed WebAuthn/TOTP/recovery; privacy allowlist and legacy history preservation; idempotent plan setup/immutable publication; HTTP runtime/invitations/provisioning/requests/SSO/feedback/attachments/webhooks/cron/session/isolation; existing APP_KEY and legacy APP_NAME/MAIL_FROM_NAME preservation with **Dev2Done display identity**; compiled platform/plans (six locale/theme scenarios, collapsed/mobile logo) passed.
- Actual compiled ZIP, Chromium: UV step-up and one team mutation, profile/security/notifications branding, agreement publication/acceptance gate, client completion: passed. Chromium/Firefox × desktop/mobile × four languages: 16 PDF/four DOCX downloads passed. Downloaded DOCX contains Dev2Done attribution and PDF contains Dev2Done metadata.

No new migration or required env key; no tariff/PlanAuthority/subscription reinitialization. Phase 3 was not started. User content/custom tenant brands and technical API/session/runtime identities remain compatible. Use [PRODUCTION.md](PRODUCTION.md).

## Static Demo v2

- ZIP: `release/dev2done-static-demo-v2-80aa892.zip` (2,914,131 bytes).
- SHA-256: `73efee471eb7d7d60efcf689e3ad267008b38a1f7aeb07138c46dc270934640b`.
- Application/build source: `80aa892bc76d25cee246f215ab5f10cd18e323b5`.
- Built: `2026-11-02T20:25:44.919Z`, `npm run demo:build:static`.
- Schema v1/read paths retained: 314 developer / 26 client contracts. 256 files, no PHP/Laravel/DB/node_modules/vendor/service worker. Lazy, content-hash JSON/assets; static SPA fallback and CSP.
- User SVG, Dev2Done/D2D labels, violet/indigo palette, four-language UI, existing profile/security/agreement and Harborline studio identity are included.
- **Core experience is the first Northstar client section**, with its six tasks numbered `1.x`; Discovery & design is second. The same source ordering is retained for Developer to keep numbering consistent. UUIDs, task content/statuses, comments, people and credentials remain intact.
- Actual ZIP CRC/extraction/file checksums, pinned schemas, reviewed public projection fingerprint and privacy/reference checks passed.
- Actual extracted package served by Node static files only: **75 UI scenarios** passed, including both roles/all projects/settings, new titles, Core experience order, history/reload/deep links, themes/languages, mobile 390/375, read-only Revoke, Team Chat and agents.
- Recorded **0 API HTTP requests, 0 writes, 0 external requests, 0 JavaScript/console errors**. No broken images/horizontal overflow. New logo, chooser, profile, security, agreement/client and platform screenshots were visually inspected.
- Only fingerprinted resources are immutable; explicit default no-cache makes all fixed brand filenames revalidate on Apache as well as the checked Node static server. This static-only cache fix is the reason the final static build commit follows the production source commit.

Hosting/update/independent rollback: [Static hosting](../../demo/static/HOSTING.md). Upload new hashed resources before manifest/index, retain old hashes for active tabs, or atomically switch the whole static web root. No production runtime or database is used.

## Regression and scope

- Full PHP 8.4.26 / MariaDB 11.8.9: **1109 tests / 16267 assertions**; after client order steering, focused suite **27 tests / 304 assertions**, passed.
- Current frontend: **449 tests**, normal Vite build and SVG/PNG/ICO validation passed (production's standard source build had 448 tests before the additional static cache test).
- Ordinary security UI: **23** scenarios; platform/plans: **6** RU/EN × light/dark/system; static: **75** scenarios. Source and actual ZIPs verified independently.
- Privacy and Phase 1–2 behavior preserved. No Phase 3/enforcement work, migrations, new runtime requirements, production DB changes, deployment or push.
- Existing local Laravel Demo/Full QA synced without reset. Dataset task content hash remains `9a39a84bb3111143a29505bacb254b15268393c08975d170d7e78240ee6af77c`; counts unchanged. Static preview: `http://127.0.0.1:18082/demo`.
- Local evidence: `artifacts/rebrand-dev2done/` (including logs and JSON field review). These files are outside both ZIPs. Physical devices/hardware Touch ID/real Safari and Jino deployment/Apache settings were not tested. Firefox authenticator compatibility uses a mock provider; Chromium uses virtual signed UV.

Previous v0.9.11 and Static Demo v1 ZIPs remain unchanged for independent rollback. The newer evidence commit does not modify the two immutable artifacts above. Naming/assets/intentional legacy references: [README.md](README.md); changes: [CHANGELOG.md](CHANGELOG.md).
