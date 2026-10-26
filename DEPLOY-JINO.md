# ProjectBrief на Jino

Домен: **https://brief.web86.site**. Публичная папка: **`~/domains/brief.web86.site`**.
База уже создана: **`specchina_breaf_tz`**. На хостинге нужны PHP 8.4, его расширения и MySQL/MariaDB.
**Composer, Node.js, npm и Git на сервере не нужны:** vendor и готовые Vue assets входят в архив.

## Первая установка

1. На Mac из чистого, закоммиченного проекта соберите архив:

   ```bash
   ./bin/build-release
   ```

   Нужны Node/npm, PHP 8.3+ и Composer 2. Локальные PHP также требуют `pdo_sqlite` и `gd` для тестов, `zip` для упаковки. Script использует обычный `composer` или локальный `.tools/composer.phar`. Другой исполняемый файл можно задать через `PHP_BIN` и `COMPOSER_BIN` (путь к binary или `.phar`, без аргументов). Сборка выполняет `npm ci`, тесты, Vue build, Laravel tests, отдельную установку production vendor, проверку пакета и запуск его копии с временной SQLite. Текущие `.env`, MariaDB, uploads и development vendor сохраняются. Архив появляется в `release/project-brief-v0.6.3-<commit>.zip`, распакованная копия — `release/project-brief/`.

   В ZIP входят только `public/`, `private/project-brief-app/`, необходимый Telegram bridge, `DEPLOY-JINO.md`, `PRODUCTION.md`, `release.json` и `checksums.txt`. Шрифты и лицензии сохранены. Тестовые документы, скриншоты, результаты проверок и исторические отчёты остаются в Git вне дистрибутива. Перед публикацией сборка распаковывает готовый ZIP в новую временную папку, сверяет все SHA-256 и проверяет установку, запуск и экспорт на отдельной SQLite.

2. Распакуйте архив локально и загрузите **содержимое**, включая скрытые файлы:

   ```text
   private/project-brief-app/ → ~/project-brief-app/           (PRIVATE)
   public/                   → ~/domains/brief.web86.site/    (PUBLIC)
   ```

   В публичную папку нельзя загружать весь архив или `private/`. `.env`, vendor, storage, database и app остаются за пределами `domains`. Создавайте указанные папки при необходимости. Фактическая домашняя папка аккаунта не зашита в исходники: public `index.php` поднимается на два уровня от `domains/brief.web86.site` и подключает соседнюю `project-brief-app`. Если Jino использует другой физический document root или symlink, сначала определите настоящий путь; эту схему ещё нужно проверить с console access.

3. В **приватной** папке создайте `.env` из примера:

   ```bash
   cd ~/project-brief-app
   cp .env.production.example .env
   chmod 600 .env
   ```

4. Заполните в `.env` значения из панели базы Jino:

   ```dotenv
   DB_HOST=
   DB_DATABASE=specchina_breaf_tz
   DB_USERNAME=
   DB_PASSWORD=
   ```

   Не угадывайте hostname или пользователя. Пароли с пробелами/`#` заключайте в кавычки по правилам dotenv. `APP_KEY` пока оставьте пустым: установщик создаст его. Сохраните `APP_ENV=production`, `APP_DEBUG=false`, одинаковые `APP_URL` и `FRONTEND_URL=https://brief.web86.site`, `SESSION_DRIVER=database`, `SESSION_LIFETIME=525600`, `SESSION_EXPIRE_ON_CLOSE=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, HttpOnly и шифрование сессий. Таблица sessions входит в migrations. HTTPS и PHP 8.4 должны быть включены для домена.

5. Проверьте PHP **в консоли**, даже если в панели уже выбран 8.4:

   ```bash
   php -v
   ```

   CLI PHP может отличаться от PHP домена. Если он ниже 8.3 или не содержит нужных расширений, найдите доступный PHP 8.4 CLI binary через панель/поддержку Jino. **Путь не угадываем:** после предоставления console access его нужно определить фактически. Во всех следующих командах вместо `php` используйте найденный binary.

6. Выполните в `~/project-brief-app`:

   ```bash
   php bin/check-server
   php bin/first-install
   ```

   `check-server` выводит PASS/FAIL для PHP, расширений, runtime files, writable directories и соединения с базой; без `.env` DB-проверка отмечается SKIP. SQL/пароли не выводятся. `first-install` требует заполненный `.env`, убирает старый config cache, сохраняет существующий APP_KEY (создаёт только отсутствующий), проверяет DB, выполняет `migrate --force` и `optimize`. Затем предлагает `Create first administrator? [Y/n]` и вызывает существующий `app:create-admin`: имя, email, скрытый пароль от 12 символов. Если активное workspace membership уже есть, создание пропускается. `app:create-admin` атомарно создаёт User + Workspace + owner membership. Повторный запуск безопасен; база не удаляется. На пустом stdin создание admin пропускается. При необходимости позже:

   ```bash
   php artisan app:create-admin
   ```

7. Откройте **https://brief.web86.site/admin/login** и войдите своим администратором. Проверьте **https://brief.web86.site/api/health** — ответ только `{"ok":true}`. Это liveness (загрузка приложения), а соединение с DB проверяет приватная CLI-команда. Затем проверьте создание проекта, вход по клиентской ссылке и скачивание вложения.

## Что находится в архиве

```text
private/project-brief-app/
  app/ bootstrap/ config/ database/migrations/ routes/
  resources/views/ storage/ vendor/ bin/
  artisan composer.json composer.lock .env.production.example
public/
  index.php .htaccess index.html assets/ favicon...
DEPLOY-JINO.md
release.json       # исходный commit и время сборки
checksums.txt      # SHA-256 каждого файла, кроме самого списка
```

Vue HTML/JS/CSS собираются Vite локально; source maps выключены. Production API base пустой — запросы идут на тот же origin. В архиве нет frontend source и инструментов сборки. Composer `--no-dev --prefer-dist --optimize-autoloader` выполняется **в staging на Mac**, поэтому development vendor не превращается в production vendor. Из vendor удаляются upstream tests/docs/examples; лицензии и runtime helpers сохраняются. Runtime не нуждается в seeders/factories, их папки пустые; schema устанавливается всеми migrations без SQL dump и demo-данных.

Apache должен поддерживать `mod_rewrite` и разрешать `.htaccess` (`AllowOverride` для rewrite/options/auth). `DirectoryIndex index.php`: существующие assets отдаются напрямую; остальные URL попадают в Laravel. Laravel возвращает Vue shell для `/`, `/admin/...`, `/project/...` и deep reload. `/api`, `/access`, `/sanctum` зарезервированы: неизвестный серверный URL получает 404, а не Vue. Sanctum сейчас не используется, сессии и CSRF — стандартные Laravel web middleware. `/api/csrf`, admin login, client access и protected downloads работают на одном HTTPS origin без CORS. Laravel `usePublicPath` указывает на реальный domain directory, стандартный request lifecycle сохранён. `index.html` тоже проходит через Laravel при прямом запросе, поэтому maintenance mode действует на страницы приложения.

## Расширения PHP и права

Требуются `ctype`, `curl`, `dom`, `gd`, `xml`, `xmlwriter`, `libxml`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, **`zip`** (проверка ZIP/DOCX). `pdo_sqlite` нужен только локальным тестам. `gd` и `xmlwriter` также нужны production-зависимости PHPWord для DOCX; PDF использует включённые в vendor шрифты DejaVu, без системных binaries или сети. Composer platform requirements также проверяются локально при сборке.

Начальные права пакета: **755 directories / 644 files**. `.env` — 600. PHP process должен иметь запись в `storage/` (включая private uploads, framework/cache/data, sessions, views, logs) и `bootstrap/cache/`. На shared hosting обычно достаточно прав владельца; если процесс имеет другого владельца, настройте группу/права с поддержкой Jino. **Не используйте 777.**

В панели PHP домена настройте `upload_max_filesize=20M`, `post_max_size=210M`, `max_file_uploads=10`, `display_errors=Off`, `log_errors=On`. Это соответствует существующим ограничениям приложения. Размер HTTP request может дополнительно ограничивать hosting; проверьте фактические лимиты Jino.

Uploads находятся в **`~/project-brief-app/storage/app/private`** и доступны только через защищённые Laravel endpoints. **`storage:link` не нужен**, приватные attachments не копируются в public. Настройки доступа/маскирования HTTP access logs для `/access/*` следует проверить на Jino: URL содержит временную секретную ссылку; приложение не пишет token в свои logs.

## Проверки перед упаковкой

Сборка прекращается без финального архива при ошибке тестов или проверки. Проверяется наличие Vue assets, vendor/autoload.php, bootstrap, artisan, production entry point, `.htaccess`, CLI tools и безопасного env example. Запрещены `.env`, `.env.*` кроме production example, `.git`, `.tools`, node_modules, tests, local DB, `local-admin.json`, `*.key`, source maps, файлы credentials/secrets и содержимое private keys. Из локальных `.env`/local-admin.json берутся значения секретов только для сравнения с содержимым пакета — они не выводятся. Development Composer packages исключены. Данные и caches текущего приложения не копируются. Symlinks запрещены. Проверяются SHA-256 файлов внутри ZIP. `checksums.txt` можно проверить локально на Mac командой `shasum -a 256 -c checksums.txt` из распакованного пакета.

Проверки локального пакета и PHP 8.4/Apache/MariaDB: [docs/release-verification.md](docs/release-verification.md) в исходном Git-репозитории (development docs не включаются в ZIP).

## Обновление

Сначала соберите и проверьте новый архив на Mac. Перед обновлением сохраните резервную копию production DB, приватной `.env` и `storage/`. Затем в приватной папке сервера:

```bash
php artisan down
```

Загрузите новые application files/vendor и public assets из архива. **Сохраните текущие `.env` и всё `storage/`, включая uploads и maintenance marker.** Не перезаписывайте их пустыми папками нового пакета. Для `bootstrap/cache` удалите старые `config.php`, `routes-*.php`, `events.php`, `packages.php`, `services.php` перед заменой; папку и права сохраните. Заменяйте vendor целиком, чтобы не оставлять старые dependencies. Не удаляйте private files через web-интерфейс приложения.

При обновлении используйте прежние пути: `private/project-brief-app/` из архива
заменяет код в `~/project-brief-app/`, а `public/` — файлы в
`~/domains/brief.web86.site/`. Сохраните резервную копию предыдущего кода/public
вне публичной папки. Не копируйте `.env.production.example` поверх `.env` и не
запускайте `bin/first-install` как шаг обновления: его поведение проверено на
временной базе (существующий APP_KEY сохраняется), но для работающего приложения
достаточно явных команд ниже. Сохраняйте `APP_KEY`, VAPID, session driver/domain и
содержимое таблицы `sessions`; миграции не требуют сброса сессий или данных.

```bash
php artisan migrate --force
php artisan optimize
php artisan up
```

Если migration/optimize завершились ошибкой, оставьте maintenance mode и исправьте её перед `up`. Не выполняйте `migrate:fresh`, `db:wipe`, seed или смену APP_KEY. Проверьте health, login, клиентскую ссылку, reload Vue route и вложения. Здесь нет автоматического SSH/rsync, server-side Composer/Node/Git, web installer или default production password. Загрузка на Jino и проверка его реального document root/CLI PHP — отдельный следующий шаг.

Проверьте создание/редактирование задачи, комментарии, уведомления, экспорт
PDF/DOCX и отсутствие доступа к чужому workspace. Для отката сохраните maintenance
mode и восстановите согласованный backup кода и БД; не запускайте старый код
поверх несовместимой новой схемы.

## AI Task Assistant (Milestone 4.1)

Архив содержит `groq`, `zveno` и `groq_bridge`, общий серверный валидатор и
workspace-лимиты. AI выключен в `.env.production.example`; сборка и установщик
не добавляют ключи и не включают AI. Существующая production `.env` сохраняется.
Для включения через уже развёрнутый Deno Bridge вручную добавьте в приватную
`~/project-brief-app/.env` во время согласованного обновления:

```dotenv
AI_ENABLED=true
AI_PROVIDER=groq_bridge
AI_BRIDGE_URL=https://projectbrief-ai-bridge.web86.deno.net/ai
AI_BRIDGE_SECRET=
GROQ_MODEL=openai/gpt-oss-120b
AI_REQUESTS_PER_MINUTE=5
AI_WORKSPACE_DAILY_LIMIT=50
```

Пустой `AI_BRIDGE_SECRET` необходимо заполнить секретом моста вручную (минимум
32 символа), не через Git, чат или публичную папку. Groq API key хранится в Deno,
Laravel для `groq_bridge` использует только bridge secret. Для прямого `groq`
нужен `GROQ_API_KEY`; для `zveno` — `ZVENO_API_KEY`, `AI_MODEL` и явно выбранный
`ZVENO_OUTPUT_MODE` (`json_schema` по умолчанию либо `prompt_json`). Автоматических
повторов, переключения провайдера или платных моделей нет.

После редактирования `.env` выполните `php artisan optimize` перед `up`. Проверьте
в интерфейсе, что предложение редактируется и применяется только к локальному
черновику; сохранение задачи остаётся отдельным действием. Не запускайте CLI
live-проверки без необходимости: успешный синтетический Groq Bridge тест уже
зафиксирован в `docs/milestone-4-1-3.md` исходного репозитория.

Таймауты: Deno → Groq — 20 секунд, Laravel — 25 секунд (соединение — 5), браузер —
35 секунд. На Jino проверьте PHP `max_execution_time` и лимит web/proxy: бюджет
запроса рекомендуется от 30 секунд. Не отключайте проверку TLS; при проблемах с
CA исправьте доверенный CA bundle средствами хостинга. Фактические настройки Jino
и доступность Groq из Deno проверяются отдельно; при HTTP 403 live-проверки
останавливают без обхода ограничений. Ключи, промпты и ответы не пишутся в логи.

## Email и Web Push уведомления

Доставка работает после фиксации бизнес-транзакции и HTTP-ответа, без Redis,
Supervisor или постоянного queue worker. Ошибка провайдера не отменяет идею,
комментарий или статус. Не настроенный канал пропускается. Отдельная запись
`project_notification_settings` хранит Email/Push для семи событий и необязательный
email разработчика; пустой email означает адрес первого администратора с ролью
`admin`. Клиенты получают уведомления только при `active=true`, активном проекте
и хотя бы одной действующей персональной ссылке. Отзыв всех ссылок прекращает
доставку; подписки сохраняются для возможного восстановления доступа.

### SMTP

В приватном backend `.env` заполнить данные своего SMTP-провайдера:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=ProjectBrief
NOTIFICATION_ADMIN_LOCALE=ru
```

Для SMTP через SSL (обычно порт 465) поставить `MAIL_ENCRYPTION=ssl`.
`tls` использует SMTP с автоматическим STARTTLS при поддержке сервера;
`MAIL_SCHEME=smtps` при необходимости явно выбирает SSL. Raw PHP `mail()` не
используется. Не помещать пароль в Git или публичную директорию. `MAIL_MAILER=log`
не считается работающей доставкой: тест покажет ошибку настройки SMTP.

Email клиенту формируется на его `preferred_locale` (`ru`/`en`, при отсутствии —
`en`), администратору — по `NOTIFICATION_ADMIN_LOCALE` (`ru` по умолчанию).
Шаблоны имеют HTML и plain text. Ссылки открывают существующую страницу идеи,
без magic token: если сессия истекла, пользователь должен открыть персональную
ссылку. Оценки, стоимость и технические заметки в сообщения не включаются.

### VAPID / Web Push

Используется `minishlink/web-push` 11, стандартный Web Push/VAPID (PHP 8.2+,
проверяемый целевой runtime PHP 8.4). Библиотека изолирована в `WebPushTransport`;
подписки хранятся отдельно для администратора или клиента и поддерживают
несколько устройств. `curl`, `mbstring` и `openssl` обязательны; `bcmath`/`gmp`
необязательны, но улучшают скорость. Все зависимости уже включены в release vendor.

На локальном компьютере из `api/` либо на сервере из приватного приложения:

```bash
php artisan push:vapid
```

Команда выводит пару для ручного копирования в приватный backend `.env`:

```dotenv
VAPID_SUBJECT=mailto:YOUR_CONTACT_EMAIL
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

`VAPID_SUBJECT` — действительный контакт `mailto:` либо HTTPS URL. Хранить одну
и ту же пару после следующих релизов: замена пары требует переподписки устройств.
Private key никогда не передаётся во frontend; authenticated `/api/.../push/status`
возвращает только public key. Не использовать Vite env для ключей. Подписки
(endpoint и ключи устройства) шифруются backend `APP_KEY`; не менять `APP_KEY`
без плана миграции. Уникальность endpoint проверяется SHA-256, только владелец
с текущей сессией может проверить/отключить свою подписку.

После изменения SMTP/VAPID выполнить:

```bash
php artisan config:clear
php artisan optimize
```

Изменения БД устанавливаются обычным `php artisan migrate --force`: новая
миграция additive и сохраняет старые данные; автоматический destructive rollback
запрещён. SMTP/VAPID можно настроить позже — основное приложение продолжит работать.

HTTPS обязателен (localhost разрешён для разработки). После входа service worker
регистрируется автоматически. При уже выданном разрешении подписка синхронизируется
без клика; `default` показывает toast с действием «Включить» один раз за браузерную
сессию. Системный запрос разрешения появляется только после этого действия или
явного включения в header «Push-уведомления». На некоторых мобильных Safari требуется «На экран Домой».
При `denied` изменить разрешение в настройках браузера. Отключение удаляет
подписку только этого устройства. Автоматическая синхронизация не присваивает чужой
endpoint: backend возвращает конфликт владельца. Явное отключение сохраняет только
предпочтение устройства до повторного включения. При смене пользователя явное включение
пересоздаёт browser subscription для новой persona; старый endpoint станет
недействителен и будет удалён после ответа 404/410.

`/sw.js` обрабатывает только push и notificationclick, не кеширует страницы
или API. Щелчок фокусирует подходящую вкладку и открывает реальный URL идеи.
Локальные PNG и manifest входят в публичную часть ZIP. Endpoint принимается
только у стандартных сервисов Chrome/Firefox/Apple/Windows, без редиректов
на произвольные адреса; сторонний browser push provider потребует добавить
проверенный домен в `PushEndpoint`.

### Проверка и диагностика

В настройках проекта раскрыть «Уведомления». «Тест Email» отправляет письмо
только на email разработчика этого проекта (или основной admin email),
«Тест Push» — только на текущую подписку вошедшего администратора.
Никакие тесты не рассылаются всем клиентам. Отображаемый успех означает,
что SMTP или Push-провайдер принял сообщение; фактическую доставку проверить
в почтовом ящике/системном уведомлении.

`notification_deliveries` содержит event, channel, reference получателя,
`pending`/`sent`/`failed`/`skipped` и безопасный `error_code`, без адресов,
endpoint, ключей или текста сообщения. Уникальный dedup key объединяет событие
истории, канал и получателя/устройство, защищая от повторного dispatch.
SMTP/Push timeout ограничен 10 секундами на попытку. 404/410 удаляет только
истёкшую подписку; временная ошибка оставляет её для следующих событий.

Доставка после ответа зависит от завершения PHP-запроса; для строгих повторных
попыток при аварийной остановке процесса в будущем потребуется durable outbox
и планировщик. Сейчас `failed`/`pending` не повторяются автоматически,
чтобы исключить дубли. Framework events/listener и отдельный service позволяют
добавить очередь позднее без изменения бизнес-контроллеров.

## Установленное приложение и сессии (UX/PWA)

Manifest запускает `/app`, `display=standalone`, `scope=/`. `/api/session` с
`Cache-Control: no-store` определяет persona по серверной сессии: admin → `/admin`,
client → `/project/{uuid}`, guest → нейтральный экран RU/EN с просьбой открыть
персональную ссылку и вторичным входом администратора. При сетевой ошибке доступна
повторная проверка. `/api/health` остаётся без session/DB зависимости.

Первый запуск клиента: открыть личную `/access/{token}` ссылку → проект → установить
через браузер. Следующие запуски открывают проект автоматически. Если cookie потерян
в установленном профиле браузера, нужно снова открыть персональную ссылку в этом
профиле. Пароли клиента/magic links не добавлены; секрет не попадает в manifest или
localStorage. Ссылки без срока действия остаются действующими до отзыва. На каждом
запросе проверяются отзыв/срок token, активность клиента и проекта.

Рекомендация `.env`: `SESSION_LIFETIME=525600`, `SESSION_EXPIRE_ON_CLOSE=false`,
`SESSION_DRIVER=database` — конечные 365 дней простоя с продлением при активности.
Secure/HttpOnly/SameSite=Lax, шифрование и CSRF сохранить. На существующем сервере
обновить env override в рамках обычного deployment и выполнить `php artisan config:cache`.
Явный logout администратора завершает сессию. Новых migrations в этом этапе нет;
`APP_KEY` и VAPID не менять.

PNG 192/512 any и maskable, Apple touch icon и исходный SVG включены в build/release;
локальная воспроизводимая генерация: `python3 bin/generate-pwa-icons.py`. Один
theme-color и зелёный HTML loader продолжают системный splash до готовности Vue и
первого решения маршрута. Есть reduced-motion, fallback без JS и retry при сбое.
Глобальные toast заменяют общую обратную связь уведомлений; полевые ошибки и
важные состояния сохранения остаются рядом с формой/постоянно видимыми.
Offline-доступ к проектам не добавлен; worker по-прежнему не кеширует private API.
Реальная установка и доставка Push зависят от браузера/ОС.

## Обновление до workspace tenant isolation (Milestone 2)

Перед обновлением существующей установки выполните отдельный проверенный backup БД,
private файлов и текущего release/environment. Включите maintenance mode; старый код
не должен записывать проекты во время staged миграции. Примените три additive
миграции `2026_10_08_122755_create_workspace_foundation`,
`2026_10_08_122756_backfill_legacy_workspaces`,
`2026_10_08_122757_enforce_project_workspace` через `php artisan migrate --force`
затем
`php artisan optimize`. APP_KEY, VAPID keys и персональные ссылки клиентов сохраняются.

Старейший legacy admin становится owner первоначального workspace, остальные legacy
admins получают project assignments для всех прежних проектов. Runtime больше не
считает `users.role=admin` глобальным разрешением. На пустой базе workspace появляется
только при создании первого owner. Исходные пароли и client links продолжают работать.

Автоматический rollback этих трёх миграций запрещён. Не запускайте старый globally
privileged код поверх новой tenant schema. Для rollback требуется согласованное
восстановление проверенного application/database backup в maintenance mode с отдельным
учётом последующих записей. Полные детали, ограничения recovery и результаты тестов:
`docs/milestone-2.md` в исходном репозитории (verification report не входит в upload package).
Ничего не было развёрнуто в production этим изменением.

## Client Workspace и Telegram (Milestone 4.5)

После замены файлов выполните обычный шаг обновления `php artisan migrate --force`
до `optimize` / `up`. Новая миграция добавляет персональные предпочтения, клиентские
маркеры прочтения и Telegram-привязки. Старые настройки Email/Push сохраняются;
Telegram выключен по умолчанию для всех событий. Сохраните прежний `APP_KEY`:
им шифруются chat ID и существующие сессии.

Клиент получает правое меню с обсуждениями, личными уведомлениями и экспортом.
Клиентский экспорт исключает часы, стоимость и технические заметки на сервере.

Telegram настраивается только в приватной `.env`:

```dotenv
TELEGRAM_TRANSPORT=direct
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_WEBHOOK_SECRET=
TELEGRAM_BRIDGE_URL=
TELEGRAM_BRIDGE_SECRET=
```

Для direct заполните токен бота, username без `@` и отдельный случайный webhook
secret (минимум 32 символа, латинские буквы/цифры/`_`/`-`). После `config:clear`
проверьте `php artisan telegram:check`, затем вручную один
`php artisan telegram:check --live` с Jino. Последняя команда вызывает `getMe`,
без отправки сообщения и без вывода токена; это ещё не проверка доставки.

Webhook регистрируется в Telegram на HTTPS URL
`https://brief.web86.site/api/telegram/webhook` с `secret_token`, совпадающим с
`TELEGRAM_WEBHOOK_SECRET`, и `allowed_updates: ["message"]`.
Подключение выполняется кнопкой в личном интерфейсе, одноразовая ссылка действует
10 минут. Клиент не вводит chat ID. После ответа бота обновите статус, отправьте
личный тест и включите нужные Telegram-события в административной матрице.

Если direct недоступен, необязательный Deno transport использует отдельные
`TELEGRAM_BRIDGE_URL=https://<service>.deno.dev/telegram/send` и
`TELEGRAM_BRIDGE_SECRET` (минимум 32 символа). На Deno хранятся token бота и этот
же отдельный secret; на Laravel token при `deno` не требуется. Webhook продолжает
принимать Laravel. AI/Groq конфигурация и secrets не используются.
`telegram-bridge.mjs` и подробная `TELEGRAM.md` приложены к GitHub Release отдельно;
Deno-сервис не является частью PHP web root и не развёртывается автоматически.

Реальную E2E доставку direct с Jino и через Deno проверяйте после предоставления
тестовых credentials. В релизных проверках Telegram использует mocks.

## Обновление Milestone 6.2

PDF теперь создаётся только в браузере; PHPWord продолжает выдавать DOCX. Загрузите согласованный комплект public/private: Worker, ленивый pdfmake, четыре hashed TTF и `font-licenses` обязательны. Замените vendor целиком комплектом из ZIP, чтобы удалённые пакеты Dompdf не оставались от старой версии. Полные инструкции сохранения `.env`/storage и отката: `PRODUCTION.md` из архива или [руководство 6.2](docs/milestone-6-2/PRODUCTION.md). Новых миграций данных нет. Deployment автоматически не выполняется.

## Обновление 0.6.3: карточки и корзина проектов

В 0.6.3 добавлена миграция `2026_10_26_200540_add_project_trash_lifecycle.php` и повторная очистка файлов `projects:cleanup-files`. До возобновления запросов примените миграции; настройте hourly cleanup через существующий scheduler или отдельный cron. Восстановление сохраняет исходную активность и связи проекта; окончательное удаление доступно только владельцу workspace.

Перед откатом на 0.6.2 восстановите проекты из корзины во всех workspaces и завершите pending cleanup: прежний код не знает `deleted_at` проектов. Полные команды, smoke и безопасный rollback: `PRODUCTION.md` из ZIP или [руководство 0.6.3](docs/project-trash/PRODUCTION.md). Требования browser PDF из раздела 6.2 выше сохраняются. Production deployment автоматически не выполняется.
