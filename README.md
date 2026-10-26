# Планировщик ТЗ / Project Brief

Клиент описывает изменение сайта своими словами, разработчик получает структурированную идею, обсуждение и историю. Vue 3 / Vite / Pinia / Vue Router / vanilla CSS остаются основой интерфейса. Backend — Laravel 13.34, Eloquent и MariaDB, без JWT и публичной регистрации.

Основные возможности: проекты и рабочие пространства с разграничением доступа, персональные клиентские ссылки, разделы и нумерация задач, обсуждения и вложения, согласование ТЗ, оценка часов и стоимости, уведомления, PWA и интерфейс RU/EN/TR. Экспорт в PDF и DOCX позволяет подготовить ТЗ или прайс-лист с выбранными полями и итогами.

## Требования

- Node.js 20.19+ или 22.12+, npm (проверено на Node 22.23.2).
- PHP 8.3+; `pdo_mysql`, `pdo_sqlite` для тестов, `mbstring`, `openssl`, `fileinfo`, `zip`, `gd`, `dom`, `xml`, `xmlwriter`, стандартные Laravel extensions. Расширения `gd` и XML необходимы в том числе для экспорта документов. Зависимости Composer закреплены для PHP 8.3.30; проверено на PHP 8.5.10.
- Composer 2.
- MariaDB (проверено на 11.8). Для необязательного Compose-сценария — Docker/OrbStack.

## Локальный запуск с API

Из корня репозитория:

```bash
npm ci
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

Команда интерактивно спрашивает имя, email и скрытый пароль (от 12 символов); сохраняется Laravel password hash. User, workspace и активное owner membership создаются атомарно. Пароль не передавайте через аргументы командной строки.

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

Добавлены client edit route `/project/:uuid/task/:id/edit` и `DELETE /api/client/tasks/{uuid}`. Существующие `addTask`, `updateTask`, `changeStatus`, `approveTask`, `addComment`, `updateDeveloperData` сохранены. В API-режиме компоненты ожидают серверный ответ, комментарии и согласование блокируют повторный submit. Оценка, стоимость и технические заметки остаются локальным черновиком до нажатия «Сохранить изменения» или Cmd+S / Ctrl+S. При ошибке черновик сохраняется; переход, выход и перезагрузка защищены предупреждением о несохранённых изменениях.

## Экспорт ТЗ и прайс-листов

В разделе проекта «Экспорт» владелец рабочего пространства или назначенный администратор может скачать PDF или DOCX на русском, английском или турецком языке. Доступны три варианта задач: «Все задачи», «Только согласованные клиентом» и «Только готовые». Готовность определяется статусом `done`, независимо от клиентского согласования.

Можно задать заголовок документа, выбрать описание, ожидаемый результат, приоритет, статус, часы, стоимость, названия вложений и дату согласования, а также начинать каждый раздел с новой страницы. Для прайс-листа включите стоимость и при необходимости часы. Итоги и количество задач рассчитываются только по выбранным задачам; валюта берётся из настроек проекта. Экспорт использует сохранённые серверные данные, поэтому перед скачиванием сохраните изменения в редакторе задачи.

Рендеринг выполняют Dompdf и PHPWord. Приватные заметки, содержимое вложений, внутренние пути и клиентские access tokens не включаются в документы. Подробности и результаты проверок — в [отчёте Milestone 3.5](docs/milestone-3.5.md).

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

## RU / EN / TR и loading

`vue-i18n` 11, Composition API; dictionaries `src/locales/ru.js` и `en.js`, одинаковые 353 keys. Переводятся UI constants, включая statuses, priorities, ошибки, history summaries и dialogs. Project/section/task content, comments, notes, URLs и filenames не переводятся. `Intl.DateTimeFormat`: ru-RU / en-GB; `Intl.NumberFormat` использует locale и валюту проекта. Counts используют pluralization vue-i18n с русским правилом.

Приоритет: ручной выбор `projectbrief.locale` в localStorage → `preferred_locale` профиля администратора или клиента → первый язык браузера → английский. Поддерживаются Русский (`ru-RU`), English (`en-GB`) и Türkçe (`tr-TR`). Переключатели интерфейса, профиля и документа используют `src/i18n/catalog.js`; серверные правила — `api/config/localization.php`. Язык меняется без reload и сохраняется через существующие `/me/locale` endpoints; язык документа независим от интерфейса. Полнота переводов, подстановки и согласованность каталогов проверяются тестами. Отчёт, скриншоты и инструкция добавления языка: [Milestone 6.0](docs/milestone-6-0/README.md).

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
| Current client       | `PATCH /api/client/me/locale` (`locale: ru/en/tr`); `GET /api/client/me` — имя/email только текущего клиента                                                                     |
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

Password reset/2FA, команды и роли, платежи, result acceptance и realtime пока не реализованы. Backend обслуживает существующий developer workflow; отдельного Laravel frontend/CRM нет. AI-помощник описан ниже; предложения разделения не создают задачи автоматически.

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

## Milestone 2: workspace tenant boundary

Projects now require a workspace. Active owners access every project in their
workspace; active admins access only explicitly assigned projects. `users.role` is
legacy metadata and no longer grants global access. Login and installed PWA bootstrap
resolve active workspace membership; existing client personal links remain separate.

The server chooses the earliest accessible workspace when no selection exists and
rejects stale selections. Minimal authenticated APIs are `GET /api/admin/workspaces`
and `POST /api/admin/workspaces/{uuid}/select`. No workspace switcher or team UI is
introduced. Notification recipients are the project's active owners and assigned
admins; foreign-workspace users/devices are excluded.

The three additive migrations preserve existing data and client links, name the
initial workspace from the oldest legacy admin/application, and retain additional
legacy admins' access through project assignments. Fresh CLI accounts own their own
workspace. See [Milestone 2 verification and upgrade instructions](docs/milestone-2.md)
before applying the release to an existing installation.

## Milestone 3: admin shell and personal activity

Authenticated owners/team users have a shared sidebar, validated workspace switcher
and mobile drawer. Project pages are `/brief`, `/clients`, `/structure`,
`/notifications` and `/settings`; legacy project URLs redirect to settings (the old
client-access hash redirects to clients). Client/PWA/task editing interfaces remain.

Project badges count only client `created`, `comment` and `approved` history after
that user's project high-water marker. Successful brief/task loading marks the
project seen through an explicit POST; settings and management screens do not.
Counts are personal and tenant-scoped, with no polling or delivery/read-state mixing.
See [Milestone 3 report](docs/milestone-3.md) for schema, routes, load/query behavior,
mobile accessibility, tests, screenshots and deployment limitations.

## AI Task Assistant — Groq / ZvenoAI

В форме создания и редактирования задачи кнопка **«Улучшить с AI»** доступна, если
заполнено хотя бы одно из полей: название, описание, ожидаемый результат. Перед
отправкой окно сообщает о передаче текста внешнему провайдеру. Только нажатие
**«Отправить в AI»** запускает запрос. Удалите персональные данные и секреты из
текста формы перед отправкой.

Предложение можно проверить и отредактировать. **«Применить»** переносит название,
описание и ожидаемый результат только в локальную форму; задача сохраняется
существующей кнопкой формы. Уточняющие вопросы и до трёх предложений отдельных
задач показываются отдельно, без создания новых записей. Язык исходного ТЗ
сохраняется независимо от переключателя RU/EN. Генерация не добавляет требования,
сроки или стоимость: недостающие сведения запрашиваются вопросами. Ответ AI
необходимо проверить перед сохранением.

Настройки только в **`api/.env`** (не в frontend `.env` и без префикса `VITE_`):

```dotenv
AI_ENABLED=true
AI_PROVIDER=groq
GROQ_API_KEY=your-server-side-groq-key
GROQ_MODEL=openai/gpt-oss-120b
AI_REQUESTS_PER_MINUTE=5
AI_WORKSPACE_DAILY_LIMIT=50
```

По умолчанию `AI_ENABLED=false`. При выключенной функции или пустом ключе выбранного провайдера сервер
возвращает `503 / ai_disabled`, а окно объясняет причину. Локальный demo-режим
без API показывает сообщение о недоступности и вообще не делает запросов.
Для локального запуска с backend задайте `VITE_DATA_SOURCE=api` в frontend `.env`,
запустите `npm run api:dev` и `npm run dev`. После изменения настроек сбросьте
закэшированный Laravel config: `cd api && php artisan config:clear`.

Backend использует Laravel HTTP Client без новых Composer-зависимостей:
`POST https://api.groq.com/openai/v1/chat/completions` или
`POST https://api.zveno.ai/v1/chat/completions`, JSON Schema с `strict: true`.
Groq остаётся провайдером по умолчанию; без `AI_PROVIDER` прежние `GROQ_API_KEY` и
`GROQ_MODEL` работают как раньше. Groq-модель по умолчанию поддерживает
[Structured Outputs](https://console.groq.com/docs/structured-outputs).
При замене модели выбирайте совместимую с этими параметрами; автоматического
перехода к неструктурированным ответам и повторных платных запросов нет.

Защищённые endpoints:

- `POST /api/admin/projects/{projectUuid}/ai/task-assistant` — текущий workspace,
  owner или администратор, назначенный на проект;
- `POST /api/client/project/{projectUuid}/ai/task-assistant` — действующая клиентская
  сессия и только проект её персональной ссылки.

Оба используют существующие session/CSRF middleware. JSON входа: `title`,
`description`, `expectedResult`, `section`, `location`, `consent: true`.
Неизвестные поля отклоняются. Максимумы: название 160 символов; описание и результат
по 10000; раздел и расположение по 2048. Нужен текст хотя бы в одном из первых трёх
полей. Ответ: `{title, description, expectedResult, questions, suggestedIdeas}`.
Максимум пять вопросов по 1000 символов и три предложения с теми же ограничениями
полей задачи. Ответ проверяется сервером и клиентом; HTML выводится как текст.

В провайдер передаются только пять текстовых полей текущей формы, без project ID,
workspace ID, языка интерфейса, ключей доступа, вложений, комментариев, истории,
других задач, данных клиентов и developer notes. Запросы и ответы не записываются
в БД или логи приложения. В cache хранятся только счётчики и блокировка квоты.
Хранение текста самим внешним провайдером регулируется его политиками.

Ограничения общие для всех пользователей и проектов одного workspace: по умолчанию
5 попыток в минуту и 50 в сутки UTC; дневной лимит обновляется в 00:00 UTC.
Проверка и резервирование квоты защищены атомарной cache lock. Для нескольких
серверов нужен общий постоянный cache (например, существующий database/Redis),
не `array`/`null`; очистка cache сбрасывает квоты. После отправки провайдеру попытка
считается использованной даже при ошибке или закрытии окна. Ошибочные входные данные,
запрещённый доступ и выключенный AI квоту не расходуют.

Тайм-аут соединения 5 секунд, ответа 25 секунд; frontend ожидает до 35 секунд.
Отдельные RU/EN сообщения предусмотрены для тайм-аута, HTTP 429, дневной квоты,
недоступности и некорректного JSON; содержимое ошибок провайдера не раскрывается.
Повторное нажатие не создаёт параллельных запросов. При закрытии окна поздний ответ
игнорируется, а черновик остаётся в форме. Изменение исходных полей, проекта или роли
во время генерации/просмотра блокирует применение устаревшего предложения:
закройте окно и запустите помощника заново. При редактировании предложенного названия
Enter не отправляет родительскую форму.

Проверки без обращения к реальному Groq и без изменения production-базы:

```sh
npm test
npm run build
npm run format:check
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

`AiTaskAssistantTest` использует `Http::fake()` и `Http::preventStrayRequests()`:
структурированные ответы, отказ при неверном JSON/схеме, ошибки провайдера, отсутствие
ключа, общие квоты, сброс суток UTC, доступ клиентов/администраторов и изоляция tenants.
Frontend-тесты проверяют состав запроса, применение только к локальным полям,
редактирование/отмену предложения, повторные нажатия, закрытие/размонтирование,
изменение исходного черновика и независимость языка ответа от RU/EN.

## Milestone 4.1.1 — ZvenoAI и benchmark

Для явного выбора ZvenoAI в `api/.env`:

```dotenv
AI_ENABLED=true
AI_PROVIDER=zveno
ZVENO_API_KEY=your-server-side-zveno-key
AI_MODEL=nvidia/nemotron-3-super-120b-a12b:free
```

Здесь Nemotron — пример кандидата из задания, **не победитель benchmark**.
Настройки `GROQ_API_KEY` и `GROQ_MODEL` сохраняются и используются при
`AI_PROVIDER=groq`; `AI_MODEL` относится только к ZvenoAI. Адреса провайдеров
фиксированы на сервере, произвольные base URL из формы или `.env` не принимаются.
Лимиты workspace общие для обоих провайдеров: переключение не сбрасывает квоту.
Все запросы формы требуют строгую JSON Schema; HTTP 400/401/402/403/429/5xx и timeout
не вызывают смены модели, платного маршрута или формата ответа. Для `:free` дополнительно
задан потолок цены входа/выхода 0 и запрещён provider fallback.

**Фактический результат 19.10.2026:** три точных бесплатных ID присутствуют в каталоге,
но все девять строгих POST завершились HTTP 400. Диагностика Nemotron уточнила:
`no provider supporting response format`. Отдельный текстовый диагностический POST
получил HTTP 429: дневная квота бесплатных моделей платформы исчерпана.
Успешных генераций ТЗ нет, сравнение качества не состоялось; лучшая бесплатная модель
не определена. Прежняя интеграция Groq сохранена. До подтверждения обработки данных бесплатными
провайдерами отправляйте через ZvenoAI только синтетический текст.
[Полный отчёт](docs/milestone-4-1-1.md) содержит измерения и ограничения.

Изолированный Artisan-инструмент использует тот же `AiTaskAssistant`, prompt,
JSON Schema, валидатор и тайм-аут 25 секунд. Он не обращается к проектам/задачам/БД,
использует только три встроенных синтетических сценария. Без `--live` сеть не используется:

```sh
cd api
php artisan ai:benchmark
php artisan ai:benchmark --live --delay=10 --output=/tmp/zveno-benchmark.json
```

Максимум девять генераций за запуск (три модели × три сценария), последовательно,
без автоматических повторов. Каталог проверяется до генераций: отсутствующий ID или
ненулевые цены токенов приводят к пропуску, без замены модели. HTTP 400 пропускает
оставшиеся сценарии этой модели; HTTP 401/402/403/429 останавливает весь запуск.
`--model=<exact-free-id>` и `--scenario=simple|vague|multiple` сужают выборку.
`--delay` от 10 до 60 секунд. Существующий output-файл не перезаписывается.

Для отдельной диагностики доступны `--format=json_object` и `--format=text`.
Это явный выбор **только CLI**: production-форма продолжает отправлять JSON Schema
независимо от запусков команды. Диагностический prompt дополнительно просит один JSON
объект без code fences, а ответ проходит тот же серверный валидатор. Такие результаты
не доказывают поддержку Structured Outputs и не выбирают production-модель автоматически.

Отчёт JSON содержит модель, сценарий, HTTP status, latency, отдельные результаты
проверки JSON/схемы, finish reason, usage, код ошибки и валидное предложение, если оно
получено. При отсутствии ответа модели JSON/схема не измерены (`null`); это не оценка
качества текста. Для синтетических ошибок CLI сохраняет ограниченное диагностическое
сообщение с редактированием ключей. Серверные API-ошибки его не раскрывают.
Русский язык, полнота результата, вопросы и выдуманные требования оцениваются вручную;
команда не выдаёт автоматические баллы за объективную оценку качества.

Проверка выполнена с локального Mac, **не с Jino**. Каталог HTTP 200 на Jino,
проверенный ранее пользователем, не доказывает успешность авторизованной генерации.
После восстановления квоты и появления совместимого маршрута повторите одиночный
строгий POST-командой на Jino и проверьте фактический PHP/web timeout. Текущий request
budget 25 секунд, connect timeout 5, frontend timeout 35; время реальной генерации
этих моделей пока неизвестно. Дополнительных очередей и серверов нет.

На этом Mac у PHP был указан отсутствующий CA bundle. Для локального benchmark
использован существующий файл с проверкой TLS (это локальный путь, не настройка Jino):

```sh
AI_ENABLED=true php -d openssl.cafile=/usr/local/etc/ca-certificates/cert.pem \
  -d curl.cainfo=/usr/local/etc/ca-certificates/cert.pem artisan ai:benchmark \
  --live --model=nvidia/nemotron-3-super-120b-a12b:free --scenario=simple \
  --output=/tmp/zveno-single.json
```

Ключ вводится только в `api/.env`, не передаётся в аргументах команды, frontend или Git.

## Milestone 4.1.2 — JSON через prompt для ZvenoAI

Для ZvenoAI добавлен **явно выбираемый** режим без `response_format`:

```dotenv
AI_PROVIDER=zveno
ZVENO_OUTPUT_MODE=prompt_json
AI_MODEL=nvidia/nemotron-3-super-120b-a12b:free
```

Ключ остаётся в `api/.env` (`ZVENO_API_KEY`), включение — через `AI_ENABLED=true`.
По умолчанию `ZVENO_OUTPUT_MODE=json_schema`, прежний режим не меняется.
Настройка относится только к ZvenoAI; Groq всегда отправляет прежнюю строгую
`response_format: json_schema`, `reasoning_effort=low` и лимит 6000 completion-токенов.
Неизвестное значение режима отклоняется до provider-запроса и расходования квоты.
После изменения закэшированного config выполните `cd api && php artisan config:clear`.

В `prompt_json` JSON Schema Milestone 4.1 целиком добавляется в системный prompt как
контракт. Модель должна вернуть ровно один JSON object, без Markdown, code fences,
рассуждений и пояснений; пустые списки — `[]`. Запрос содержит только `model`,
два `messages`, `max_tokens=6000` и `provider` с запретом fallback и потолком цены 0
для `:free`. `response_format`, `reasoning`, `reasoning_effort`, `stream` и
`provider.require_parameters` не передаются.

Ответ не извлекается из произвольного текста и не исправляется автоматически:
`json_decode` → проверка объекта/массивов → существующий Laravel validator с whitelist
полей и прежними ограничениями длины. Невалидный JSON, лишние/пропущенные поля,
неправильные типы, слишком длинные поля/списки, Markdown-обёртки и обрыв по токенам
возвращают `ai_invalid_response`, без повторного запроса и без изменения формы.
Доступ по workspace/проектам, общие квоты, приватность, уведомление о внешнем AI и
ручное применение предложения сохраняются.

**Одиночная синтетическая live-проверка** (выполнена 20.10.2026; автоматических повторов нет):

```sh
cd api
php artisan ai:benchmark --live --format=prompt_json \
  --model=nvidia/nemotron-3-super-120b-a12b:free --scenario=simple \
  --output=/tmp/zveno-prompt-nemotron.json
```

Для локального PHP с отсутствующим CA bundle используйте описанные выше `php -d`
параметры; проверка TLS остаётся включённой. Параметр CLI не меняет production config.
Сначала проверьте HTTP, валидность JSON и русский текст Nemotron. Только после
успешного ответа последовательно проверяйте аналогичный сценарий у
`google/gemma-4-26b-a4b-it:free` и `dots-studio/dots-3-note-preview:free`, делая паузу
не менее 10 секунд. На ошибке квоты/доступа остановитесь; не повторяйте запросы,
не ослабляйте режим автоматически и не меняйте бесплатные ID на платные.

После указанного провайдером сброса квоты (20.10.2026, 03:00 Europe/Istanbul)
выполнено **два** одиночных синтетических запроса в `prompt_json`, без повторов:

| Модель   | HTTP             | Время POST  | JSON / Laravel validator | Результат                                              |
| -------- | ---------------- | ----------- | ------------------------ | ------------------------------------------------------ |
| Nemotron | 200              | 18 847,7 мс | valid / valid            | Получено русское ТЗ; `finish_reason=stop`              |
| Gemma 4  | 429              | 592,7 мс    | не проверялись           | `rate limit exceeded`; проверка остановлена            |
| Dots3    | не запрашивалась | —           | —                        | Остановка после HTTP 429, без смены модели или повтора |

Nemotron корректно описал увеличение фотографий и перелистывание на телефоне,
вернул ожидаемый результат и пустые массивы вопросов/разделения. При ручном
редактировании нужно убрать или согласовать добавленные моделью **планшеты** и
уточнить платформу («сайт/приложение»); уточняющий вопрос об этом был бы полезен.
Стек, сроки, бюджет и дополнительные самостоятельные функции не выдуманы.
Успешная генерация бесплатной моделью подтверждена, но одна выборка не доказывает
стабильность; 18,85 с близки к Laravel timeout 25 с.

Фактические ответы, UTC-время, usage, каталог и ручная оценка сохранены без
секретов в [docs/milestone-4-1-2-results.json](docs/milestone-4-1-2-results.json).
Клиентские данные не отправлялись, результат не применялся и в БД не записывался.
Groq, production, `.env` и опубликованный релиз не менялись; новых повторов не
запланировано. Текущие целевые fake-тесты benchmark / prompt mode: **88/88**,
624 assertions. Они дополняют реальный результат, а не заменяют его.

Проверки Milestone 4.1.2: Laravel **391/391** (4170 assertions), frontend
**129/129**, production build, Prettier и Pint — успешно. Новый режим проходит
полный общий набор проверок доступа/приватности/квот и дополнительные тесты отказа
от Markdown, пояснений, нескольких JSON-объектов и скрытого переключения формата.

## Milestone 4.1.3 — Groq через Deno Bridge

Для существующего моста настроены отдельные backend-параметры:

```dotenv
AI_ENABLED=true
AI_PROVIDER=groq_bridge
AI_BRIDGE_URL=https://projectbrief-ai-bridge.web86.deno.net/ai
AI_BRIDGE_SECRET=your-server-side-bridge-secret
GROQ_MODEL=openai/gpt-oss-120b
```

Секрет должен совпадать с `AI_BRIDGE_SECRET` на Deno и иметь не менее 32 символов;
используется Bearer-авторизация. `GROQ_API_KEY` для этого провайдера хранится на Deno,
Laravel его не передаёт. Разрешён только указанный HTTPS URL `/ai`; произвольные
адреса из конфигурации отклоняются. После изменения cached config выполните в `api`
`php artisan config:clear`. Настройки прямого Groq и ZvenoAI продолжают работать.

Payload полностью совпадает с прямым Groq, включая system prompt и строгую JSON
Schema. Доступ, квоты и серверный валидатор сохранены; AI-предложение применяется и
сохраняется пользователем прежними действиями формы. Интерфейс не менялся.
Нет автоматического fallback, повторов или смены региона. Bridge/upstream 403
останавливает одиночную диагностическую проверку; никаких обходов доступа нет.

**Live 19.10.2026:** один синтетический запрос через Deno → Groq завершился HTTP 200
за **1614.9 мс**, JSON и Laravel-контракт пройдены, модель `openai/gpt-oss-120b`.
Использовано 786 токенов. Проверка выполнена локально, не с Jino.
[Отчёт, пример ответа и рекомендации](docs/milestone-4-1-3.md),
[JSON измерений](docs/milestone-4-1-3-results.json).

Тайм-ауты оставлены: Deno 20 секунд, Laravel 25, frontend 35; один быстрый запрос
не является нагрузочным измерением. На Jino отдельно проверьте PHP/web request
budget, желательно не менее 30 секунд. Размер payload моста ограничен 100000 байт.

`cd api && php artisan ai:bridge-check` показывает план без сети и БД.
`php artisan ai:bridge-check --live --output=/tmp/groq-bridge-check.json` выполняет
ровно один синтетический POST через тот же сервис и валидатор. Без секрета запрос
не отправляется; существующий файл не перезаписывается. Ключи в CLI не передавайте.

## Milestone 4.8 — приглашения разработчиков и личный аккаунт

Владелец управляет командой и назначениями проектов на странице «Команда». Приглашения действуют 7 суток, принимаются однократно после входа либо создания нового аккаунта. Один User может участвовать в нескольких workspace. Глобальный «Мой аккаунт» содержит профиль, аватары, безопасность и прежние личные уведомления. Новый Email требует подтверждения, смена/восстановление пароля отзывает остальные сеансы. Навигация desktop/mobile использует общий AppIcon и общую конфигурацию.

[Архитектура и результаты](docs/milestone-4-8/REPORT.md), [обновление production и откат](docs/milestone-4-8/PRODUCTION.md), [release notes](docs/milestone-4-8/RELEASE.md). Требуются Argon2id и GD WebP; новая additive миграция сохраняет существующие таблицы и memberships. Проверку реальной доставки SMTP Jino пользователь выполняет самостоятельно. Push, deployment и GitHub Release не выполнялись.

## Milestone 5.0 — Integrations API & Webhooks

Владелец workspace управляет API-ключами и исходящими webhook на странице
`/admin/settings/integrations`. Stateless `/api/v1` использует workspace-scoped
Bearer tokens, scopes, пагинацию и Idempotency-Key; клиентские ссылки и браузерная
сессия не участвуют. Подписанные события отправляет cron-команда
`php artisan integrations:webhooks --limit=20 --seconds=45` из DB outbox.

[Архитектура, API, curl и n8n](docs/milestone-5-0/README.md),
[OpenAPI](docs/milestone-5-0/openapi.json),
[проверки и screenshots](docs/milestone-5-0/VERIFICATION.md),
[обновление и откат](docs/milestone-5-0/PRODUCTION.md).
Нужны additive миграция и cron; реальный Jino/CRM E2E выполняется при внедрении.

## Milestone 5.0.1 — фильтры Webhooks и комментарии клиентов

Владелец workspace выбирает все проекты (включая новые) или явный список проектов
для каждого webhook. Изменение фильтра отменяет ожидающие доставки, а cron и ручной
retry перепроверяют актуальную выборку. Новые события комментариев клиента к задаче
и в общем обсуждении включаются явно; в payload только UUID, без текста и вложений.
Существующие endpoints мигрируют в `all`, подписки и секреты сохраняются.

[Архитектура и примеры](docs/milestone-5-0-1/README.md),
[проверки и screenshots](docs/milestone-5-0-1/VERIFICATION.md),
[миграция production и откат](docs/milestone-5-0-1/PRODUCTION.md).
