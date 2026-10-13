# Milestone 3 — Admin navigation and personal unread activity

Branch: `feature/saas-foundation`.
Starting commit: `79ed43312988c15d1e8f93a2daa3c1e71829e02b`.
Implementation date: 13 October 2026. Verification is in progress; release results are
recorded after the clean implementation checkpoint. Nothing is deployed.

## Shell and route structure

`AdminShell` is the authenticated `/admin` parent route. It owns a persistent desktop
sidebar, a compact Push/language toolbar, and the existing skip-link's main-content
target. `AdminSidebar` is reused in the desktop sidebar and native mobile dialog.
Only authenticated owner/team routes use this layout; login, clients, personal links,
`/app` and local demo pages retain their existing interface. No Team/Profile dead
links or later auth/billing UI are introduced.

`AdminProjectShell` shares the project header and localized Ideas/Clients/Structure/
Notifications/Settings navigation. It uses the existing management components and
preserves the task board/editor; the redundant project title inside the admin brief
is removed while the client header remains unchanged.

| URL                                           | Content / behavior                                                                      |
| --------------------------------------------- | --------------------------------------------------------------------------------------- |
| `/admin`                                      | All accessible projects, progress/status and personal unread counts                     |
| `/admin/projects/new`                         | Existing project creation form                                                          |
| `/admin/projects/{uuid}/brief`                | Primary idea/task board                                                                 |
| `/admin/projects/{uuid}/brief/task/new`       | Existing new-task form                                                                  |
| `/admin/projects/{uuid}/brief/task/{id}`      | Existing task editor/comments/history                                                   |
| `/admin/projects/{uuid}/brief/task/{id}/edit` | Existing task editing form                                                              |
| `/admin/projects/{uuid}/clients`              | ProjectClientManager, including existing personal links                                 |
| `/admin/projects/{uuid}/structure`            | ProjectSectionManager                                                                   |
| `/admin/projects/{uuid}/notifications`        | ProjectNotificationSettings                                                             |
| `/admin/projects/{uuid}/settings`             | Only project name/website/currency/availability settings                                |
| `/admin/projects/{uuid}`                      | Redirect to `/settings`; legacy `#client-access` redirects to `/clients` without a hash |
| `/admin/unavailable`                          | Unavailable/unauthorized project or task inside the authenticated shell                 |

All new navigation/role/loading/unread/mobile labels support RU and EN. Sidebar and
project tabs expose active state with `aria-current`; unread badges have full localized
event-count labels and hide at zero (visual counts above 99 display `99+`). Role labels
use `membershipRole`, never the legacy account `users.role`.

## Workspace switching and state isolation

`adminNavigation` bootstraps `/api/admin/me`, `/api/admin/workspaces` and the scoped
`/api/admin/projects` list in parallel. Its workspace choice is the server's UUID/name;
localStorage grants no authorization. Only server-listed workspaces appear.

Selection calls the existing POST workspace-selection endpoint. Dirty developer
fields require explicit discard confirmation. Cancel leaves the editor and draft
untouched and makes no selection request. Confirm never silently saves.

Before the selection request, the stores clear tasks, sections, project metadata,
clients, drafts, header and sidebar rows. Main content is suspended while switching,
so old data cannot appear under the new workspace label. After selection, the shell
resolves current user/membership role/workspace and project list again and routes to
`/admin`. A failed or lost response produces a global error toast and re-resolves the
server's actual selection rather than guessing that it stayed in the old workspace.
Offline recovery keeps cleared state and offers an inline retry.

Revision checks reject old task/common/client/section/navigation responses after a
clear or newer load. Existing save account/project guards remain. Login resets the
cached CSRF token after session regeneration and clears old navigation context.
Logout and session loss also clear navigation/management state. Project URLs never
select a workspace automatically: backend ProjectAccess remains authoritative.

## Mobile and accessibility

At 960px or below, the sidebar becomes a native modal `<dialog>` opened by a visible
menu button with `aria-expanded`, `aria-controls` and a localized accessible name.
The dialog makes background/off-screen controls inert and contains focus. Escape,
backdrop or close button closes it; Escape/explicit close restores trigger focus.
Route selection closes it, then the existing route/skip-link logic focuses main
content. Desktop resizing closes the mobile dialog. The desktop sidebar is `display:
none` on mobile, so its hidden duplicate controls cannot receive focus.

Navigation uses semantic nav elements, links for navigation, buttons for actions and
existing visible focus styles. Safe-area insets and `100dvh` accommodate installed
PWA mode. Sidebar projects scroll within their own region; mobile users do not scroll
through navigation before reaching content. No UI framework dependency was added.

## Personal read-state schema and exact semantics

Additive, forward-only migration:
`2026_10_13_200356_create_project_reads_table.php`.

`project_reads` contains internal PK, user_id, project_id, unsigned
last_seen_history_id (default 0), created_at and updated_at. `(user_id, project_id)` is
unique. User/project FKs cascade only these derived read-marker rows when their
parent is deliberately deleted; they do not delete domain data. Milestone 2's
restrictive workspace/project/membership constraints remain intact.

The numeric high-water mark intentionally has **no FK to task_history**. Deleting a
task and its history cannot remove/reset the marker or resurrect already-read events.
Marking read uses insert-or-ignore plus an atomic conditional increase; concurrent
requests or a smaller surviving history maximum never lower an existing watermark.

Relevant activity is exactly:

- `actor_type = client`
- `event_type IN (created, comment, approved)`
- history belongs to a task in the authorized project
- `task_history.id > this authenticated user's last_seen_history_id`

Counts represent events, not distinct tasks. Admin/developer events, other client
updates/attachments/reordering, notes/prices/estimates and notification deliveries
are excluded. A missing marker means zero, so pre-existing relevant client history
initially counts as unread. Reading as one user never changes another user's marker.
Re-granted access retains that user's prior marker. There are no per-event read flags,
notification-center records or read-state changes in Email/Push delivery tables.

## API, load timing and query strategy

- `GET /api/admin/projects`: adds integer `unreadCount` for each accessible project.
- `GET /api/admin/projects/{uuid}/activity`: current personal unread count; no mutation.
- `POST /api/admin/projects/{uuid}/activity/read`: empty body, server-derived maximum
  relevant history ID; returns current integer `unreadCount`. Supplied IDs/user fields
  are rejected. Normal session/CSRF protection applies.
- `GET /api/admin/projects/{uuid}?summary=1`: common metadata/counts without structure.
  Default project detail requests retain full sections; client resources are unchanged.

`ProjectUnread` owns the relevant-event query, list counts, state and marking logic.
Every read/mark uses existing ProjectAccess; foreign workspace, unassigned project,
revoked membership and inaccessible UUID requests are denied. No project authorization
comes from the sidebar, cached frontend state or a supplied watermark.

The list uses a correlated COUNT subquery and the unique read-marker lookup in the
same authorized project SQL query. No application-level unread query or structure
load runs per project. A new `(task_id, actor_type, event_type, id)` history index
supports this query. Tests compare query counts for one versus thirteen projects.

Common project metadata is shared across sibling project pages. First entry into
management loads summary plus its requested resource in parallel; clients fetch
clients, structure fetches sections, and settings fetches neither. Notification
preferences retain their existing component loader. Settings saves request summary
responses too. Brief/task contexts load actual project structure/tasks in parallel
without repeating admin identity loading.

Only successful authorized brief/task content loading sends the read POST. Missing
or failed content loading never sends it. Opening clients, structure, notification
preferences, settings or the sidebar never marks seen. The server determines its
current relevant maximum at the POST; activity committed between content GET and the
POST can be included in that watermark. This is project-level work-context semantics,
not a guarantee that each event was visibly inspected.

The returned count updates the matching sidebar row locally; failure retains the
badge and produces a global toast without automatic mutation replay. Counts refresh
on initial shell load, explicit workspace switch, return to `/admin`, refresh/reload
and successful work-context read updates. New project creation updates/refreshes the
sidebar and opens `/brief`. There is no polling or WebSocket infrastructure; badges
are not continuously real-time. Existing Push remains the external live alert.

## Verification

- New backend tests cover personal isolation, relevant-event filtering, high-water
  monotonicity after deleted history/tasks, new events after read, forged watermark
  rejection, foreign/unassigned/revoked access, GET non-mutation and constant list query
  count with no per-project structure load.
- New real Vue/router/store tests cover shell/client separation, sidebar/current/unread
  rendering, zero badges, RU/EN, dedicated routes and legacy redirects, resource loading,
  successful and failed marking, switch success/failure/lost-response recovery, dirty
  cancellation, cleared previous data, late response rejection, new-project refresh,
  drawer interaction and route closure. Existing Milestone 1/2 suites remain required.
- Legacy migration tests advance the actual old schema through the new read-state
  migration while retaining all previous data/credential/client-link assertions.
- Browser verification uses a separate SQLite preview with synthetic owner, two
  workspaces, assigned-admin role and projects. Desktop 1440px and mobile 390px checks
  found no horizontal overflow or JavaScript errors; Escape restores menu focus,
  route selection closes the drawer, dirty cancellation preserves the draft and a
  confirmed switch clears old content and updates the displayed membership role.
- Packaged runtime additionally checks read-state GET/POST, cross-tenant denial and
  direct SPA navigation to all four management subpages.

Screenshots: [desktop](milestone-3-desktop.png), [brief](milestone-3-brief.png),
[task editor](milestone-3-task.png), [mobile](milestone-3-mobile.png),
[mobile drawer](milestone-3-drawer.png).

## Deployment and limitations

Apply the additive migration with the existing backed-up maintenance/update procedure,
then rebuild/optimize the application. No keys, existing access links, client auth,
VAPID transport or production configuration change. Its `down()` refuses destructive
rollback; coordinated backup restoration is required for a downgrade.

Workspace selection is explicit, including opening project URLs from another workspace.
Unread is project-level and advances when entering loaded work context; it is not an
individual message-reading audit. Badges refresh at lifecycle points, not continuously.
Current metadata caching may remain stale between these points; server authorization
always validates each protected request. Milestone 4 registration, recovery,
verification, invitations, team/profile management and billing remain unimplemented.
Real external mail/Push delivery and production deployment are not performed.

## Verification results

| Check                                          | Result                                                                               |
| ---------------------------------------------- | ------------------------------------------------------------------------------------ |
| `npm test`                                     | PASS — 106 tests                                                                     |
| `npm run build`                                | PASS — production Vite build                                                         |
| `npm run format:check`                         | PASS — Prettier                                                                      |
| `php artisan test` / PHP 8.5 + SQLite          | PASS — 170 tests, 2,017 assertions                                                   |
| Full PHP 8.5 + MariaDB 11.8 suite              | PASS — 170 tests, 2,021 assertions                                                   |
| Full PHPUnit / PHP 8.4.26 + MariaDB 11.8 suite | PASS — 170 tests, 2,021 assertions                                                   |
| `vendor/bin/pint --dirty --format agent`       | PASS                                                                                 |
| Local browser preview                          | PASS — desktop/mobile, RU/EN, workspace draft protection, drawer focus/route closure |
| `./bin/build-release` / packaged runtime       | Recorded after clean implementation checkpoint                                       |

MariaDB and PHP 8.4 checks use separate disposable resources and synthetic data,
including legacy schema fixtures. Existing databases were not upgraded or reset.
Application dependency versions remain unchanged; GD was added only to the test image.

## Changed files

- `README.md`
- `api/app/Http/Controllers/ProjectController.php`
- `api/app/Http/Resources/ProjectResource.php`
- `api/routes/web.php`
- `api/tests/Feature/WorkspaceMigrationTest.php`
- `docs/saas-roadmap.md`
- `src/App.vue`
- `src/assets/main.css`
- `src/components/AppIcon.vue`
- `src/components/ProjectHeader.vue`
- `src/locales/en.js`
- `src/locales/ru.js`
- `src/router/index.js`
- `src/stores/project.js`
- `src/stores/projectManagement.js`
- `src/views/AdminLoginView.vue`
- `src/views/AdminProjectFormView.vue`
- `src/views/AdminProjectView.vue` (removed)
- `src/views/AdminProjectsView.vue`
- `tests/release-runtime.mjs`
- `api/app/Http/Controllers/ProjectActivityController.php`
- `api/app/Models/ProjectRead.php`
- `api/app/Services/ProjectUnread.php`
- `api/database/migrations/2026_10_13_200356_create_project_reads_table.php`
- `api/tests/Feature/ProjectUnreadTest.php`
- `docs/milestone-3-brief.png`
- `docs/milestone-3-desktop.png`
- `docs/milestone-3-drawer.png`
- `docs/milestone-3-mobile.png`
- `docs/milestone-3-task.png`
- `docs/milestone-3.md`
- `src/components/AdminSidebar.vue`
- `src/components/UnreadBadge.vue`
- `src/stores/adminNavigation.js`
- `src/utils/adminDrawer.js`
- `src/views/AdminProjectClientsView.vue`
- `src/views/AdminProjectNotificationsView.vue`
- `src/views/AdminProjectSettingsView.vue`
- `src/views/AdminProjectShell.vue`
- `src/views/AdminProjectStructureView.vue`
- `src/views/AdminProjectUnavailableView.vue`
- `src/views/AdminShell.vue`
- `tests/admin-navigation.test.js`
