# Milestone 5.0.3 — verification

26 October 2026. Baseline `dd0d27f`, preserving ancestor `728b1f1`, on branch
`codex/milestone-5-0-3-read-api-quiet-cron`. Local-only work; no push/deployment or
live Jino/MariaDB/CRM E2E claim.

## Results

- Full backend suite: **663/663 tests**, **7033 assertions**.
- New API tests: **10/10**, **141 assertions**. New real-process CLI tests: **7/7**, **79 assertions**.
- Full frontend suite: **240/240**.
- New read API browser smoke: passed, **12 screenshots**, no runtime errors.
- All prior integration browser regressions (5.0, 5.0.1 and 5.0.2): passed, with separate output under ignored `artifacts/milestone-5-0-3/`.
- Appearance regression: **11/11 groups**. Both separate-process filter/deletion concurrency checks pass.
- Prettier, Pint, branding verification and OpenAPI local reference resolution pass.
- Production build and packaged runtime/secret/file validation run again in the release builder.

## Coverage

New API feature tests cover workspace/project/comment isolation, owner/admin/client/
guest browser identities failing without bearer, required scopes, expired/revoked
keys, all seven scope choices, task/general and client/admin filters, strict invalid
filters, direct lookup using actual webhook commentId, no new webhook text/fields,
exact comment/file allowlists, JSON markup escaping and safe author UUID/null.
They verify deterministic encrypted cursor continuation after insertion/deletion,
binding to token/project/filters, hidden soft-deleted parents, internal/invalid
authors, inconsistent parent links and legacy task comments with null project_id.

Attachment tests exercise the three routes, direct task versus task/general-comment
separation, metadata without bytes/URLs, required attachments and parent scopes,
actual streamed bytes with download security headers/no cookies, foreign tenant,
missing disk file, traversal path, inconsistent attachment relationship and deleted
comment/task denial. Text is intentionally available only in the explicit read API;
webhooks remain metadata-only. No read receipts are marked by API reads.

CLI tests start real PHP processes and capture stdout, stderr and exit separately.
They assert empty and busy-lease silence, 2xx silence, explicit verbose statistics,
503 retry with bounded sanitized diagnostic, no due/backoff silence, successful retry
silence, 400 rejection without blocking a healthy endpoint, transport/timeout and
unsafe destination errors, runtime Throwable/Error nonzero sanitization even in debug,
lock/database failure and history rollback. They use disposable SQLite and HTTP fakes,
never a public receiver. Existing filter/deletion concurrency regressions are rerun.

[Browser results](browser-results.json) cover new scopes initially unchecked, owner
UI creation with the new permissions, privacy notice, real synthetic client uploads
to both task/general discussions, bearer list/direct retrieval and downloaded bytes,
and client cookies denied without bearer. Twelve screenshots cover RU/EN,
light/dark/system on desktop/320px with no horizontal overflow. System resolves dark;
the existing appearance suite additionally checks OS/manual switching. Representative
screenshots were visually reviewed; no one-time secret or file/comment text is captured.

- [Mobile scopes RU light](mobile-scopes-ru-light.png)
- [Desktop scopes EN dark](desktop-scopes-en-dark.png)
- [Mobile scopes EN system](mobile-scopes-en-system.png)

Production runtime checks use the packaged production vendor, real migrations and
HTTP v1 routes: comments by UUID, attachment metadata/lists/download bytes, parent
permissions and cookie-free bearer requirements. They also run the packaged cron in
actual PHP processes with fake 503 and 204 receivers to check stderr/nonzero and
silent success, plus all inherited install/auth/export/outbox/runtime checks.

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
node tests/webhook-filters.concurrent.mjs
node tests/integration-cleanup.concurrent.mjs
PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/integration-read.browser.mjs
INTEGRATION_OUTPUT_DIR=../artifacts/milestone-5-0-3/baseline-5-0/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/integrations.browser.mjs
INTEGRATION_OUTPUT_DIR=../artifacts/milestone-5-0-3/baseline-5-0-1/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/webhook-filters.browser.mjs
APPEARANCE_OUTPUT_DIR=../artifacts/milestone-5-0-3/appearance/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs npm run test:appearance
./bin/build-release
```

The 5.0.2 polish regression can be rerun with INTEGRATION_OUTPUT_DIR pointing at
ignored artifacts; it must preserve its published 5.0.2 screenshot evidence.
Release requires a clean source commit, repeats full suites/build, validates allowed
files/local secrets, production vendor and split runtime, checksums files and writes
a ZIP. Final archive metadata/SHA256 are recorded in a later documentation-only
commit, separate from the exact source commit in release.json. See PRODUCTION.md
for actual Jino smoke/rotation/rollback instructions; production remains unmodified.

## Final archive

Packaged source: `be94a0f81e2ebeb9955aeede958ffc1c65747f2b` (ancestor `728b1f1` preserved).
Archive: `release/project-brief-20261026-094241-be94a0f.zip` (17396803 bytes). ZIP CRC, all 7905
file SHA-256 checksums, 196 matching tracked source files plus compiled frontend
assets, production-only vendor (91 packages), packaged runtime and secret/file
validation passed. The packaged runtime verifies new read routes and actual quiet
cron stdout/stderr with fake error/success receivers.

SHA-256: `99aea589d06852a8185a8f279290212f8a74ccc82509ab23767d325c8bb5b56b`. See [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json)
and [SHA256SUMS.txt](SHA256SUMS.txt). A later documentation-only commit records this
archive evidence; the ZIP identifies the clean implementation commit above.
