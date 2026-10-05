# ProjectBrief на Jino

Домен: **https://brief.web86.site**. Публичная папка: **`~/domains/brief.web86.site`**.
База уже создана: **`specchina_breaf_tz`**. На хостинге нужны PHP 8.4, его расширения и MySQL/MariaDB.
**Composer, Node.js, npm и Git на сервере не нужны:** vendor и готовые Vue assets входят в архив.

## Первая установка

1. На Mac из чистого, закоммиченного проекта соберите архив:

   ```bash
   ./bin/build-release
   ```

   Нужны Node/npm, PHP 8.3+ и Composer 2. Локальные PHP также требуют `pdo_sqlite` и `gd` для тестов, `zip` для упаковки. Script использует обычный `composer` или локальный `.tools/composer.phar`. Другой исполняемый файл можно задать через `PHP_BIN` и `COMPOSER_BIN` (путь к binary или `.phar`, без аргументов). Сборка выполняет `npm ci`, тесты, Vue build, Laravel tests, отдельную установку production vendor, проверку пакета и запуск его копии с временной SQLite. Текущие `.env`, MariaDB, uploads и development vendor сохраняются. Архив появляется в `release/project-brief-<UTC timestamp>-<commit>.zip`, распакованная копия — `release/project-brief/`.

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

   Не угадывайте hostname или пользователя. Пароли с пробелами/`#` заключайте в кавычки по правилам dotenv. `APP_KEY` пока оставьте пустым: установщик создаст его. Сохраните `APP_ENV=production`, `APP_DEBUG=false`, одинаковые `APP_URL` и `FRONTEND_URL=https://brief.web86.site`, `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, HttpOnly и шифрование сессий. Таблица sessions входит в migrations. HTTPS и PHP 8.4 должны быть включены для домена.

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

   `check-server` выводит PASS/FAIL для PHP, расширений, runtime files, writable directories и соединения с базой; без `.env` DB-проверка отмечается SKIP. SQL/пароли не выводятся. `first-install` требует заполненный `.env`, убирает старый config cache, сохраняет существующий APP_KEY (создаёт только отсутствующий), проверяет DB, выполняет `migrate --force` и `optimize`. Затем предлагает `Create first administrator? [Y/n]` и вызывает существующий `app:create-admin`: имя, email, скрытый пароль от 12 символов. Если admin уже есть, создание пропускается. Повторный запуск безопасен; база не удаляется. На пустом stdin создание admin пропускается. При необходимости позже:

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

Требуются `ctype`, `curl`, `dom`, `xml`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `session`, `tokenizer`, **`zip`** (проверка ZIP/DOCX). `pdo_sqlite` и `gd` нужны только локальным тестам. Composer platform requirements также проверяются локально при сборке.

Начальные права пакета: **755 directories / 644 files**. `.env` — 600. PHP process должен иметь запись в `storage/` (включая private uploads, framework/cache/data, sessions, views, logs) и `bootstrap/cache/`. На shared hosting обычно достаточно прав владельца; если процесс имеет другого владельца, настройте группу/права с поддержкой Jino. **Не используйте 777.**

В панели PHP домена настройте `upload_max_filesize=20M`, `post_max_size=210M`, `max_file_uploads=10`, `display_errors=Off`, `log_errors=On`. Это соответствует существующим ограничениям приложения. Размер HTTP request может дополнительно ограничивать hosting; проверьте фактические лимиты Jino.

Uploads находятся в **`~/project-brief-app/storage/app/private`** и доступны только через защищённые Laravel endpoints. **`storage:link` не нужен**, приватные attachments не копируются в public. Настройки доступа/маскирования HTTP access logs для `/access/*` следует проверить на Jino: URL содержит временную секретную ссылку; приложение не пишет token в свои logs.

## Проверки перед упаковкой

Сборка прекращается без финального архива при ошибке тестов или проверки. Проверяется наличие Vue assets, vendor/autoload.php, bootstrap, artisan, production entry point, `.htaccess`, CLI tools и безопасного env example. Запрещены `.env`, `.env.*` кроме production example, `.git`, `.tools`, node_modules, tests, local DB, `local-admin.json`, `*.key`, source maps, файлы credentials/secrets и содержимое private keys. Из локальных `.env`/local-admin.json берутся значения секретов только для сравнения с содержимым пакета — они не выводятся. Development Composer packages исключены. Данные и caches текущего приложения не копируются. Symlinks запрещены. Проверяются SHA-256 файлов внутри ZIP. `checksums.txt` можно проверить локально на Mac командой `shasum -a 256 -c checksums.txt` из распакованного пакета.

## Обновление

Сначала соберите и проверьте новый архив на Mac. Перед обновлением сохраните резервную копию production DB, приватной `.env` и `storage/`. Затем в приватной папке сервера:

```bash
php artisan down
```

Загрузите новые application files/vendor и public assets из архива. **Сохраните текущие `.env` и всё `storage/`, включая uploads и maintenance marker.** Не перезаписывайте их пустыми папками нового пакета. Для `bootstrap/cache` удалите старые `config.php`, `routes-*.php`, `events.php`, `packages.php`, `services.php` перед заменой; папку и права сохраните. Заменяйте vendor целиком, чтобы не оставлять старые dependencies. Не удаляйте private files через web-интерфейс приложения.

```bash
php artisan migrate --force
php artisan optimize
php artisan up
```

Если migration/optimize завершились ошибкой, оставьте maintenance mode и исправьте её перед `up`. Не выполняйте `migrate:fresh`, `db:wipe`, seed или смену APP_KEY. Проверьте health, login, клиентскую ссылку, reload Vue route и вложения. Здесь нет автоматического SSH/rsync, server-side Composer/Node/Git, web installer или default production password. Загрузка на Jino и проверка его реального document root/CLI PHP — отдельный следующий шаг.
