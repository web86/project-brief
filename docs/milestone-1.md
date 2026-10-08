# Milestone 1 — UX and notification fixes

Implemented on `feature/saas-foundation`, 8 October 2026.
Starting commit: `43eaedd974297c24fb8bdb87013cb25b50eb05f4`.
No database migrations, dependency changes or production deployment.

## Developer editing

Estimate hours, price and developer notes now use a local draft separate from the
canonical server task. Typing, pauses, blur/change, route changes, logout, project
loading and ordering never silently PATCH those fields. One explicit **Save changes**
(**Сохранить изменения**) sends only currently changed developer fields for one task.
The existing page layout and design language are retained.

A dirty indicator appears when the draft differs from saved values. The Save button
has a loading state and blocks duplicate requests. Success applies the authoritative
server response and a global success toast. Failure preserves the draft and gives a
global error toast; retry uses the current draft. Text typed during a save remains
unsaved when it differs from the response. Responses from a previous project/persona
cannot populate the new editor.

Cmd+S and Ctrl+S are intercepted only while TaskView is mounted, the developer editor
is active and the task is dirty. Repeated shortcuts do not duplicate requests. Clean
editors and other pages retain the browser shortcut. Internal navigation (including
another task), logout and demo persona changes ask before discarding drafts. Cancel
keeps the editor and draft. `beforeunload` exists only while dirty; browser-controlled
reload/close confirmation wording depends on the browser. Nothing warns when clean.

Drafts are memory-only. There is no new localStorage draft or persistence layer; no
authentication secrets are stored. Confirming discard/reload/close loses the unsaved
draft. Existing demo snapshot storage contains only explicitly saved developer fields.

The backend's existing dirty-field audit behavior is retained and tested: changed
estimate/price retain old/new values; notes create one content-free event per explicit
save; identical fields produce no events. Status, comment, approval, creation and
ordering keep their existing immediate semantics.

## Notification content and localization

The existing path remains TaskAudit → ProjectNotificationRequested →
ProjectNotificationService → NotificationPayload → ProjectActivityMail → HTML/text.
CommentController records only a server-derived `commentId` in the existing history
JSON metadata, in the same transaction as the comment. NotificationPayload loads that
history through the task, validates the mapped event and author identity, and loads
the exact comment through the task relationship. It never guesses the latest comment
or trusts a frontend message snapshot. Event constructors remain unchanged. Delivery,
deduplication, after-commit behavior and safe session-based task URLs are preserved.

| Event                           | Recipient                                       | Human-authored content         | Explanation / next action                                         |
| ------------------------------- | ----------------------------------------------- | ------------------------------ | ----------------------------------------------------------------- |
| `client.task_created`           | Existing primary admin / configured admin email | New idea description excerpt   | Named client added an idea; open it for review                    |
| `client.comment_created`        | Existing primary admin / configured admin email | Exact client comment           | Named client commented; open the task to reply                    |
| `client.task_approved`          | Existing primary admin / configured admin email | None                           | Named client approved the task; view approved scope               |
| `admin.comment_created`         | Eligible active clients                         | Exact developer public comment | Named developer commented; open the task to reply                 |
| `admin.clarification_requested` | Eligible active clients                         | None                           | Clarification is needed; open the task and answer                 |
| `admin.task_review`             | Eligible active clients                         | None                           | Work is ready; check the result and comment if changes are needed |
| `admin.task_done`               | Eligible active clients                         | None                           | Work is completed; view the completed task                        |

Every mail includes the project, canonical task number/title, an event-specific
subject and heading, next action, CTA, and the existing access/session note. HTML
uses inline CSS and a presentation table; Blade escapes all human-authored values.
The plain-text alternative contains the equivalent content as literal text. Push
keeps its existing seven-field allowlist, VAPID transport, recipient/device ownership,
endpoint validation and delivery tracking. Comment Push adds a short actor/message
preview; other events retain task context. Private developer fields never enter the
mail or Push payload.

Client system copy uses `project_clients.preferred_locale` (`ru`/`en`, existing `en`
fallback). Admin copy continues to use `NOTIFICATION_ADMIN_LOCALE` (existing `ru`
fallback). Comments and descriptions retain their original language, even when the
surrounding system copy is in another language. No user-level admin locale is added.

## Content bounds and limitations

Bounds in `NotificationPayload` count UTF-8 Unicode characters, not bytes:

- Comments: 2,000 characters; shorter comments are included in full.
- New idea description: 600 characters.
- Push body: 150 characters, with whitespace collapsed in comment previews.
- Push title: 120 characters; subject project context: 80 characters.

Truncated content adds `…` after the bound. The CTA opens the full task and comment
thread. Multibyte characters are never split; literal markup is escaped in HTML mail
and remains literal text in the plain-text alternative.

Clarification currently changes status before focusing the public comment form; it
has no stable association with a question. Its mail therefore gives clear instructions
without attaching an unrelated earlier comment. Sending the subsequent question
produces the normal comment email containing that exact question. Historical comment
audit rows without a `commentId` omit message content safely if explicitly replayed.

No live SMTP or external Push delivery was attempted. Tests fake transport and verify
rendered content, recipient selection, configuration handling and delivery tracking.
Workspace, membership, registration, queue/Redis and authentication changes are outside
this milestone.

## Verification

- `npm test`: 88 tests, all passing (includes existing suites and real Vue/router/editor tests).
- `php artisan test`: 143 tests, 1,354 assertions, all passing, using isolated SQLite.
- `npm run build`: production Vite build passes.
- `npm run format:check`: passes.
- `vendor/bin/pint --dirty --format agent`: passes.
- Release package/runtime verification: recorded after the clean implementation checkpoint.

New frontend coverage includes no timer PATCH, dirty/revert behavior, one Save PATCH,
success/error global toasts, retained failed drafts, edits during pending saves,
Ctrl/Cmd+S and active/clean scope, conditional beforeunload, navigation cancellation,
logout cancellation/failure, clean navigation and project reload/move/reorder without
autosave. Real Vue templates verify RU/EN controls and the actual textarea/Save action.

Backend coverage verifies meaningful changed-only audit records, no duplicate notes,
unchanged numeric values, all seven event types in RU/EN HTML and text, useful subjects,
recipient locale and next actions, original-language content, exact comment selection,
cross-task reference rejection, escaped HTML, Unicode truncation, concise Push and no
private fields/tokens/storage paths in client notification output. Existing status,
comment, approval, session/access, subscription and release tests remain passing.

## Changed files

Frontend:

- `src/App.vue`
- `src/assets/main.css`
- `src/components/DeveloperNotes.vue`
- `src/components/DeveloperTaskPanel.vue`
- `src/components/ModeSwitcher.vue`
- `src/locales/en.js`
- `src/locales/ru.js`
- `src/router/index.js`
- `src/stores/project.js`
- `src/utils/developerEditor.js`
- `src/views/TaskView.vue`

Backend and mail:

- `api/app/Http/Controllers/CommentController.php`
- `api/app/Services/NotificationPayload.php`
- `api/lang/en/notifications.php`
- `api/lang/ru/notifications.php`
- `api/resources/views/mail/project-activity.blade.php`
- `api/resources/views/mail/project-activity-text.blade.php`

Tests:

- `api/tests/Feature/ProjectNotificationTest.php`
- `api/tests/Feature/TaskPermissionsTest.php`
- `tests/developer-editor.test.js`
- `tests/project.test.js`
- `tests/views.test.js`

Documentation:

- `README.md`
- `docs/backend-verification.md`
- `docs/developer-workspace.md`
- `docs/milestone-1.md`
- `docs/saas-roadmap.md`
