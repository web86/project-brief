# Project Brief

Клиент описывает изменение сайта своими словами, разработчик получает структурированную идею, обсуждение и историю. Vue 3 / Vite / Pinia / Vue Router / vanilla CSS остаются основой интерфейса. Backend — Laravel 13.34, Eloquent и MariaDB, без JWT и публичной регистрации.

## Требования

- Node.js 20.19+ или 22.12+, npm (проверено на Node 22.23.2).
- PHP 8.3+; `pdo_mysql`, `pdo_sqlite` для тестов, `mbstring`, `openssl`, `fileinfo`, `zip`, стандартные Laravel extensions. `gd` используется фабрикой изображений в тестах. Зависимости Composer закреплены для PHP 8.3.30; проверено на PHP 8.5.10.
- Composer 2.
- MariaDB (проверено на 11.8). Для необязательного Compose-сценария — Docker/OrbStack.

## Локальный запуск с API

Из корня репозитория:

```bash
npm install
cp .env.example .env
```

В корневом `.env` оставьте `VITE_DATA_SOURCE=api`, пустой `VITE_API_BASE_URL` и `API_PROXY_TARGET=http://127.0.0.1:8000`. API вызывается по относительным адресам через Vite proxy; CORS не требуется.

Можно использовать собственную MariaDB или отдельный контейнер этого проекта. Для контейнера задайте в корневом `.env` два различных случайных пароля `DB_PASSWORD` и `DB_ROOT_PASSWORD`, затем:

```bash
docker compose up -d db
```

Контейнер слушает только `127.0.0.1:3307`; база и пользователь — `project_brief`, данные сохраняются в отдельном Docker volume. Другие базы не изменяются. Без Docker настройте свою MariaDB и соответствующий порт.

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
```

Укажите `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` в `api/.env`. При Compose пароль пользователя должен совпадать с корневым `DB_PASSWORD`.

```bash
php artisan migrate
php artisan app:create-admin
```

Команда интерактивно спрашивает имя, email и скрытый пароль (от 12 символов); сохраняется Laravel password hash. Пароль не передавайте через аргументы командной строки.

В двух терминалах из корня:

```bash
npm run api:dev
```

```bash
npm run dev
```

Vue: [http://127.0.0.1:5173](http://127.0.0.1:5173), Laravel: `http://127.0.0.1:8000`. Откройте [/admin/login](http://127.0.0.1:5173/admin/login), войдите, создайте проект и выдайте ссылку клиенту. Клиент открывает её в другом браузере/профиле и сразу работает с проектом. Один браузерный профиль содержит одну текущую личность: открытие клиентской ссылки завершает admin session в этом профиле.

`npm run api:dev` использует стандартный `php artisan serve`, включает dev PHP-лимиты: 20 МБ на файл, 210 МБ на multipart request, 10 файлов. Режим `--quiet` предотвращает вывод URL с access token в консоль. При запуске Laravel другими способами настройте PHP-лимиты самостоятельно. Приложение дополнительно проверяет 20 МБ / 10 файлов.

### Демо-данные

```bash
cd api
php artisan db:seed
```

В `local`/`testing` seeder создаёт девять идей с шестью статусами, если проектов ещё нет. Повторный запуск не дублирует данные. Для необязательного демо-администратора задайте **свой** `DEMO_ADMIN_PASSWORD` в `api/.env` перед seed; email — `admin@example.test`. Без этой переменной admin не создаётся; используйте CLI. Production seeder не создаёт демо, `DemoSeeder` отдельно отказывается работать в production. Автоимпорта browser localStorage нет.

На текущей dev-машине Composer установлен локально в `.tools/composer.phar`; из `api/` можно использовать `php ../.tools/composer.phar install` вместо `composer install`. Этот инструмент исключён из Git; при новом clone требуется обычный Composer 2.

## Режимы frontend

- `VITE_DATA_SOURCE=api`: единственный источник данных — Laravel/MariaDB. Pinia служит кешем; localStorage не читается и не записывается. Роль определяется серверной сессией, переключателя режима нет.
- `VITE_DATA_SOURCE=local`: прежний демонстрационный режим **при разработке**, без backend, с localStorage `project-brief:v1` и ModeSwitcher. Существующие данные сохраняются. Для переключения перезапустите Vite.
- Production build всегда использует API; demo ModeSwitcher в production недоступен.

Добавлены client edit route `/project/:uuid/task/:id/edit` и `DELETE /api/client/tasks/{uuid}`. Существующие `addTask`, `updateTask`, `changeStatus`, `approveTask`, `addComment`, `updateDeveloperData` сохранены. В API-режиме компоненты ожидают серверный ответ, комментарии и согласование блокируют повторный submit. Технические поля сохраняются автоматически с коротким debounce и последовательной очередью; drafts остаются в памяти при ошибке, есть повторное сохранение. Перед переходом между страницами и при потере фокуса поле отправляется на сервер. Дождитесь завершения сохранения перед перезагрузкой страницы.

## Структура

```text
src/api/client.js           HTTP, session cookies, CSRF, безопасные ошибки
src/stores/project.js      Pinia tasks, canonical numbers, API cache и explicit developer save
src/stores/projectManagement.js  sections/clients/settings API actions
src/utils/structure.js     grouping, canonical ordering, local snapshot migration
src/router/index.js        local routes, client project, admin guards
src/components/            прежний клиентский/developer UI, ProjectFields
src/views/                 ProjectView, TaskView, TaskFormView,
                           AdminLoginView, AdminProjectsView,
                           AdminProjectFormView, AdminProjectView, AccessErrorView
api/app/Models/            User, Project, ProjectSection, ProjectClient, ProjectAccessToken, Task,
                           Comment, Attachment, TaskHistory
api/app/Http/Requests/     валидация и whitelist клиентских/админских полей
api/app/Http/Middleware/   EnsureAdmin, EnsureClientProjectAccess
api/app/Http/Resources/    ProjectResource, TaskResource
api/app/Services/          TaskAccess, TaskAudit, AttachmentStorage
api/app/Rules/             SafeAttachment
api/database/migrations/  users/sessions, cache,
                           projects, project_access_tokens, tasks,
                           comments, attachments, task_history
api/tests/Feature/         auth, проекты, ссылки, права, комментарии,
                           вложения, CSRF/cookies/rate limits
```

Frontend URLs: `/admin/login`, `/admin`, `/admin/projects/new`, `/admin/projects/:uuid`, admin workspace `/admin/projects/:uuid/brief`, client workspace `/project/:uuid`; идеи — `/task/new` и `/task/:id` внутри соответствующего workspace. UUID используются в публичных project/task/attachment URLs. Прежние `/`, `/task/new`, `/task/:id` работают в local demo.

Основной интерфейс сохранён: свободное место на сайте/URL, группировка, desktop 3 колонки, mobile 1 колонка, overlay завершённых идей и стабильная сортировка, комментарии, независимое согласование объёма ТЗ, контекстные developer actions, оценка, стоимость по валюте проекта, технические заметки и история.

## Структура ТЗ и обновление существующей базы

Раздел ТЗ и свободный `location` — разные поля. Клиент вводит любое название или URL в editable combobox. Backend использует раздел с точно совпадающим именем после trim или создаёт новый в конце; регистр и URL не переписываются. Developer меняет порядок прямо в заголовках разделов и карточках, а также может перенести задачу через свой panel. Номер вычисляется централизованно из `project_sections.position` и `tasks.position`, не хранится строкой и не зависит от статуса/фильтров. Завершённые карточки уходят вниз своего раздела только визуально. После create/move/reorder/delete позиции непрерывны; пустые разделы сохраняются и показываются компактно, чтобы заголовки шли подряд. Явные перемещения меняют номера; move/reorder нормализуют позиции и пишут историю. Структурные изменения и создание задач сериализованы transaction + блокировкой строки проекта. Раздел с задачами удалить нельзя.

Добавлены две migrations: `2026_10_05_204121_add_project_structure_and_client_identity` и `2026_10_05_204122_backfill_legacy_project_structure`. Первая создаёт `project_sections` и `project_clients`; ALTER TABLE добавляют nullable `tasks.project_section_id`, `tasks.position`, nullable `project_client_id` в tokens/comments/history/attachments. UUID unique; indexes `(project_id, position)`, `(project_id, active)`, `(project_section_id, position)`; FK на project cascade только при удалении самого проекта, на section/client — SET NULL. UI не удаляет клиентов физически.

Backfill проходит проекты по ID; legacy `tasks.section` определяет разделы по первому появлению (task ID), пустые — «Общее». `location` не используется. Записываются только новые связи/позиции. Конфигурированные пустые разделы добавляются после разделов с задачами. Legacy `client_name/client_email` создают первого клиента; при наличии старого token клиент создаётся обязательно, даже без имени. Старые hashes/expiry/revocation не меняются, tokens получают ссылку на первого клиента. Старым comments/history/attachments не назначается предположительный автор. Повторный backfill идемпотентен.

**Deprecated, сохранены:** `projects.client_name`, `projects.client_email`, `projects.sections`, `tasks.section`. Новый UI их не редактирует. Старые localStorage snapshots version 1 обновляются добавлением associations/positions, без потери контента и использования URL как раздела; невалидные snapshots не перезаписываются.

Migration review: удалений таблиц/колонок/данных в `up()` нет. DDL MariaDB может ожидать metadata locks или перестраивать таблицы при добавлении FK/index; backfill блокирует один проект до завершения его transaction. Время зависит от объёма реальной базы. Применять в штатном maintenance update с проверенной резервной копией; ручной SQL не нужен. DDL MySQL не является общей transaction: проверки существования новых колонок/индекса поддерживают повторный запуск после прерывания. Schema migration намеренно forward-only: `down()` отказывается удалять новые production associations; откат через проверенную резервную копию. Backfill `down()` ничего не удаляет. Production база в этой задаче не изменяется.

## RU / EN и loading

`vue-i18n` 11, Composition API; dictionaries `src/locales/ru.js` и `en.js`, одинаковые 353 keys. Переводятся UI constants, включая statuses, priorities, ошибки, history summaries и dialogs. Project/section/task content, comments, notes, URLs и filenames не переводятся. `Intl.DateTimeFormat`: ru-RU / en-GB; `Intl.NumberFormat` использует locale и валюту проекта. Counts используют pluralization vue-i18n с русским правилом.

Приоритет: ручной `projectbrief.locale` в localStorage → session client's `preferred_locale` → первый browser language из navigator.languages (fallback navigator.language) → en. ru* → ru, остальные → en. RU | EN в header меняет UI и `<html lang>` без reload. Только ручной client switch вызывает `PATCH /api/client/me/locale`; ID берётся из сессии, body принимает только ru/en. Browser detection не записывается в DB. Admin preference хранится только в localStorage; TR/DE dictionaries пока отсутствуют.

Page loaders — fixed overlay; button loaders сохраняют label footprint и размеры; developer save status — fixed toast. JSON requests имеют timeout 20 s, uploads с реальными файлами — минимум 120 s. Timeout не вызывает automatic replay mutation; loading заканчивается с переводимым сообщением.

Полное удаление draft требует confirmation. `ClientTaskDeletion` блокирует project/task, сохраняет temporary private backups, проверяет полноту копии, удаляет только принадлежащие задаче files, затем task и FK-related comments/history/metadata. Позиции нормализуются, empty section остаётся. Shared legacy file сохраняется, если на него ссылается другая задача. При ошибке DB rollback и восстановление удалённых файлов; API возвращает 503. Если storage мешает восстановлению, backup сохраняется в private `.deletion-recovery`, путь записывается только в server log для восстановления разработчиком. Неполная очистка не сообщается как success.

## Сессии и доступ клиента

Admin auth: `POST /api/admin/login`, `POST /api/admin/logout`, `GET /api/admin/me`. `GET /api/csrf` выдаёт CSRF token; изменения защищены Laravel web middleware. Cookie session — HttpOnly, SameSite=Lax, Secure в production. Вход admin и успешный доступ клиента меняют session ID. Login/access/comments/uploads имеют rate limits.

Access link содержит 32 случайных байта (256 bits), представленных 64 hex символами. В `project_access_tokens` хранится только SHA-256 hash. URL возвращается **один раз**, находится только в текущем Vue component state; после ухода со страницы его нельзя получить повторно. UI показывает только метаданные, last used/expiry/revocation. Пересоздание атомарно отзывает прежние ссылки только выбранного клиента. У каждого клиента собственная ссылка; отключение клиента отзывает его ссылки, повторное включение требует новой ссылки.

`GET /access/{token}` проверяет hash, срок, отзыв и активность проекта, создаёт минимальную client session и перенаправляет на `/project/{uuid}`. Token исчезает из URL; redirect использует `Referrer-Policy: no-referrer` и `Cache-Control: no-store`. В логах приложения token не записывается. Внешний reverse proxy должен отключать/маскировать access logging для `/access/*`.

**На каждом клиентском запросе** middleware заново проверяет token, проект и активного клиента, а также принадлежность token клиенту и проекту. Отзыв блокирует существующую сессию на следующем запросе. Tasks, comments, uploads, download и preview выбираются только в проекте client session; чужие UUID получают 404 без выдачи данных. Подмена author/project/approval/history/status/developer fields отклоняется. Клиент редактирует или полностью удаляет исходную идею только в `new`/`clarification` при `client_approved=false`; далее обсуждает изменения в комментариях. Это проверяется сервером при PATCH, DELETE и добавлении файлов. Edit использует ту же форму; изменение free-form раздела находит/создаёт target и нормализует обе группы.

Client TaskResource вообще не содержит `developerNotes`, `estimateHours`, `price` и внутренних history events. Client project не содержит client email/admin metadata. Сервер задаёт авторов комментариев и создаёт audit events: создание/изменение идеи, статус с old/new, согласование, комментарий, файл, оценка, цена, заметки. Содержимое private notes не копируется в историю.

Согласование — согласие клиента на содержание/объём ТЗ. `client_approved_at` записывается один раз, status при этом не меняется. Приёмка готового результата оставлена на следующий этап.

## API

| Область              | Endpoints                                                                                                                                                                     |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Session              | `GET /api/csrf`, `POST /api/admin/login`, `POST /api/admin/logout`, `GET /api/admin/me`                                                                                       |
| Admin projects       | `GET/POST /api/admin/projects`, `GET/PATCH /api/admin/projects/{uuid}`                                                                                                        |
| Access links         | `POST /api/admin/projects/{uuid}/clients/{client}/access-links` (create/rotate, optional `expiresAt`), `DELETE /api/admin/projects/{uuid}/clients/{client}/access-links/{id}` |
| Admin clients        | `GET/POST /api/admin/projects/{uuid}/clients`, `PATCH /api/admin/projects/{uuid}/clients/{client}`                                                                            |
| Admin sections       | `GET/POST /api/admin/projects/{uuid}/sections`, `PATCH/DELETE /api/admin/projects/{uuid}/sections/{section}`                                                                  |
| Task structure       | `POST /api/admin/tasks/{uuid}/move` (`sectionId`), `POST /api/admin/tasks/{uuid}/reorder` (`direction: up/down`)                                                              |
| Current client       | `PATCH /api/client/me/locale` (`locale: ru/en`); `GET /api/client/me` — имя/email только текущего клиента                                                                     |
| Admin tasks          | `GET/POST /api/admin/projects/{uuid}/tasks`, `GET/PATCH /api/admin/tasks/{uuid}`                                                                                              |
| Client project/tasks | `GET /api/client/project`, `GET /api/client/project/{uuid}`, `GET/POST /api/client/tasks`, `GET/PATCH /api/client/tasks/{uuid}`, `POST /api/client/tasks/{uuid}/approve`      |
| Comments             | `POST /api/{admin,client}/tasks/{uuid}/comments`                                                                                                                              |
| Attachments          | `POST /api/{admin,client}/tasks/{uuid}/attachments`, `GET /api/{admin,client}/attachments/{uuid}/download`, `GET /api/{admin,client}/attachments/{uuid}/preview`              |

Payloads/responses используют совместимые с Vue camelCase поля. Tasks можно создавать JSON или multipart с `attachments[]`. API errors: 401 с завершённой session, 403 при запрещённом workflow, 404 для недоступных записей, 419 при CSRF, 422 для полей, 429 для rate limit. Frontend не показывает server/SQL stack traces и не повторяет изменения автоматически при ошибке CSRF.

## Файлы

JPG/JPEG/PNG/WEBP/PDF/DOC/DOCX/TXT/ZIP: extension проверяется вместе с фактическим MIME, изображения декодируются, ZIP/DOCX проверяются как архивы; DOCX с macros отклоняется. Случайный stored filename создаётся сервером; оригинальное имя очищается и не определяет путь. Файлы находятся в `api/storage/app/private`, без public symlink, base64 в DB и публичных URL. Скачивание/превью требуют соответствующей session, имеют `nosniff` и private/no-store headers. Документы скачиваются как attachment; inline preview разрешён только изображениям. Создание идеи и файлов выполняется атомарно с cleanup при неудаче.

## Проверки

```bash
npm test
npm run build
npm run format:check
cd api
php artisan test
vendor/bin/pint --dirty --format agent
```

Feature tests используют отдельную SQLite `:memory:` и fake storage, не трогают dev MariaDB. Fresh migrations/seed проверены отдельно в новой выделенной dev-базе. `migrate:fresh` удаляет данные выбранной базы, поэтому используйте его только в disposable dev/test environment.

Проверка этапа sections/clients: 73 Laravel tests (386 assertions), 35 frontend tests, production build и formatting — PASS. Дополнительно на PHP 8.4.26 / MariaDB 11.8 / Apache проверено обновление распакованного предыдущего release: все старые rows, файлы и token сохранены, backfill повторяемый, fresh migrations работают. Реальные 5 параллельных create/move/reorder через Apache дают уникальные нормализованные позиции; первый consistent read выполняется после project lock (учтён MariaDB REPEATABLE READ). В двух browser sessions John/Anna проверен независимый revoke, авторы comments, numbering, done filter/overlay; desktop 3 колонки, mobile 390 px одна колонка без horizontal scroll, console без warnings/errors. Generated releases остаются вне Git.

Результаты и browser screenshots: [проверка backend/API](docs/backend-verification.md). Проверки прошлых этапов: [UX](docs/ux-cleanup.md), [developer workspace](docs/developer-workspace.md), [завершённые карточки](docs/completed-tasks.md).

## Локальная production-сборка для Jino

Из чистого, закоммиченного проекта:

```bash
./bin/build-release
```

Готовый ZIP — в `release/`, распакованный пакет — `release/project-brief/`. `private/project-brief-app` содержит Laravel и production vendor; `public` — Vue build, PHP entry point и Apache `.htaccess`. Реальные `.env`, uploads, local DB, dev dependencies, tests и source maps исключены; до архива выполняются frontend/Laravel tests, build, проверка secrets и запуск копии готового пакета. Серверу не нужны Node/npm/Composer/Git.

Инструкция: [DEPLOY-JINO.md](DEPLOY-JINO.md). Target: `https://brief.web86.site`, private app `~/project-brief-app`, public `~/domains/brief.web86.site`, DB `specchina_breaf_tz`, PHP 8.4. Один HTTPS origin, Vue history fallback через Laravel, серверные `/api`, `/access`, `/sanctum` не попадают в SPA. `GET /api/health` возвращает только liveness `{"ok":true}`; CLI `bin/check-server` дополнительно проверяет DB и окружение, `bin/first-install` сохраняет ключ/данные и выполняет migrations/optimize с интерактивным созданием admin. В этой итерации загрузка на Jino не выполняется; существующий production работает отдельно от локальных проверок.

## Проверка текущего UX / RU-EN этапа

89 Laravel tests / 532 assertions, 46 frontend tests, translation parity (306 keys), Vue build и formatting — PASS. Server tests покрывают все draft/locked states, agreement даже при status=new, cross-project/crafted requests, удаление связанных rows/files, rollback при частичном удалении, неполную backup-copy и shared legacy file. Frontend tests рендерят реальные Vue views, проверяют Edit/Delete visibility, confirmation/cancel/navigation, locale priority и detection, persistence, HTML lang, dates/currency/plurals и abort timeout.

В MariaDB browser flow проверены free-form «Корзина», edit с переносом в «Каталог» и независимым URL, comments и agreement с исчезновением draft actions; inline reorder и Done filter сохраняют canonical numbers. Все 7 loading buttons измерены с замедлением запросов: width/height неизменны. Desktop — 3-column grid. Финальная mobile/console проверка повторена в отдельном local-demo: 390 px / одна колонка / без horizontal scroll / без JS warnings/errors. Во время проверки локальный Docker/MariaDB runtime временно перестал отвечать; production не затрагивался и глобальный OrbStack без разрешения не перезапускался. Release validation использует собственную isolated SQLite/HOME, не dev/production DB.

## Следующий этап

AI API/разделение большой идеи, password reset/2FA, команды и роли, платежи, result acceptance и realtime пока не реализованы. Backend обслуживает существующий developer workflow; отдельного Laravel frontend/CRM нет.

## Installed ProjectBrief app and long-lived sessions

The PWA manifest launches `/app` in standalone mode with scope `/`. One uncached
`GET /api/session` decides the current server persona: admin → `/admin`, valid
client → `/project/{uuid}`, guest → a neutral RU/EN access screen with an optional
administrator sign-in link. A connection failure shows a retry, rather than a
false guest state. `/` in production also enters this launcher; local demo routes
retain their existing behavior. `/api/health` remains a session-free liveness check.

Clients still authenticate only through their developer's personal `/access/{token}`
URL. Open that URL, enter the project, then install ProjectBrief from the browser's
installation/home-screen control. Future launches open the client's project when
the Laravel session is valid. If the installed browser profile loses its session,
open the personal link in that profile again. Client passwords and email magic links
are not introduced. No access token or token hash is stored in localStorage or in
the manifest. Links without `expires_at` remain reusable until revoked.

Production defaults/examples recommend database sessions with
`SESSION_LIFETIME=525600` and `SESSION_EXPIRE_ON_CLOSE=false`: a finite 365-day idle
lifetime, renewed by activity. Secure, HttpOnly, SameSite=Lax, session encryption,
CSRF protection and explicit admin logout remain unchanged. Every protected client
request and the bootstrap endpoint revalidate the session's access token, its
expiry/revocation, active client and active project. A long cookie never overrides
these checks. Apply the recommendation to the deployed `.env` and refresh Laravel's
configuration cache during the normal deployment; existing environment overrides
are respected. No database migration is needed for this milestone.

After an authenticated persona mounts, ProjectBrief registers its push-only worker.
With granted notification permission it obtains/restores a subscription and posts
it to the existing persona API automatically. The backend's ownership check still
rejects another account's endpoint; automatic enrollment never unsubscribes it to
claim ownership. The Push control supports explicit enabling/switching, disabling
and retry. `default` permission gets a non-blocking translated toast with an Enable
action; only that click requests permission. Suggestions are shown once per browser
session, including across reloads. `denied` never triggers permission prompts.
Explicit disabling records only a device preference, preserved until enabling again.
VAPID keys, endpoint validation, multi-device support, business events and
`WebPushTransport` are unchanged; the worker still caches no pages or private data.

Success/error/info/warning toasts are global, translated, closable and timed, with
pause on hover/focus and safe cleanup. Notification settings saves, test Email/Push,
Push controls, project settings and client-management success feedback use them.
Field validation stays beside its field; storage and unsaved-draft states remain
persistent. The initial HTML contains a green branded loader before Vue/route/API
startup, reduced-motion support, a no-JavaScript message and a timed/error retry
fallback. It disappears as soon as initial routing and Vue mounting complete.

Regular, maskable and Apple icons are local assets. Reproduce all PNGs with
`python3 bin/generate-pwa-icons.py` from `public/app-icon.svg` using only Python's
standard library; the maskable mark fits inside its safe circle. The production
build and release checks include these assets. This PWA does not provide offline
project access; browser/OS installation and push availability remain platform-specific.

## Milestone 1: explicit saves and useful notifications

Developer estimate, price and technical notes are local drafts until **Save changes**
(**Сохранить изменения**) or Cmd+S / Ctrl+S in the task editor. A visible dirty
indicator and navigation/logout/reload protection prevent accidental discarding.
Failed saves retain the draft and report errors through global toasts. Status,
comments, approval and ordering remain immediate.

Notification emails now explain each event and the next action, identify the project
and task, and include the relevant public comment or new idea description. Client
system copy uses `project_clients.preferred_locale`; admin copy continues to use
`NOTIFICATION_ADMIN_LOCALE`. Human-written content keeps its original language.
See [Milestone 1 implementation and verification](docs/milestone-1.md) for content
bounds, event context, limitations and tests. No database migration is required.
