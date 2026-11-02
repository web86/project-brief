# Dev2Done Static JSON Demo

A separate Vue/JS/CSS/JSON release for `demo.brief.web86.site`. It uses the existing application views, with a compile-time transport adapter. No PHP, Laravel, database, server session, authentication service, Docker, mail, Push, Telegram, SSO or AI runtime is installed on the hosting server. The immutable production v0.9.11 archive was completed and pinned in commit `8f7c9cb` before this implementation began; see `docs/releases/ux-polish/ARTIFACT.md`.

## Build and refresh

Prerequisites on the developer machine only: locked npm/Composer dependencies, Python 3, and the existing isolated local demo from [../README.md](../README.md), including its PHP 8.4 image/MariaDB containers. The reviewed Harborline dataset must be present. No production environment file is read by the frontend static build.

```sh
npm run demo:export
npm run demo:build:static
npm run demo:preview:static
```

Export invokes `bin/demo export` with the dedicated `demo_reader` SELECT-only credential. It asserts the local installation marker and reviewed content hash, audits UUID/workspace/foreign-key/lifecycle coherence, executes a fixed inventory of real GET routes through Laravel middleware, policies and resource serializers, and confirms the source database hash is unchanged. The CLI process alone can read account/agreement screens and bypass HTTP request quotas; its route inventory, isolated database and SQL read-only guard remain enforced. Ordinary public Laravel Demo restrictions are unchanged.

The exporter never serializes models wholesale. Account/preferences HMAC identifiers and edit/sync cursors are removed or replaced with inert public identifiers. Only allowlisted synthetic attachment/branding assets are copied. Profile, security devices and agreement state use explicit synthetic projections in existing UI contracts, without changing QA records. Developer and Client have different routes, serializers and files. Every published byte is fictional and safe even if all developer JSON is opened manually.

Exported output is ignored under `.tools/demo-runtime/static-export/`. The builder validates pinned schema v1, private-field/content guards, reserved `.test` contacts, checksums and references, then copies **only files referenced by the current manifest** into an env-free `.tools/static-demo-build/`. Snapshots and images use content hash filenames. No all-dataset startup JSON, service worker, PDF worker/fonts, backend or node_modules is shipped. PDF/DOCX pages show existing export settings; export actions show “Demo · View only”.

Final packaging requires a clean committed working tree. It creates `release/dev2done-static-demo-v2-<commit>.zip`, `.zip.sha256`, `release.json`, `checksums.txt`, `validation-report.json`, schema and Apache SPA rules. It validates the actual extracted ZIP. `node deployment/build-static-demo.mjs --preview` is an explicit development-only build that permits an uncommitted tree and creates no release ZIP.

The local Laravel/full QA stack remains available through the existing launcher. Export neither resets it nor changes business records. The generated fictional avatar is copied to a demo-only storage directory without changing any profile record.

## Validation

```sh
node deployment/validate-static-demo.mjs
node --test tests/static-demo.test.js
npm test
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs npm run test:static-demo
```

For an actual extracted package, set `STATIC_DEMO_PACKAGE_ROOT=/absolute/path/to/extracted/static/`. The browser harness serves files with Node only, exercises both roles, every project/task family and settings, records screenshots and rejects API HTTP requests, writes, outgoing requests, broken images, overflow and JavaScript errors. Results stay outside both release ZIPs in `artifacts/demo/static/`.

See [INVENTORY.md](INVENTORY.md), [SCHEMA.md](SCHEMA.md), [HOSTING.md](HOSTING.md) and [ASSETS.md](ASSETS.md). Current Dev2Done v2 release identity/results are recorded in `docs/releases/dev2done/ARTIFACTS.md`; `ARTIFACT.md` retains the historical v1 record. No deployment, production DB access or push is part of this work.
