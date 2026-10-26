# Milestone 5.0.1 — verification

26 October 2026, Europe/Istanbul. Local baseline `1360ec5`, including 5.0 source
`5042b23`; the previous production ZIP hash, packaged commit/version and matching
integration/comment source files were verified before branching. Branch:
`codex/milestone-5-0-1-webhook-filters`. No push, deployment or live CRM/Jino test.

## Results

- Full Laravel suite: **642/642 tests**, **6759 assertions**.
- New backend tests: **12/12**, **208 assertions** across `WebhookProjectFiltersTest`
  and `ClientCommentWebhooksTest`. All existing Milestone 5.0 tests also pass.
- Full frontend suite: **238/238**. Vite production build, Prettier check, Laravel
  Pint, branding generation check and git whitespace check pass.
- Existing appearance suite: **11/11 groups**, including OS/system theme changes,
  manual override, desktop/mobile light/dark, RU/EN and 320/360/390px widths. Output
  is separate under ignored `artifacts/milestone-5-0-1/appearance/`.
- New browser smoke: passed with no runtime errors; [browser-results.json](browser-results.json).
- Separate-process concurrency check: passed using a disposable SQLite database and
  a delayed HTTP fake. A concurrent filter transaction waits for the in-flight
  delivery, then cancels the remaining pending delivery. A subsequent processor
  sends nothing under the old filter. This demonstrates SQLite transaction blocking;
  it is not a live MariaDB/Jino row-lock or remote network claim.

## Backend evidence

Filter tests cover all/future workspace projects, selected subsets, foreign tenant
UUIDs, empty/duplicate/invalid selection, partial edits, order-only no-op, all↔selected,
owner/admin/client/guest, and unchanged workspace-scoped API token visibility.
Composite FK tests reject both a foreign project under the local workspace and a
local endpoint under the foreign workspace. Project deletion cascades selection,
leaves selected empty/fail-closed, cancels queued events at processing, and prevents
manual retry. Test delivery bypasses selection with projectId=null. Filter changes
increment version, cancel all pending, preserve immutable event/body/IDs and block
old failed retries. Existing disable/rotate, lease, HMAC, timeouts and retry tests
remain green. The additive migration test checks default all while preserving exact
endpoint fields/subscriptions, delivered events, delivery rows and attempt history.

Comment tests exercise both task/general routes and all/selected filters through
`CommentSubmission::store`, including attachments, same submissionId replay/conflict,
client versus admin, excluded same-workspace and foreign projects, transaction rollback
and attachment DB failure with file cleanup. Payload checks assert the exact public
key allowlist, comment/client/project/task UUIDs, null taskId for general, empty changes,
origin ui and raw-byte HMAC. They reject raw text, email, names, filenames/private
paths/bytes, prices and estimates. Disabled notification rules/preferences do not
prevent webhook events, and task/general unread counts remain correct. Existing
comment attachment/storage/notification tests run in the full suite.

## Browser evidence

Local Laravel/Vite and fresh disposable SQLite, synthetic identities/projects and
HTTP fake processing. Public DNS is checked at webhook setup; no remote webhook POST
or CRM side effect occurs. The browser verifies:

- Initial management load failure, retry and clearing the error after success.
- Search/no-match, two-project selection and count, empty selected save disabled,
  create, edit prefilled UUIDs, switch all/selected, and an empty workspace state.
- Explicit opt-in to both new events; real synthetic client sessions post task and
  general comments twice with one submissionId. Selected projects create four events;
  the excluded third project creates no event/delivery. Fake HTTP delivery is shown
  as delivered with safe type/status/attempt history. Test works with selected mode.
- RU/EN, light/dark, 1440px desktop and 320px mobile without horizontal overflow;
  mobile owner navigation. Owner→admin workspace switching removes access; separate
  admin/client/guest contexts lack management access.

Screenshots contain only synthetic project names and safe delivery metadata. No
one-time secret, raw comment text or private attachment is captured.

| View                                     | Screenshot                                                                       |
| ---------------------------------------- | -------------------------------------------------------------------------------- |
| Desktop selection / RU / light           | [desktop-create-filter-ru-light.png](desktop-create-filter-ru-light.png)         |
| Delivered comments / desktop / EN / dark | [desktop-comment-deliveries-en-dark.png](desktop-comment-deliveries-en-dark.png) |
| Edit selection / 320px / EN / dark       | [mobile-edit-filter-en-dark.png](mobile-edit-filter-en-dark.png)                 |
| Selected summary / 320px / RU / light    | [mobile-selected-summary-ru-light.png](mobile-selected-summary-ru-light.png)     |
| Owner mobile navigation / RU / light     | [mobile-owner-navigation-ru-light.png](mobile-owner-navigation-ru-light.png)     |

## Reproduce

```sh
npm test
npm run build
npm run format:check
npm run brand:check
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
cd ..
node tests/webhook-filters.concurrent.mjs
PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/webhook-filters.browser.mjs
APPEARANCE_OUTPUT_DIR=../artifacts/milestone-5-0-1/appearance/ \
  PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs npm run test:appearance
./bin/build-release
```

The existing release builder repeats full tests/build, installs production-only
vendor into staging, scans forbidden files/local secrets, tests the packaged split
private/public application on disposable SQLite and checksums all files before ZIP.
Its runtime checks include stateless v1, cron, the new project-filter migration/FKs,
selected membership and metadata-only comment events without development factories.
The archive commit, exact path, bytes, SHA-256 and CRC/file-checksum results are
recorded in `RELEASE-ARTIFACT.json` and `SHA256SUMS.txt` after the clean code commit
is packaged. The final evidence commit only records artifact metadata.

## Limits and rollout

No real Jino/MariaDB migration or production cron, external receiver, CRM or n8n E2E
was run. Read [PRODUCTION.md](PRODUCTION.md) for the additive MariaDB migration,
unchanged cron, post-install smoke and rollback procedure. In particular, never run
an old 5.0 sender against enabled selected endpoints: disable/cancel before rollback,
remove new subscriptions and explicitly accept all-project semantics before resuming.

No project-scoped API tokens, comment-read REST API, magic/deep links, extra worker,
GitHub/Figma, widget, OAuth or two-way CRM sync were added. Existing at-least-once,
ordering, DNS resolver timeout and retention limits remain. Excluded events have no
delivery log row; deleted projects may leave an enabled selected endpoint with an
empty filter that sends nothing until the owner chooses available projects.
