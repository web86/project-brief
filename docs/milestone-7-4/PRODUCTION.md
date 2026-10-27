# Project Brief 0.7.4 — обновление и откат

Это руководство не разрешает deployment. Обновление рассчитано на установленный 0.7.3. Production, push и merge в рамках Milestone 7.4 не выполнялись.

## Изменение схемы

Добавлена только миграция `2026_10_27_203324_create_project_feedback_table.php`. Таблица `project_feedback` хранит workspace/project/client, рейтинг, текст, время запроса/показа/отклонения/отправки и ссылку на существующий журнал `notification_deliveries`. Рейтинг и текст остаются NULL до отправки. Unique `(project_id, project_client_id)` обеспечивает один отзыв клиента на проект. Прежние данные не переписываются.

## Порядок обновления

1. Сверьте SHA-256 внешнего ZIP, CRC и внутренний `checksums.txt`. Проверьте пакет на защищённом staging. Сохраните согласованный backup БД, прежних public/private/vendor, `.env`, `APP_KEY`, storage и установленного `.htaccess` вне публичной директории.
2. Остановите записи и фоновые процессы, дождитесь завершения запросов и включите maintenance: `php artisan down` из private app.
3. Замените public/private/vendor согласованным комплектом ZIP согласно DEPLOY-JINO.md. Сохраните `.env`, APP_KEY, storage/uploads/sessions, credentials и maintenance marker. Не запускайте `first-install`, `key:generate`, seed, `migrate:fresh` или recovery-процедуры.
4. Примените новую миграцию до возобновления HTTP и пересоберите cache:

   ```sh
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   ```

   Все предыдущие миграции, включая `2026_10_26_200540_add_project_trash_lifecycle` и security migrations 7.x, должны оставаться применёнными. Не изменяйте origin/RP ID, HTTPS, cookie policy, срок сессий и настройки подтверждения безопасности.

5. Проверьте существующий SMTP и scheduler. Запустите Laravel `schedule:run` раз в минуту через уже используемый cron либо отдельный cron каждые 5 минут из private app:

   ```sh
   php artisan projects:feedback-mail --limit=20
   ```

   Команда повторяет pending/failed письма об отзывах старше пяти минут. Успешные записи повторно не отправляются. Успех тихий; ошибка даёт ненулевой код и диагностический код в журнале. Новые credentials, отдельный worker и новый механизм почты не требуются. Не отключайте существующие webhook/file cleanup jobs.

6. Выполните staging smoke ниже, затем после отдельного разрешения возобновите процессы и HTTP через `php artisan up`. Сохраняйте прежние ограничения HTTP hardening из раздела ниже.

## Поведение и staging smoke

- Карточка клиента: создание без письма; создание с письмом на RU/EN/TR/ZH; SMTP failure сохраняет клиента и показывает понятный результат; явный повтор из карточки. Дубликат нормализованного email возвращает прежнего клиента без нового письма. Новая email-ссылка использует существующий `/access/` и действует 7 дней. Ручное создание ссылки сохраняет прежнюю настройку срока. Явная отправка приглашения заменяет только ссылку выбранного клиента и использует существующий security step-up.
- Завершённость: минимум одна актуальная задача и все её статусы `done`; корзина не учитывается. Пустой или частичный проект не позволяет запросить отзыв.
- Владелец или назначенный разработчик запрашивает отзыв кнопкой проекта. После запроса клиент видит окно автоматически один раз при открытии проекта. «Позже», Escape и закрытие сохраняют состояние на сервере. Ручное «Оставить отзыв» доступно для завершённого проекта и без запроса команды.
- После отправки — один отзыв и одно намерение доставки владельцу workspace. Двойной HTTP-запрос не изменяет отзыв и не отправляет письмо повторно. SMTP-сбой не отменяет отзыв; cron восстанавливает доставку. Чужой проект даёт 404, другой клиент не видит рейтинг/текст чужого отзыва. Повторное открытие/завершение задач не сбрасывает состояние.
- Комментарии: редактор перед списком, новые записи сверху, одинаковые timestamps упорядочены по серверной последовательности. Ошибка сохраняет черновик и файлы; сворачивание основной секции не затрагивает редактор. Отдельное «Обсуждение» сохраняет прежний порядок и пагинацию.
- Проверить обе темы, 390/768/1440 px, длинные имена/email и одновременно несколько мобильных notices. Проверить прежние login/security, клиентские ссылки, комментарии/вложения, PDF/DOCX и мобильную навигацию.

SMTP не имеет общей транзакции с БД: если SMTP подтвердил письмо, а процесс аварийно завершился до записи результата в БД, абсолютное exactly-once недостижимо. Обычные двойные HTTP-запросы и повторные запуски после записанного успеха дедуплицируются. Не делайте выводов о доставке реальному адресату по локальному SMTP-тесту.

## Откат 0.7.4

Остановите записи и scheduler, верните согласованный предыдущий public/private/vendor, сохраните `.env`, APP_KEY, storage и установленный `.htaccess`; пересоберите cache прежним кодом. Новую таблицу и отзывы оставьте в БД: прежний код их игнорирует. `down()` миграции намеренно запрещён, чтобы не потерять данные. Полный откат схемы возможен только восстановлением согласованного backup с учётом новых записей после обновления. Pending mail остаётся в журнале до возврата 0.7.4; автоматически удалять его нельзя. После smoke снимите maintenance и возобновите прежние процессы.

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
