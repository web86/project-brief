# Milestone 3.5 — Project export and large-project UX

Branch: `feature/saas-foundation`. Implemented 19 October 2026.
Initial milestone starting commit: `c77ce19ca5b8f49fa3198e2e364fc5d0ee848759`.
Export refinement starting commit: `a6fea04010cae37d81a8e4c420b772a634b8cfdb`.
Milestone 4 remains unstarted. No migrations or deployment.

## Export UI and access

`/admin/projects/{uuid}/export` is a normal child of `AdminProjectShell`, after
Settings in the shared Ideas / Clients / Structure / Notifications / Settings /
Export navigation. RU labels include «Экспорт». Active links use the existing
`aria-current` convention. Project tabs wrap on narrow screens so Export remains visible. Opening this route loads only cached/minimal project
summary (`?summary=1`), without clients, structure management, tasks, comments,
history, notification preferences or read-marker mutations.

`POST /api/admin/projects/{uuid}/export` uses the existing `admin` middleware and
Milestone 2 `ProjectAccess` project resolution before request validation. Active
workspace owners have implicit project access; active admins need a project
assignment in the selected workspace. Unassigned and foreign workspace users get
404; guests and client sessions get 401. Inactive/missing workspace memberships
retain the existing 403 behavior. Client navigation has no Export. A guessed admin
page resolves through the authenticated shell; guessed file representations and
client export endpoints do not expose documents. Existing session/CSRF rules apply.

## Request and field contract

```json
{
  "format": "pdf",
  "scope": "all",
  "locale": "ru",
  "fields": {
    "description": true,
    "expectedResult": true,
    "priority": true,
    "status": true,
    "estimateHours": false,
    "price": false,
    "attachments": false,
    "approvalDate": false
  },
  "documentTitle": "Техническое задание",
  "pageBreakBySection": false
}
```

`format`, `scope`, `locale` are required: exactly `pdf|docx`, `all|approved|done`, `ru|en`.
`fields` is optional; each omitted field uses the defaults below. Options must be
JSON booleans. Unknown top-level and field options, invalid values and nulls are
rejected with 422. The UI disables controls/download while pending, prevents
repeat submits, uses CSRF-aware binary downloading and presents failures inline
and through the existing global error toast. It does not replay failed requests.

| Content                                             | Default | Behavior                                                      |
| --------------------------------------------------- | ------- | ------------------------------------------------------------- |
| Project name, website when present, generation date | Always  | Common document metadata                                      |
| Section number/name, canonical task number/title    | Always  | Existing project structure                                    |
| `description`                                       | On      | Description paragraphs                                        |
| `expectedResult`                                    | On      | Expected-result paragraphs when present                       |
| `priority`                                          | On      | Human-readable localized priority                             |
| `status`                                            | On      | Human-readable localized status                               |
| `estimateHours`                                     | Off     | Explicitly selected saved hours, when present                 |
| `price`                                             | Off     | Explicitly selected saved price in project currency           |
| `attachments`                                       | Off     | Safe original filenames only; no embedding or links           |
| `approvalDate`                                      | Off     | Stored approval date only for approved tasks with a timestamp |

`documentTitle` is optional, trimmed, limited to 160 characters and rejected if it
contains control/format characters. Empty/null/whitespace falls back to
«Техническое задание» / “Technical specification” in the selected document language.
The UI pre-fills this title and updates an untouched default on document-language
changes; a custom title is preserved. Custom text is escaped and never translated.
`pageBreakBySection` is an optional strict boolean, default false. These settings
apply to this request only and are never stored in the database.

Zero estimates/prices remain valid values. Null/empty optional content is omitted.
Export uses saved server data; no local developer draft is silently saved. Attachment contents remain excluded; the optional filename list is described below.

`all` includes every sectioned project task. `approved` selects only
`client_approved = true`, independently of workflow status. The additional
«Только готовые» / “Completed tasks only” choice uses `scope=done` and selects
`status = done`, independently of client approval. All counts and totals use only
the exported task set. Empty sections are
omitted after filtering. Original numbers are preserved rather than renumbering
approved tasks, so an approved document can start at `1.2` or section `2`.
A zero-task result is 422 with `errors.scope`, including «Нет задач для выбранного
варианта экспорта.» or “No tasks for the selected export option.”

## Canonical data and rendering

`ProjectExportData` builds one normalized structure with `project`, `generated`,
`labels`, and `sections[].tasks[]`. Tasks contain only canonical number, title and
selected localized label/value entries; a presentation flag distinguishes text
blocks from metadata. A shared metadata string joins nonempty enabled values with
`·`, so disabled/missing fields never leave stray separators. The builder also
provides the document title, safe filenames, formatted approval dates, exact totals,
coverage messages, summary rows and presentation settings. Both renderers consume this exact array.

The builder queries tasks through the authorized project's relationship with an
explicit column allowlist, and selects sections through the same project. Queries
use section position and task position, with existing ID tie-breakers. Numbering
calls `ProjectStructure::number()` with the selected section relation already
loaded; no exporter has its own numbering/business rules or per-task queries.
A task belonging to a foreign project cannot enter through a malformed section
association. Renderers receive no Eloquent models, DB IDs, UUIDs or private records.

The document language defaults to current UI locale and remains selectable.
`api/lang/{ru,en}/exports.php` centralizes system labels, all six workflow statuses,
three priorities, units, empty-scope text and footer copy. User-authored names,
descriptions and expected results keep their original language. Unknown legacy
status/priority values display a localized “Unspecified”, never the raw enum.
Generation date is localized; the filename uses the same date in ISO form.

`PdfProjectExporter` renders an escaped Blade template through Dompdf onto A4 with
page margins, hierarchical headings, paragraphs and a subtle repeating ProjectBrief
footer/date. DejaVu Sans and bold fonts/metrics are shipped by Dompdf, selected
explicitly and embedded for Cyrillic. Font caches use system temporary storage;
no developer-machine font is required. Remote resources, inline PHP and JavaScript
are disabled, and resource access is restricted to packaged fonts. User markup
is literal text, never interpreted as resource links, HTML or executable content.

`DocxProjectExporter` creates modern OOXML with PHPWord, Calibri/fallback fonts,
Word title/heading styles, paragraphs, margins and repeating footer/date. XML
escaping is enabled. It writes one temporary ZIP outside public storage, reads it
and unlinks it in `finally`, including on failure. PDF is produced in memory.
Both responses immediately stream an attachment with the correct MIME,
`private, no-store`, `nosniff`, and an ASCII slug/date/extension filename. Empty
slugs fall back to `project`. No generated document/history/database row is stored.

## Dependencies and release resources

Verified against current upstream package metadata and installed Composer APIs:

| Added package               | Locked version | License           |
| --------------------------- | -------------- | ----------------- |
| `dompdf/dompdf`             | 3.1.6          | LGPL-2.1          |
| `dompdf/php-font-lib`       | 1.0.2          | LGPL-2.1-or-later |
| `dompdf/php-svg-lib`        | 1.0.2          | LGPL-3.0-or-later |
| `masterminds/html5`         | 2.11.0         | MIT               |
| `sabberworm/php-css-parser` | 9.5.0          | MIT               |
| `phpoffice/phpword`         | 1.4.0          | LGPL-3.0-only     |
| `phpoffice/math`            | 0.3.0          | MIT               |

[Dompdf](https://packagist.org/packages/dompdf/dompdf) and
[PHPWord](https://packagist.org/packages/phpoffice/phpword) are pure PHP packages
whose declared PHP requirements include 8.3/8.4. Composer resolves with the existing
PHP 8.3.30 platform target. No existing dependency was updated. Composer reported
no known security advisories when installing these versions. No overlapping PDF
renderer or Laravel wrapper was added. Neither renderer requires HTTP services,
Chrome, Node, LibreOffice, wkhtmltopdf or other runtime binaries.

Production needs PHPWord's GD and XMLWriter extensions in addition to existing
requirements; `api/bin/environment.php` and deployment guidance now check them.
Only disposable visual QA uses bundled LibreOffice, outside application runtime.

Dompdf includes DejaVu fonts. Their Bitstream/Arev notices and public-domain DejaVu
changes are retained in `resources/fonts/DejaVu-LICENSE.txt` from the
[upstream font license](https://github.com/dejavu-fonts/dejavu-fonts/blob/master/LICENSE).
The packager copies that resource directory, preserves upstream package licenses,
and explicitly validates PDF/DOCX classes, font family metadata, regular/bold
TTF/metrics, Blade template and both language files after vendor pruning.
The disposable packaged runtime generates RU PDF and DOCX through authenticated,
CSRF-protected HTTP requests, checks PDF/font and DOCX/XML/Cyrillic output, and
checks foreign-project and client denial. No generated output enters the archive.

## Privacy exclusions

The export allowlist excludes developer notes, comments, task history, attachment contents,
access-token values/hashes, magic URLs, Push subscriptions/endpoints, notification
settings/deliveries, storage paths, numeric DB identifiers, application UUIDs and
workspace membership internals. Estimate/price columns are not even selected unless
their options are enabled. User-authored content is intentionally retained as typed;
export does not attempt to redact secrets a manager manually types into public
project/task fields. Attachment paths/URLs, stored names, IDs and embedded images remain excluded even
when filename lists are enabled. Original filenames are reduced to basenames
(Unix/Windows separators), stripped of controls/directional formatting, limited to
200 characters and escaped in both formats. Empty/dot names are omitted. Only
attachment ID/task-ID associations and original names are selected internally;
renderers receive filename strings only.

## Collapsible sections

`SectionBlock` is shared by manager and client briefs. Sections initially expand.
The heading contains a native button with chevron, title and total task count;
click, Enter and Space toggle it. `aria-expanded`, a stable `aria-controls` target,
visible focus treatment and a minimum 44px button height work on mobile/desktop.
Reorder controls are siblings, so ordering does not trigger collapse. Hidden task
content uses `v-show` and cannot receive focus while collapsed.

One non-sensitive boolean is stored only when collapsed under
`projectbrief:collapsed:v1:{project UUID}:{section UUID}`. Expanding removes that
key. Rename/reorder preserve it; new sections default expanded. Different projects
and sections are independent. Client/manager personas intentionally share this
visual preference on the same browser; no account identity or authorization is
stored. Malformed/unavailable storage falls back to expanded without blocking the
brief. Deleted-section keys may remain harmlessly in localStorage.

The badge counts all section tasks even when status filters hide some. Filter state
is independent and never written to collapse storage. Direct admin/client task
routes render TaskView independently of SectionBlock, so a persisted collapse
cannot hide task details. The existing task ordering, explicit Save and unread
loading/marking behavior is retained.

## Initial Milestone 3.5 verification

| Check                                        | Result                                                                                                     |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `npm test`                                   | PASS — 113 tests (106 existing + 7 export/collapse tests)                                                  |
| `npm run build`                              | PASS — production Vite build                                                                               |
| `npm run format:check`                       | PASS — Prettier                                                                                            |
| `php artisan test` / PHP 8.5.10 + SQLite     | PASS — 176 tests, 2,300 assertions                                                                         |
| Full PHP 8.5.10 + MariaDB 11.8 suite         | PASS — 176 tests, 2,300 assertions                                                                         |
| Full PHP 8.4.26 + MariaDB 11.8 suite         | PASS — 176 tests, 2,300 assertions                                                                         |
| `vendor/bin/pint --dirty --format agent`     | PASS                                                                                                       |
| `./bin/build-release`                        | PASS — 7,762 packaged files, production dependencies, checksums, ZIP and disposable runtime                |
| Desktop 1440px / mobile 390px browser checks | PASS — downloads, native keyboard collapse, persistence, deep links, no horizontal overflow or page errors |
| RU/EN document QA                            | PASS — real endpoint samples, text extraction and all PDF/DOCX page images reviewed                        |

The six new backend feature tests add 283 assertions covering owner/assigned-admin
access, unassigned/foreign/client denial, guessed representations, both scopes,
empty sections and empty all/approved results, canonical numbering/order, omitted
and enabled fields, all status/priority translations, user text, strict input
validation, safe filenames, correct MIME/disposition, ZIP/XML structure, escaped
markup, PDF signature/embedded Unicode font, private-data exclusion and temporary
DOCX cleanup. Actual synthetic comments, history, token hashes, attachment paths,
Push endpoints and foreign tasks are excluded from the shared/PDF source and DOCX.
No unstable binary snapshots are used.

Seven new frontend tests exercise the real Vue templates, router/store and CSRF
API client: manager-only route/tab and summary-only loading, RU/EN default controls,
request payload, pending duplicate guard, binary filename handling, API/empty-scope
feedback, independent collapse and persistence, rename/reorder/project isolation,
corrupt/unavailable localStorage and client/admin brief/task behavior. Browser
verification additionally activates native buttons with Enter/Space on desktop
and mobile, reloads collapsed sections, opens both personas' deep links, downloads
both formats in both languages, checks control dimensions and examines responsive
page screenshots. Project navigation wraps so the last tab remains visible.

Real RU/EN PDF text extraction confirms Cyrillic user text, localized labels and
absence of private notes. RU/EN DOCX files render through the bundled QA-only
LibreOffice; every page image is reviewed, with no missing glyphs, clipping or
orphaned final status page after compact metadata layout. This QA tool is never
called by the application or release runtime.

MariaDB checks use a newly created disposable container with synthetic credentials;
the PHP 8.4.26 image derives from the existing verification image with GD added.
The copied source excludes real environments and credentials. Initial isolated-run
setup errors (missing synthetic APP_KEY/example files) were corrected before the
clean full passes above. Existing databases were never migrated, reset or removed.
No external SMTP/Push delivery was attempted.

## Initial release checkpoint

- Implementation and archive source commit: `f693156065c23cb6793cc5dfc3a69f8074e499c5`.
- Local archive: `release/project-brief-20261019-120543-f693156.zip` (ignored, about 14 MiB).
- The release build reran all 113 frontend tests and 176 PHP tests (2,300 SQLite
  assertions), rebuilt the frontend, installed production-only Composer dependencies,
  checked platform requirements, validated 7,762 files, verified checksums/ZIP and
  passed the disposable packaged runtime, including the new export HTTP checks.
- The packaged runtime preserves all previous split HOME, CLI/install/key,
  migration/tenant, CSRF/session/client, unread, route/assets and maintenance checks.
- The initial final documentation checkpoint `a6fea04` changed documentation only;
  initial application code matched that archive source commit. The final documentation
  commit identifier is reported in the completion message.
- Disposable preview processes, MariaDB container and custom PHP test image are
  removed after verification. Existing local services/images are retained.
- No production deployment or remote push. Final committed working tree is clean.

## Initial implementation changed files

- `DEPLOY-JINO.md`
- `api/app/Http/Controllers/ProjectExportController.php`
- `api/app/Http/Requests/ProjectExportRequest.php`
- `api/app/Services/DocxProjectExporter.php`
- `api/app/Services/PdfProjectExporter.php`
- `api/app/Services/ProjectExportData.php`
- `api/bin/environment.php`
- `api/composer.json`
- `api/composer.lock`
- `api/lang/en/exports.php`
- `api/lang/ru/exports.php`
- `api/resources/fonts/DejaVu-LICENSE.txt`
- `api/resources/views/exports/specification.blade.php`
- `api/routes/web.php`
- `api/tests/Feature/ProjectExportTest.php`
- `deployment/build-release.mjs`
- `deployment/release-files.mjs`
- `docs/milestone-3.5.md`
- `docs/saas-roadmap.md`
- `src/api/client.js`
- `src/assets/main.css`
- `src/components/SectionBlock.vue`
- `src/locales/en.js`
- `src/locales/ru.js`
- `src/router/index.js`
- `src/utils/projectExport.js`
- `src/utils/sectionCollapse.js`
- `src/views/AdminProjectExportView.vue`
- `src/views/AdminProjectShell.vue`
- `src/views/ProjectView.vue`
- `tests/export-collapse.test.js`
- `tests/release-runtime.mjs`

## Export refinement — compact specification and estimate

This is a focused continuation of Milestone 3.5. The renderers, ProjectAccess
boundary, canonical data builder, routes and collapse implementation remain in
place. No dependency or migration is added, and Milestone 4 remains unstarted.

### Compact document layout

PDF now uses 10pt body text at 1.35 line height, 14pt section headings, 11.5pt task
titles, 9.5pt labels, one 8.5pt secondary metadata paragraph per task and a 7.5pt
footer. Margins and paragraph/heading spacing are reduced while keeping readable
paragraphs. DOCX retains Calibri/fallback, uses 11pt body at 1.15 line height,
14pt/12pt section/task styles, 10pt labels, one 9pt secondary metadata paragraph
and an 8pt footer. Attachments use simple bullets. Neither document uses cards.

For example, a task can end with:

```text
Приоритет: Высокий · Статус: Готово · Оценка: 6 ч · Стоимость: 18 000 ₽
```

Approval date, when enabled and present, joins the same compact metadata line.
Long metadata wraps naturally; metadata stays with the preceding text/list paragraph where possible. Description/expected-result paragraphs remain
normal body text. Missing/disabled metadata produces no separators or empty row.

### Totals and final summary

Every document ends with a compact localized ИТОГО / SUMMARY block. It always
shows exported task count and approved count (`client_approved`) among those tasks.
Including hours or price automatically adds the corresponding total; there is no
separate totals checkbox or frontend-authoritative calculation.

Only tasks in the current scope and actual exported sections contribute. Both
formats consume the same pre-calculated `totals` and formatted `summary` rows.
Hours/prices are summed as decimal minor-unit strings at the domain's two-decimal
scale, avoiding cumulative binary rounding and aggregate PHP integer overflow.
Totals expose exact decimal strings plus their formatted document text.

For each included numeric field, the builder counts non-null values. Zero is a
provided value. If any exported task lacks that field, only that total receives a
localized coverage note, e.g. «Оценка указана для 1 из 2 задач» / “Estimate provided
for 1 of 2 tasks”. Complete coverage has no extra message. If every value is
missing, the total is zero with explicit 0-of-N coverage. Singular/plural forms are
localized. Turning a numeric field off also omits its total and coverage entirely.

Currency comes from the project, using the existing product symbol mapping:
RUB → ₽, USD → $, EUR → €, TRY → ₺. Unknown legacy/future codes fall back to the
configured code. RU uses nonbreaking-space digit grouping and a decimal comma;
EN uses comma grouping and a decimal point. The number and symbol/code are joined
by a nonbreaking space. Insignificant fractional zeroes are omitted in display;
exact raw totals retain two decimals. No exchange-rate conversion is performed.
The same formatted values are used for task prices and both renderers' totals.

### Optional content and pagination

- **Attachment list:** off by default. Includes only safe original filenames as a
  plain bullet list, with no images, download links, tokens, stored names or paths.
  Tasks without filenames produce no attachment heading/list. The query is loaded
  once for the selected project task set only when enabled.
- **Approval date:** off by default. Uses `client_approved_at` only when
  `client_approved` is true and the timestamp exists. RU uses `DD.MM.YYYY`, EN
  uses `D MMM YYYY`, with localized “Approved by client” copy. Unapproved tasks
  and missing timestamps have no fabricated date.
- **Start each section on a new page:** off by default. Only sections after the
  first exported section start a new page, including when earlier original
  sections were filtered out. PDF uses heading `page-break-before`; DOCX uses a
  paragraph style based on Heading1 with page-break-before. No leading/trailing
  break paragraphs are emitted. Normal content can still flow to further pages.

The form keeps format and language radio groups, document title, the three task
scope choices, eight content checkboxes and one layout checkbox. Only description,
expected result, priority and status default on; hours, price, filenames, approval
date and section breaks all default off. Pending/error/download behavior stays
unchanged. Unexpected root/nested options, non-boolean option values and invalid
or oversized titles are rejected server-side.

### Refinement changed files

- `api/app/Http/Controllers/ProjectExportController.php`
- `api/app/Http/Requests/ProjectExportRequest.php`
- `api/app/Services/DocxProjectExporter.php`
- `api/app/Services/ProjectExportData.php`
- `api/lang/en/exports.php`
- `api/lang/ru/exports.php`
- `api/resources/views/exports/specification.blade.php`
- `api/tests/Feature/ProjectExportTest.php`
- `docs/milestone-3.5.md`
- `docs/saas-roadmap.md`
- `src/locales/en.js`
- `src/locales/ru.js`
- `src/views/AdminProjectExportView.vue`
- `tests/export-collapse.test.js`
- `tests/release-runtime.mjs`

### Refinement verification and release

| Check                                    | Result                                                                                                           |
| ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `npm test`                               | PASS — 115 tests, including all previous export/collapse cases and 2 new refinement UI cases                     |
| `npm run build`                          | PASS — production Vite build                                                                                     |
| `npm run format:check`                   | PASS                                                                                                             |
| `php artisan test` / PHP 8.5.10 + SQLite | PASS — 183 tests, 2,876 assertions                                                                               |
| Full PHP 8.5.10 + MariaDB 11.8 suite     | PASS — 183 tests, 2,876 assertions                                                                               |
| Full PHP 8.4.26 + MariaDB 11.8 suite     | PASS — 183 tests, 2,876 assertions                                                                               |
| `vendor/bin/pint --dirty --format agent` | PASS                                                                                                             |
| `./bin/build-release`                    | PASS — 7,762 files, platform checks, checksums, ZIP and disposable packaged runtime                              |
| Browser / document rendering             | PASS — 12 real RU/EN PDF/DOCX downloads, desktop/mobile UI, text extraction and complete final page-image review |

The export feature file now has 13 tests and 859 assertions. Its original 6 cases
remain, with expectations updated for the requested title/unit/currency formatting.
Seven new cases cover scoped/exact totals, zero/null distinction and complete,
partial and zero coverage; RUB/USD/EUR/TRY/code fallback; all hours/price option
combinations; compact metadata paragraphs and smaller styles; custom/blank titles
and strict input validation; filename/date opt-ins and privacy; section breaks and
page count; completed-scope filtering, counts, totals and empty-result validation.

Frontend export/collapse coverage now has 9 tests. The two added cases verify title
defaults and language/custom preservation, all new localized controls, unchanged
default-off options, completed scope and exact submitted options. There is no totals
checkbox or authoritative browser-side calculation. Existing authorization, tenant,
Save/unread, client-link, notification/Push, PWA and collapse suites continue passing.

Browser QA generated default RU/EN, full-option RU/EN, section-break RU and completed
EN samples in both formats. PDF extraction and rendered DOCX text verify Cyrillic,
custom titles, numeric summaries, missing-value explanations, filenames/dates and
absence of private fixtures. Every page of the final RU/EN full-option and RU
section-break layouts was inspected. Six-task full-option samples fit two pages;
three-section break mode fits three pages without blank leading/trailing pages.
Task metadata is one smaller secondary paragraph and stays with preceding content.

No dependencies changed. MariaDB and PHP 8.4 checks used isolated synthetic data and
a source copy excluding real environment secrets, with GD in a disposable test image.
Existing databases and services were not migrated or reset. The temporary preview,
custom image and database container were removed after checks. No external mail or
Push delivery, production deployment or remote push was performed.

Refinement implementation / archive source:
`76517ad85b8473785a032b4c33988747e6ce00e3`.
Local archive: `release/project-brief-20261019-125511-76517ad.zip` (ignored).
The release reran all 115 frontend and 183 PHP tests (2,876 SQLite assertions),
built the frontend, installed production-only vendor, checked platform requirements,
validated 7,762 packaged files, verified checksums/ZIP and passed the disposable
runtime. Runtime checks now exercise the custom title, scoped totals/coverage,
filenames, approval date, section style and completed scope in addition to previous
export denial and CLI/session/tenant/unread/client/maintenance checks.

The final documentation commit changes documentation only; runtime code matches
the verified archive source. Its identifier is reported in the completion message.
Final committed working tree is clean. No migrations are introduced; applying this
change uses the existing release installation process. Nothing is deployed.

## Limitations and deployment

Exports run synchronously and use memory proportional to the selected project.
There is no background queue, saved document, version history, template editor,
branding, signature, individual task picker or attachment embedding. Word's font
fallback and pagination can vary across installed office applications. Very large
exports remain subject to the host's PHP memory/time limits.

No migrations, registration, recovery, verification, team management, invitations,
profile editing or billing are introduced. Nothing is deployed or pushed remotely.
