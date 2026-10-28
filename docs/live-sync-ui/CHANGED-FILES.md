# Изменённые файлы

Полный перечень файлов реализации, тестов и упаковки относительно базы 7.7. Материалы QA в этой папке перечислены отдельно и исключены из production ZIP.

- `.gitignore`
- `DEPLOY-JINO.md`
- `api/app/Console/Commands/CleanupProjectFiles.php`
- `api/app/Http/Controllers/AdminAuthController.php`
- `api/app/Http/Controllers/LiveSyncController.php`
- `api/app/Http/Controllers/ProjectDiscussionController.php`
- `api/app/Http/Controllers/ProjectFeedbackController.php`
- `api/app/Http/Controllers/TaskController.php`
- `api/app/Http/Controllers/WorkspaceBrandingController.php`
- `api/app/Http/Requests/TaskRequest.php`
- `api/app/Http/Resources/ProjectResource.php`
- `api/app/Http/Resources/TaskResource.php`
- `api/app/Models/Task.php`
- `api/app/Observers/LiveSyncObserver.php`
- `api/app/Providers/AppServiceProvider.php`
- `api/app/Services/CompanyWebsite.php`
- `api/app/Services/LiveSync.php`
- `api/app/Services/ProjectDiscussions.php`
- `api/app/Services/ProjectFeedbacks.php`
- `api/app/Services/ProjectUnread.php`
- `api/app/Services/TaskAudit.php`
- `api/database/migrations/2026_10_28_151049_create_project_sync_events_table.php`
- `api/lang/en/branding.php`
- `api/lang/ru/branding.php`
- `api/lang/tr/branding.php`
- `api/lang/zh-CN/branding.php`
- `api/routes/web.php`
- `api/tests/Feature/LiveSyncTest.php`
- `api/tests/Feature/ProjectFeedbackTest.php`
- `api/tests/live-sync-fixture.php`
- `deployment/build-release.mjs`
- `public/sw.js`
- `src/App.vue`
- `src/assets/main.css`
- `src/components/DeveloperNotes.vue`
- `src/components/FeedbackRequestButton.vue`
- `src/components/TaskForm.vue`
- `src/i18n/index.js`
- `src/locales/en.js`
- `src/locales/ru.js`
- `src/locales/tr.js`
- `src/locales/zh-CN.js`
- `src/services/branding.js`
- `src/services/liveSync.js`
- `src/stores/adminNavigation.js`
- `src/stores/discussions.js`
- `src/stores/project.js`
- `src/stores/workspaceBranding.js`
- `src/views/AdminBrandingView.vue`
- `src/views/AdminNotificationsView.vue`
- `src/views/AdminProjectDiscussionsView.vue`
- `src/views/AdminProjectsView.vue`
- `src/views/TaskView.vue`
- `tests/developer-editor.test.js`
- `tests/fixtures/company-websites.json`
- `tests/live-sync.browser.mjs`
- `tests/live-sync.test.js`
- `tests/notifications.test.js`
- `tests/release-runtime.mjs`
- `tests/ui-feedback.test.js`

## Отчёт и визуальные материалы

`README.md`, `TESTS.md`, `PRODUCTION.md`, `CHANGED-FILES.md`, `COMPARISONS.md`, JSON результатов/нагрузки/SQL, 12 пар сравнительных PNG, `feedback-review.png`, `website-invalid.png`, `RELEASE-ARTIFACT.json`.
