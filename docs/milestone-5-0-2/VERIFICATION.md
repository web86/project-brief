# Milestone 5.0.2 — verification

26 October 2026, Europe/Istanbul. Baseline `1116f41`; branch
`codex/milestone-5-0-2-ui-polish`. Local verification only; no push/deployment or
production credentials/data used.

## Results

- Full Laravel suite: **646/646**, **6813 assertions**; new cleanup tests: **4/4**, **54 assertions**.
- Full frontend suite: **240/240**.
- New browser regression: passed, **15 screenshots**, no runtime errors.
- Existing 5.0 and 5.0.1 browser regressions: passed; outputs remain under ignored
  `artifacts/milestone-5-0-2/baseline-5-0/` and `baseline-5-0-1/`.
- Existing appearance suite: **11/11 groups**, including OS/system/manual themes,
  RU/EN and mobile widths. Output: `artifacts/milestone-5-0-2/appearance/`.
- Separate-process deletion and filter concurrency checks pass.
- Prettier, Laravel Pint, branding asset verification and git whitespace check pass.
- Production build and packaged runtime/validation are repeated by the release builder.

## Scope and evidence

- Four new backend feature tests verify token row/hash/usage/idempotency retention,
  repeated revoke and bearer/replayed-write 401; endpoint → deliveries → attempts
  cascade and project-selection deletion; retained other endpoint/history/shared
  event; failed cross-workspace/admin delete; and processing delivery/lease expiry.
- The separate-process concurrency test runs the production destroy controller
  against a worker held inside a fake HTTP attempt. Deletion waits for its transaction,
  cascades its completed attempt plus the next pending delivery, retains immutable
  events, and a subsequent worker sends nothing. SQLite uses IMMEDIATE transactions
  and a busy timeout for this experiment. This demonstrates serialization and cascade
  behavior locally; it does not claim a live MariaDB/Jino row-lock experiment.
- Frontend SSR checks cover general/project navigation placement, one return link,
  owner/admin/no-workspace visibility, direct-route role enforcement and different
  Team/Clients SVG output.
- [Browser results](browser-results.json) cover creation, 422 field validation,
  403/500/network failures without success, pending duplicate clicks, clipboard
  denial/success/deduplication, revoke cancel/failure/delayed success, bearer 401,
  stale list and reload, webhook create/filter/save/toggle/test/history/retry/rotate,
  named delete warning, cancellation/failure retaining history, and a held history
  response released after deletion without resurrection. Owner/admin/workspace and
  guest boundaries are checked. Existing milestone browser suites are also rerun.
- Browser tests use fresh disposable SQLite and synthetic users/projects; outgoing
  HTTP is faked and prevented from escaping. Setup checks public DNS for example.com.
  No screenshots include a one-time secret or private comment/attachment content.
- Packaged runtime tests repeat real controller revocation, retained token/idempotency
  metadata, bearer and replayed-write 401, endpoint/history/pivot cascade and retained
  events using the production-only vendor and migrations. Existing packaged v1,
  filter, metadata-only comment, auth, export, attachment and install checks remain.

## Screenshot matrix

For each RU/EN locale, light/dark/system is captured on desktop 1440px and mobile
320px. System resolves to a dark OS setting in this matrix; the appearance suite
also checks OS changes/manual overrides. Matrix notifications use the actual
global toast component and localized successful-deletion copy, injected only to
hold a stable screenshot after the real deletion assertion. Actual revoke success
is captured independently. Browser assertions check toast bounds and no page
horizontal overflow. Screenshots have been visually reviewed.

- [Actual revoke success, desktop RU light](desktop-revoked-toast-ru-light.png)
- [Mobile RU light toast](mobile-toast-ru-light.png), [mobile EN dark toast](mobile-toast-en-dark.png)
- [Desktop EN system](desktop-toast-en-system.png), [mobile RU system](mobile-toast-ru-system.png)
- [Owner mobile navigation](mobile-owner-navigation-ru-light.png)
- [Project mobile navigation](mobile-project-navigation-ru-light.png)

All 15 screenshots are beside this report, enumerated in browser-results.json.

## Reproduce

```sh
npm test
npm run format:check
npm run brand:check
npm run build
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
cd ..
node tests/integration-cleanup.concurrent.mjs
node tests/webhook-filters.concurrent.mjs
PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/integration-polish.browser.mjs
INTEGRATION_OUTPUT_DIR=../artifacts/milestone-5-0-2/baseline-5-0/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/integrations.browser.mjs
INTEGRATION_OUTPUT_DIR=../artifacts/milestone-5-0-2/baseline-5-0-1/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/webhook-filters.browser.mjs
APPEARANCE_OUTPUT_DIR=../artifacts/milestone-5-0-2/appearance/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs npm run test:appearance
./bin/build-release
```

The release builder requires a clean source commit, repeats full suites and Vite
build, installs production vendor, validates allowed files/local secrets and the
split public/private runtime, and writes every file checksum before creating ZIP.
Evidence uses GUIDES rather than a forbidden docs directory inside the archive.
Final source commit, archive bytes and SHA-256 are recorded after verification in
RELEASE-ARTIFACT.json and SHA256SUMS.txt; the later metadata-only commit is distinct
from the source commit contained in the ZIP.

## Final archive

Packaged source: `728b1f124cdb2344c1afbf473a2bf5eee05d3cc4`. Archive: `release/project-brief-20261026-090942-728b1f1.zip`
(16349020 bytes). ZIP CRC, all 7885 file SHA-256 checksums,
197 matching tracked source files plus built frontend assets, production-only
vendor (91 packages), packaged runtime and secret/file validation pass.

SHA-256: `6e6762a738783d4c0860ac39292732b60750461126d7689699979b7f104d42c9`. See [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json)
and [SHA256SUMS.txt](SHA256SUMS.txt). The following documentation-only commit records
archive evidence; the ZIP deliberately identifies the clean source commit above.
