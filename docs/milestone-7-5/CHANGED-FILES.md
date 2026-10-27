# Milestone 7.5 — изменённые файлы

Единственная новая миграция: `api/database/migrations/2026_10_27_215858_add_branding_to_workspaces.php`.

Код, тесты, builder и эксплуатационное руководство:

- `.gitignore`
- `DEPLOY-JINO.md`
- `api/app/Http/Controllers/ProjectController.php`
- `api/app/Http/Controllers/SpaController.php`
- `api/app/Http/Controllers/SpaController.php`
- `api/app/Http/Controllers/WorkspaceBrandingController.php`
- `api/app/Http/Resources/ProjectResource.php`
- `api/app/Mail/AccountActionMail.php`
- `api/app/Models/Workspace.php`
- `api/app/Services/AccountMail.php`
- `api/app/Services/BrandImage.php`
- `api/app/Services/ClientInvitations.php`
- `api/app/Services/DocxProjectExporter.php`
- `api/app/Services/ProjectExportData.php`
- `api/app/Services/ProjectFeedbacks.php`
- `api/app/Services/ProjectNotificationService.php`
- `api/app/Services/WorkspaceBranding.php`
- `api/database/migrations/2026_10_27_215858_add_branding_to_workspaces.php`
- `api/lang/en/branding.php`
- `api/lang/ru/branding.php`
- `api/lang/tr/branding.php`
- `api/lang/zh-CN/branding.php`
- `api/resources/views/mail/account-action-text.blade.php`
- `api/resources/views/mail/account-action.blade.php`
- `api/resources/views/mail/brand-footer.blade.php`
- `api/resources/views/mail/brand-header.blade.php`
- `api/resources/views/mail/project-activity-text.blade.php`
- `api/resources/views/mail/project-activity.blade.php`
- `api/routes/web.php`
- `api/tests/Feature/ProjectPaginationTest.php`
- `api/tests/Feature/WorkspaceBrandingTest.php`
- `deployment/build-release.mjs`
- `src/App.vue`
- `src/assets/main.css`
- `src/components/AdminSidebar.vue`
- `src/components/AppIcon.vue`
- `src/components/ClientSidebar.vue`
- `src/components/PortalBrandLogo.vue`
- `src/components/ProjectRecycleBin.vue`
- `src/components/ProjectSidebar.vue`
- `src/components/SidebarScroll.vue`
- `src/components/TaskNavigator.vue`
- `src/locales/en.js`
- `src/locales/ru.js`
- `src/locales/tr.js`
- `src/locales/zh-CN.js`
- `src/router/index.js`
- `src/services/branding.js`
- `src/services/pdf/definition.js`
- `src/stores/adminNavigation.js`
- `src/stores/projectListing.js`
- `src/stores/workspaceBranding.js`
- `src/views/AdminBrandingView.vue`
- `src/views/AdminProjectsView.vue`
- `src/views/AdminShell.vue`
- `src/views/NotFoundView.vue`
- `tests/branding-export.browser.mjs`
- `tests/branding-pagination.browser.mjs`
- `tests/branding.test.js`
- `tests/release-runtime.mjs`

Материалы этапа: README.md, PRODUCTION.md, TESTS.md, CHANGED-FILES.md, BROWSER-RESULTS.json, EXPORT-RESULTS.json, RELEASE-ARTIFACT.json, screenshots/ и export-previews/.

Небольшая сопутствующая правка: NotFoundView.vue получил недостающий импорт локализации t — ошибка обнаружена при проверке сброса бренда на общем маршруте404.
