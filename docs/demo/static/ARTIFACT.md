# Static Demo artifact — schema v1

- Archive: `release/project-brief-static-demo-v1-e1f0daf.zip` (2,718,686 bytes).
- SHA-256: `9174594962d7dd6f84e5f5654140dfb7339abeb0ad71894e81084636d4aad18a`.
- Immutable application source: `e1f0daf0d4831972a438efc2eb56449d0868ce4b`.
- Built: `2026-11-02T16:34:56.983Z` by `npm run demo:build:static`, from a clean committed working tree.
- Independent target: `demo.brief.web86.site`; static HTTP only. This is separate from production v0.9.11 / source `5685ea2efadc419aef2c9c61132ef8b416600420`.
- ZIP integrity, actual extracted file checksums, pinned schema v1, reviewed public projection fingerprint, privacy/secret and relationship validation: passed.

## Contents and performance

255 files, 5,408,348 bytes extracted: 141 immutable JSON snapshots; 22 synthetic images/text attachments; compiled Vue/JS/CSS/icons; manifest, schema, Apache fallback/cache/CSP rules, release metadata, validation report, checksums and hosting note. The largest snapshot is 105,444 bytes. Screens/project snapshots load lazily; no all-dataset startup payload. PDF worker/fonts, PHP, Laravel, database, vendor/node_modules, Docker, `.env`, credentials and service worker are absent.

314 developer and 26 client read paths share the existing application views. All ten projects / 150 tasks and the scoped fifteen client tasks are validated. Profile, security and agreement are existing UI components with explicit fictional state and disabled mutation controls. Other writes fail locally with `demo_read_only`, without HTTP. Client snapshots have no internal agent metadata/comments/notes/pricing/hours/integration settings. All public developer data is fictional too.

## Actual extracted ZIP browser verification

Chromium `151.0.7922.34`, **75** recorded UI scenarios, served by a Node static file server only. Developer/Client entry; all project/task families; profile/security/recovery acknowledgement; English agreement and public document; branding/administrators/notifications; requests/reviews; API tokens/Webhooks/SSO/Agents/operations; project team/clients/structure/settings/export; team chat and agent conversation; search/status filtering; Back/Forward/direct reload; language/light/dark/system; 390/375 mobile widths: passed.

Every browser request was recorded: **0 API HTTP requests, 0 writes, 0 outgoing requests, 0 JavaScript/console errors**. Revoke returned the view-only message, with no false success. No broken images or horizontal overflow. Mobile profile/security/agreement/client screenshots were visually inspected. Source and actual archive were tested separately.

Regression after implementation: **1109 backend tests / 16267 assertions on PHP 8.4.26 / MariaDB 11.8.9**, **445 frontend tests**, normal production Vite build and **23 ordinary Laravel security browser scenarios** passed. The normal frontend contains no static snapshot transport/dataset markers or demo entry chunk. Local Laravel Demo/Full QA was synced without reset; source counts/content hash remained intact.

Local evidence: `artifacts/demo/static/browser-results.json`, screenshots and `artifacts/demo/static/logs/`. See [TESTS.md](TESTS.md). No physical-device/Safari/Jino deployment verification was performed. Hosting/independent rollback: [HOSTING.md](HOSTING.md). Fictional avatar asset and exact built-in imagegen prompt: [ASSETS.md](ASSETS.md).

Later evidence/test-harness commits do not modify this immutable archive. No deployment, production database change or push was performed.
