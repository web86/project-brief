# Project Brief 0.7.5 — обновление и откат

Руководство предназначено для согласованного обновления установленного 0.7.4 на Jino/PHP 8.4. Deployment не выполнялся. Ветка 7.4 опубликована в main по отдельному прямому поручению пользователя; новая 7.5 остаётся локальной. Сначала защищённый staging, затем отдельное разрешение на production.

## Изменение схемы и хранилища

Единственная новая миграция: `2026_10_27_215858_add_branding_to_workspaces.php`. Она добавляет nullable JSON `workspaces.branding` без backfill и изменения прежних данных. Там находятся секции visual, business, contact, export, email и метаданные assets. При NULL работают стандартные значения, брендированные email выключены, банковские реквизиты в документах выключены.

Файлы: существующий private local disk, `storage/app/private/branding/{workspace-uuid}/{random-token}.png` и очищенный SVG-компаньон при SVG-загрузке. Клиенту и письмам доступна только нормализованная PNG-картинка через непрогнозируемый URL `/branding-assets/{workspace}/{token}.png`. Исходный SVG не публикуется. Замена/удаление попадает в существующую очередь `project_file_deletions`; текущий cleanup/scheduler должен продолжать работать. При замене старый URL перестаёт обслуживаться приложением; уже доставленное письмо может показывать прежнее изображение только из кеша почтового клиента.

## Порядок обновления

1. Сверьте SHA-256 ZIP с внешним RELEASE-ARTIFACT.json, CRC архива и внутренний checksums.txt. Сохраните согласованные backup БД, public/private/vendor, `.env`, APP_KEY, всего storage, установленных cron и `.htaccess` вне публичного каталога.
2. Остановите записи и фоновые задания, дождитесь текущих запросов. Из private app включите maintenance: `php artisan down`.
3. Замените public/private/vendor комплектом ZIP по DEPLOY-JINO.md. Сохраните `.env`, APP_KEY, storage/uploads/branding/sessions и maintenance marker. Не накладывайте пустой storage из пакета поверх данных. Не выполняйте first-install, key:generate, seed, migrate:fresh, db:wipe или recovery.
4. Проверьте web и CLI PHP 8.4: существующие расширения, включая GD, DOM/libxml, fileinfo, mbstring и zip; для WebP должна быть поддержка WebP в GD. Убедитесь, что ограничения web upload/post позволяют загрузку файла 2 МБ и PNG-компаньона SVG до 4 МБ, а память достаточна для изображения до 4096×4096 / 16 млн пикселей. Это проверяется на Jino отдельно от локального CLI.
5. Примените миграцию и пересоберите cache:

   ```sh
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   ```

   Прежние миграции, включая `2026_10_26_200540_add_project_trash_lifecycle` и `2026_10_27_203324_create_project_feedback_table`, сохраняются. Не меняйте HTTPS origin/RP ID, cookie policy, срок/шифрование сессий и security step-up.

6. Выполните smoke ниже в maintenance/staging. После отдельного разрешения выполните `php artisan up` и возобновите прежний scheduler. Сохраните действующий feedback-mail retry (`php artisan projects:feedback-mail --limit=20`) и очистку файлов; новых SMTP credentials, workers, DNS или cron для брендирования не требуется.

## Smoke на staging

- Владелец видит «Брендирование» в нижней группе и профиль с прежней ссылкой аккаунта. Разработчик не получает настройки; прямые GET/PATCH/upload чужого workspace дают отказ. Переключение workspace не переносит формы и цвета.
- Сохранение отдельных секций, повтор при сетевой ошибке, изменения во время запроса и подтверждение ухода. Сброс оформления сохраняет бизнес-реквизиты и контакты, включая их несохранённый черновик в форме.
- Загрузить/заменить/удалить PNG/JPEG/WebP и статичный SVG. Проверить favicon, прозрачность, пропорции и отложенную очистку. Скрипты, события, внешние ресурсы и XML entities в SVG не исполняются.
- Проверить клиентский портал на RU/EN/TR/ZH, обе темы, обычный/яркий/белый цвет. Админка и общие страницы сохраняют системную тему/favicon. Цвет ошибок/успеха не заменяется.
- Экспортировать существующие варианты ТЗ/прайс-листа в PDF/DOCX с брендом и без; включить/выключить адрес/контакты/реквизиты. Проверить суммы, оценку, Unicode, шапку, ссылку платформы, footer и нумерацию. Реквизиты находятся единожды в конце.
- Проверить реальную доставку проектных приглашений, комментариев/ответов, статусов и согласования через действующий транспорт. Сохранить From, правильные абсолютные ссылки и сроки клиентского доступа. Восстановление доступа и глобальная безопасность остаются оформлением платформы. Локальные Mail::fake/render и packaged HTTP не доказывают доставку SMTP на Jino.
- Список >20 проектов: поиск, активность, клиент у владельца, сортировка, URL и возврат, пустая последняя страница, корзина/восстановление. Разработчик видит только назначенные проекты и не может искать частные email клиентов через фильтр.
- Сайдбар: desktop и mobile drawer, короткая высота, touch, Tab/Shift+Tab/Escape, исчезновение градиента в конце; нижние настройки и профиль остаются видимыми.
- «Собственный домен / отправитель»: disabled, «Скоро», без успешного подключения или изменения транспорта.

## Откат 0.7.5

Остановите записи и scheduler, включите maintenance. Верните согласованные прежние public/private/vendor 0.7.4, сохранив `.env`, APP_KEY, весь storage (включая новые branding-файлы), установленный `.htaccess` и marker. Пересоберите cache старым кодом, выполните smoke и возобновите HTTP/scheduler.

**Не выполняйте migrate:rollback.** Колонку `workspaces.branding`, записи migration и файлы нужно оставить: старый код игнорирует добавленную колонку. `down()` новой миграции намеренно запрещён, чтобы не удалить реквизиты. Физический откат схемы возможен только восстановлением согласованного backup с оценкой новых записей после обновления. Не сбрасывайте APP_KEY/сессии ради отката. До возврата 0.7.5 оформление перестанет применяться, данные сохранятся. Очередь очистки файлов не нужно вручную обнулять.

## Сохранённые требования предыдущего HTTP hardening

## Host-dependent settings: inactive until preflight

- `HTTP_BOOTSTRAP_LIMIT_ENABLED=false`, including when absent from installed `.env`. The 120/minute setting is a staging starting point, not production capacity. Before enabling, verify actual socket peer/proxy and NAT baseline. The guard runs before StartSession, uses REMOTE_ADDR/file cache, shares one GET/HEAD csrf/session/security-status budget and has at most 4096 hashed IP buckets (8192 counter/timer keys). Collisions share budgets. No global API actor budget is enabled.
- `<IfDefine PROJECT_BRIEF_NATIVE_ERRORS>` is inactive by default. With inherited dynamic PHP error pages, rejected scans can still invoke the inherited handler. Ask Jino whether ErrorDocument is allowed and whether parent/proxy error pages invoke PHP. Support may define the name in server/vhost config after confirmation. If support instead approves per-directory ErrorDocument, remove only the enclosing IfDefine lines, retain the two native ErrorDocument directives and record this deliberate host delta. Never activate blindly.
- No new Apache module, HSTS, full CSP, CAPTCHA, WAF, UA/country blacklist, daemon or SQL request logger is enabled. mod_headers was tested locally, not on Jino; without it static headers/cache rules are skipped, while Laravel dynamic headers work independently.

## Read-only Jino preflight — all account-specific settings UNKNOWN

Confirm true document root/symlinks; Apache 2.4 patch/MPM/web handler; mod_rewrite, mod_headers, authz_core; effective AllowOverride/AllowOverrideList for existing Options/DirectoryIndex/Require/RewriteRule and Header unset/always. ErrorDocument permission is separate if enabling its block. Confirm actual **web** PHP 8.4 extensions/memory/body/upload/workers, CPU/DB/connection/disk/log quotas. CLI ini is not web-handler proof.

Verify HTTPS redirect/termination and original peer/trusted proxy chain. Never enable wildcard trusted proxies or trust arbitrary forwarded headers. Read only allowlisted cached flags: DEBUG=false, cookie Secure/HttpOnly/Lax/host-only, existing lifetime/encryption, CACHE_STORE/cache.limiter. File cache is the documented single-host baseline; do not switch existing stores blindly.

For access/error/proxy/statistics logs and all copies/backups, verify ACL/readers, external analytics, retention and masking/exclusion of `/access/[redacted]` **before recording**, including Referer/error logs. PHP redirect/no-referrer/redaction does not remove an initial request already logged by Jino. Do not send real links, raw logs, `.env`, auth.json, cookies or tokens to support. If a secret is actually public, stop deployment and prepare scoped incident response; never revoke all links automatically. Host modules, ACL, masking, quotas and DDoS guarantees remain UNKNOWN.

No public phpinfo/probes, scanners, brute force, load loops or limit-exhaustion tests on live Jino. Safe allowlisted read-only commands are in the repository research document, intentionally outside the runtime ZIP.

## Update existing Jino installation

1. Verify external ZIP SHA-256, CRC and internal checksums.txt outside public; rehearse in protected staging. Back up the matched previous code/vendor/public, DB, private configuration and storage together. Preserve older releases.
2. Save the exact **deployed** `.htaccess` plus checksum outside public, including legitimate host changes. The ZIP also contains `deployment/rollback-public.htaccess`, the exact pre-milestone canonical file from baseline 1ef85a2. Use it only when it matches the installation. Never upload the ZIP, private tree or deployment helpers into public.
3. Prepare independent SSH/SFTP/panel rollback access that works during HTTP 500. Pause writes/scheduler/webhook workers and drain requests; enter maintenance in the installed private application:

   ```sh
   cd ~/project-brief-app
   php artisan down
   ```

4. Follow DEPLOY-JINO.md: private application to `~/project-brief-app/`, only public files to `~/domains/brief.web86.site/`. Replace vendor as a matched whole; preserve `.env`, APP_KEY, storage/uploads/sessions, credentials and maintenance marker. Never run first-install/key:generate/seeders/operator recovery or rotate links/tokens during this update.
5. Apply `php artisan migrate --force --no-interaction` for the additive feedback migration before serving 0.7.4. Keep earlier migrations applied, including `2026_10_26_200540_add_project_trash_lifecycle` and the 0.7.2 recommendations migration. Rebuild caches while maintenance is active:

   ```sh
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   php -r 'require "vendor/autoload.php"; echo class_exists(\Webauthn\Credential::class) && class_exists(\Illuminate\Console\View\Components\Secret::class) ? "PASS: runtime classes present\n" : "FAIL: runtime classes missing\n";'
   ```

6. New installations default to daily logs, 14 retained files, 0600 and locking. Existing `LOG_CHANNEL=single` stays unchanged unless the owner deliberately adopts `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14` and rebuilds config. Do not replace `.env` with its example or delete old logs automatically. Daily rotation limits file count, **not bytes/day**; hosting size rotation/quota alerts remain required and UNKNOWN. Expected security failures log the first code/stage per minute through bounded file-cache keys; 5xx are unsampled. Generic exception logs include a reference, exception class, source basename/line; omit SQL bindings, message, stack arguments, URL and request content.
7. Preflight actual directive permissions before replacing `.htaccess`. Upload a complete temporary file outside public on the same filesystem, then atomic rename after approval; never overwrite the live file in chunks. Leave native-errors and bootstrap IP guards inactive until verified.
8. Run `php artisan up`; use a few ordinary requests and disposable test data: root/admin/client deep navigation, existing link without a logged terminal, health/static; Secure cookie/CSRF, real Passkey/TOTP fallback, existing/cancelled proof; authorized API, attachment/preview, one PDF/DOCX, SW/manifest/push, all four locales. Check private no-store and no duplicate headers. Do not exhaust limits or send scanner traffic to production. Keep live smoke evidence distinct from UNKNOWNs.

## Immediate `.htaccess` rollback

Syntax 500, a blocked legitimate path or broken login/maintenance triggers rollback. Do not debug regex on production. Restore the exact saved deployed file through independent SSH/SFTP, atomically; use the packaged baseline only if known to match. Confirm account paths and same filesystem before using this example:

```sh
# Only during an approved rollback.
cp ~/project-brief-rollback/previous.htaccess ~/project-brief-rollback/restore.htaccess
chmod 644 ~/project-brief-rollback/restore.htaccess
mv -f ~/project-brief-rollback/restore.htaccess ~/domains/brief.web86.site/.htaccess
```

Verify checksum and one health/page/static request. Restore rather than deleting `.htaccess`, which loses routing/protection. No DB/session/credential reset is needed. Normal Apache rereads it without restart; host behavior is UNKNOWN. Restoration target is 1–2 minutes with access ready, not a measured Jino SLA. For code rollback restore the matched previous application/vendor/public under maintenance, preserve `.env`/storage and rebuild caches; no schema rollback. Do not rotate APP_KEY or invoke operator recovery to repair configuration.
