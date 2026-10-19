# Milestone 3.5 — Project export and large-project UX

Branch: `feature/saas-foundation`. Implemented 19 October 2026.
Starting commit: `c77ce19ca5b8f49fa3198e2e364fc5d0ee848759`.
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
    "price": false
  }
}
```

`format`, `scope`, `locale` are required: exactly `pdf|docx`, `all|approved`, `ru|en`.
`fields` is optional; each omitted field uses the defaults below. Options must be
JSON booleans. Unknown top-level and field options, invalid values and nulls are
rejected with 422. The UI disables controls/download while pending, prevents
repeat submits, uses CSRF-aware binary downloading and presents failures inline
and through the existing global error toast. It does not replay failed requests.

| Content                                             | Default | Behavior                                                   |
| --------------------------------------------------- | ------- | ---------------------------------------------------------- |
| Project name, website when present, generation date | Always  | Common document metadata                                   |
| Section number/name, canonical task number/title    | Always  | Existing project structure                                 |
| `description`                                       | On      | Description paragraphs                                     |
| `expectedResult`                                    | On      | Expected-result paragraphs when present                    |
| `priority`                                          | On      | Human-readable localized priority                          |
| `status`                                            | On      | Human-readable localized status                            |
| `estimateHours`                                     | Off     | Explicitly selected saved hours, when present              |
| `price`                                             | Off     | Explicitly selected saved price with project currency code |

Zero estimates/prices remain valid values. Null/empty optional content is omitted.
Export uses saved server data; no local developer draft is silently saved. No
attachments or filenames are included in V1.

`all` includes every sectioned project task. `approved` selects only
`client_approved = true`, independently of workflow status. Empty sections are
omitted after filtering. Original numbers are preserved rather than renumbering
approved tasks, so an approved document can start at `1.2` or section `2`.
A zero-task result is 422 with `errors.scope`, including «Нет задач для выбранного
варианта экспорта.» or “No tasks for the selected export option.”

## Canonical data and rendering

`ProjectExportData` builds one normalized structure with `project`, `generated`,
`labels`, and `sections[].tasks[]`. Tasks contain only canonical number, title and
selected localized label/value entries; a presentation flag distinguishes text
blocks from compact metadata paragraphs. Both renderers consume this exact array.

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

The export allowlist excludes developer notes, comments, task history, attachments,
access-token values/hashes, magic URLs, Push subscriptions/endpoints, notification
settings/deliveries, storage paths, numeric DB identifiers, application UUIDs and
workspace membership internals. Estimate/price columns are not even selected unless
their options are enabled. User-authored content is intentionally retained as typed;
export does not attempt to redact secrets a manager manually types into public
project/task fields. Attachment URLs and embedded images are unsupported.

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

## Verification

The final checkpoint below records exact commands, suite totals, compatibility,
rendering and release archive details after release verification.

## Changed files

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

## Limitations and deployment

Exports run synchronously and use memory proportional to the selected project.
There is no background queue, saved document, version history, template editor,
branding, signature, individual task picker or attachment embedding. Word's font
fallback and pagination can vary across installed office applications. Very large
exports remain subject to the host's PHP memory/time limits.

No migrations, registration, recovery, verification, team management, invitations,
profile editing or billing are introduced. Nothing is deployed or pushed remotely.
