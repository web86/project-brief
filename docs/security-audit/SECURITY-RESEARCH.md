# Milestone 7.3 — Security Audit & Bot Protection Research

Дата: 27 октября 2026 года (Europe/Istanbul). Статус: исследование завершено; реализация требует отдельного утверждения.

Исторический срез до реализации Issue #8. Впоследствии владелец разрешил локальную реализацию и Composer registry audit; актуальные изменения, выполненные проверки, оставшиеся Jino UNKNOWNs и release evidence находятся в [Milestone 7.3 verification](../milestone-7-3/VERIFICATION.md). Исходные findings ниже сохранены как основание решений, а не описание состояния после исправлений.

## Область исследования и достоверность

Исследован локальный `main`, commit `1ef85a241e9529aeab275566ebf308575a1c4a9c`. На начало работы дерево было чистым, ветка опережала локальный `projects-manager/main` на один коммит. Remote не обновлялся: соответствие текущему GitHub main и установленной production-ревизии этим аудитом не удостоверяется.

В lock-файлах: Laravel **13.34.0**, Fortify **1.41.0**, PHP Passkeys **0.2.1**, PHPWord **1.4.0**; Vue **3.5.43**, Vue Router **4.6.4**, vue-i18n **11.4.13**, JS Passkeys **0.4.0**, pdfmake **0.3.13**, Vite **7.3.6**. Локальный CLI PHP — **8.5.10**; PHP **8.4** на Jino — исходный контекст заказчика, а не результат удалённой проверки. Требование Composer `php: ^8.3` и platform `8.3.30` не определяет версию production PHP.

Проведены чтение исходников и установленного framework, инвентаризация 159 Laravel-маршрутов, проверка существующего ZIP, запуск существующих тестов в изолированной локальной среде и изучение официальных источников. Не выполнялись HTTP-запросы к production, SSH, чтение production-журналов, активное сканирование, подбор или нагрузочные испытания. Изменяется только этот документ. Конфигурация, `.env`, зависимости, миграции, авторизация и release-архивы не менялись; push/merge/deploy не выполнялись.

Обозначения в отчёте:

- **К** — подтверждено кодом/конфигурацией или локальной проверкой. Это не всегда означает эксплуатируемую уязвимость.
- **У** — условный риск: требуется дополнительное условие или измерение.
- **Х** — свойство production/Jino не проверено на данном аккаунте.

**Главный вывод:** минимальный полезный слой — дешёвый отказ Apache для однозначно посторонних путей, адресные лимиты дорогих операций и безопасные существующие журналы. Текущая модель авторизации уже имеет существенную защиту. Подтверждённого обхода авторизации или критической production-уязвимости в исследованном объёме не найдено. Доказательств текущей атаки, утечки или допустимого RPS нет. `.htaccess` уменьшает стоимость обработки сканирования, но не гарантирует доступность origin при распределённом HTTP flood или насыщении канала.

## A. Текущее состояние

### A.1. Apache, маршрутизация и production ZIP

Источник: `deployment/public/.htaccess:1–31`, `deployment/public/index.php:8–20`, `api/routes/web.php:44–57,159,243–247`, `api/app/Http/Controllers/SpaController.php:14–26`.

| Объект | Что происходит сейчас | Оценка |
|---|---|---|
| Document root | Public entry point подключает соседний `project-brief-app`, приложение/vendor/storage вне public | Правильная архитектура; фактический root/symlink на Jino ещё проверить |
| Индексы | `Options -Indexes -MultiViews`, `DirectoryIndex index.php` | Сохранять; не добавлять слепо `!-d`: это может отдать каталог вместо SPA |
| Приватные имена | `FilesMatch "^\."` запрещает файлы с точкой в начале имени | Не является запретом всех скрытых каталогов и их обычных дочерних файлов, например `.git/config` |
| PHP | Любой существующий файл проходит мимо завершающего rewrite `!-f` | Единственный PHP в проверенном public ZIP — `index.php`; случайно оставленный `probe.php`/`.phtml` отдельным правилом не запрещён |
| Backend | `/api`, `/access`, `/sanctum` переписываются в Laravel даже при совпадении с файлом; `index.html` тоже через Laravel | Сохранять порядок и maintenance-поведение |
| Неизвестные GET/HEAD | Кроме reserved prefixes, при наличии `index.html` любой путь получает SPA, статус 200 | `/wp-admin/`, `/phpmyadmin/`, `/cgi-bin/...`, `/anything.php`, пропавший `/assets/chunk.js` запускают PHP и web middleware; ложный 200 затрудняет мониторинг |
| Неизвестные мутации | SPA-контроллер допускает только GET/HEAD | После middleware будет 404; CSRF может раньше вернуть 419. Не обещать всем неизвестным POST одинаковый статус |
| Статика | Существующие файлы напрямую, hashed JS/CSS/TTF — годовой immutable при `mod_headers` | Полезно; отсутствие модуля молча отключает правило. Нельзя распространять immutable на HTML, SW или API |
| Ошибки Apache | Локальные `ErrorDocument` не заданы | Наследование Jino неизвестно; отказ не должен внутренне возвращать запрос в Laravel |

Нет специальных правил для backup/config/dump-файлов, неиспользуемых CMS и альтернативных исполняемых расширений. Это пробел в дополнительной защите, **не доказательство размещённого секрета или webshell**. `FilesMatch` проверяет имя файла, а `RewriteRule` в `.htaccess` сопоставляет путь относительно каталога; query string не входит в этот шаблон. [Apache mod_rewrite](https://httpd.apache.org/docs/2.4/mod/mod_rewrite.html).

Каноническая сборка — `./bin/build-release` → `deployment/build-release.mjs:75–140,165–220`: чистое Git-дерево, npm CI/tests/build, Composer/tests, отдельный staging vendor `--no-dev`, public/private структура, проверка состава и checksum. В `deployment/release-files.mjs:6–48,124–end` проверяются запрещённые пути, секреты, private keys, отсутствие dev vendor и symlink. Это проверка пакета, а не замена HTTP-проверки Apache. Public-фильтр явно запрещает лишние `.php`/`.vue`, но не исчерпывает `.phtml`/`.phar`/CGI и все backup-суффиксы: имеет смысл расширить контракт public allowlist.

Read-only проверка существующего `release/project-brief-v0.7.3-05d4414.zip`:

- размер **39 149 306 байт**, SHA-256 **`86f507c330d4bec938b84aa2c68a95084b39f609185354dc89afe0dc9c9182a9`**;
- ZIP CRC: без ошибок, 10 052 entries; 64 public-файла;
- среди проверенных PHP/PHTML/PHAR/CGI/PL public-файлов найден только `public/index.php`;
- packaged `.htaccess` побайтно совпадает с исследованным deployment-файлом;
- `vendor/composer/installed.json`: `dev=false`, Laravel 13.34.0; нет `node_modules` и `.map`;
- commit пакета — `05d44141ce135f5142792280b8b764a5a7b5e067`, он отличается от HEAD. Полная повторная сборка, распаковка/HTTP-запуск и повторный content secret scan пакета в этом исследовании не выполнялись.

### A.2. Инвентаризация ограничений запросов

Определения: `api/app/Providers/AppServiceProvider.php:26–42`; назначения — `api/routes/web.php`; stateless API — `api/routes/api.php:8–19` и `AuthenticateIntegration.php:13–42`; AI — `AiTaskAssistant.php:269–288`.

Числа ниже — текущие значения/default, а не предложение. Для `perMinute` окно 60 секунд с TTL от первого попадания в bucket, не скользящее окно и не точный потолок конкурентных запросов. Именованные Laravel buckets включают имя limiter; разные limiter обычно независимы. Превышение route throttle даёт **429**, `Retry-After` и rate-limit headers. На `/api/security/*` ответ преобразуется в `SEC_RATE_LIMITED` с diagnostic ID, заголовки исключения сохраняются (`AccountSecurityDiagnostics.php:19–60`). Нет CAPTCHA или постоянной блокировки пользователя. [Laravel rate limiting](https://laravel.com/docs/13.x/rate-limiting).

| Поверхность / limiter | Ключи | Лимит / окно | Что учитывать |
|---|---|---|---|
| Пароль, `admin-login` | IP **и** SHA-256 lower(email) | 5/IP + 5/email / 60 s | Все попытки, не только неуспешные. Email bucket защищает от ротации IP; низкий IP-порог может мешать NAT |
| Passkey options + verify, `security-passkeys` | IP **и** session ID | 10 + 10 / 60 s, общий для login/confirm/register | Одна церемония расходует минимум два запроса; повторные options тоже считаются |
| TOTP/recovery challenge, `security-challenge` | IP **и** authenticated/pending user ID, иначе session ID | 5 + 5 / 60 s | Pending user ищется SQL-запросом уже при вычислении limiter; session rotation не снимает account bucket |
| Подтверждение/изменение security, `account-security` | User ID **и** IP | 5/user + 15/IP / 60 s | Общий бюджет confirm/setup/recovery/passkey management и части account/team actions |
| Forgot/reset, `account-recovery` | IP **и** SHA-256 normalized email | 5/IP + 3/email / 60 s | Дополнительно password broker throttle; нейтральный forgot-ответ. Разные email не обходят IP bucket |
| `/access/{token}`, `access` | IP | 20 / 60 s | Новый session ID не снимает лимит; 256-bit secret делает угадывание непрактичным. На валидный вход — DB update |
| Комментарии/обсуждения, `comments` | Session ID | 30 / 60 s | Не глобальный лимит всей REST-поверхности; другой session получает другой бюджет |
| Upload/avatar/task-create, `uploads` | Session ID | 15 / 60 s | Task-create расходует budget даже без файла. Несколько сессий/клиентских ссылок увеличивают суммарный бюджет |
| PDF JSON, `browser-pdf` | Session ID | 5 / 60 s (`BROWSER_PDF_RATE_LIMIT`) | Сам PDF строит браузер, не PHP; CPU/SQL подготовки JSON всё равно учитываются |
| DOCX `/export` | **Нет отдельного limiter** | — | PHPWord и загрузка полного набора данных выполняются на PHP |
| AI | Workspace ID, общий admin/client | 5 / 60 s; 50 до следующей полуночи UTC | Квота резервируется под 5 s cache lock до provider request; отказ 429 с retry; quota-lock не является lock всей генерации |
| Telegram connect/test | Client ID либо User ID; отдельное имя limiter | 5 / 60 s для каждого действия | Пользовательские операции, не bearer/webhook secret |
| Telegram inbound | IP | 120 / 60 s | Может быть один IP Telegram/bridge для многих событий; важны retry и dedup |
| `/api/v1/*` | IP до token lookup; затем Workspace ID | 120/IP и 60/workspace / 60 s | Валидный токен не снимает IP-порог. 429 + Retry-After + no-store. Отдельного token budget нет |
| Числовые `throttle:20,1`, `10,1`, `30,1`, `5,1` | Auth user ID, иначе route domain + IP | 60 s | Invitation resolve/accept, profile/notification writes, integration UI/test/retry, push и др. **Без отдельного prefix делят один ключ**, хотя пороги различны |
| `/api/csrf`, `/api/session`, `/api/security/status` | **Нет route limiter** | — | Cookie/session/SQL работа, bootstrap запрашивает каждый открытый клиент |
| Обычные admin/client read/update endpoints | В основном **нет общего limiter** | — | Есть authorization; это не защита от чрезмерного числа разрешённых запросов |
| SPA и fallback, `/api/health`, `/up` | **Нет limiter** | — | `/api/health` специально без StartSession, `/up` отдельный framework health route; не считать их равноценными сложным API |

Числовые limiter проверены по установленному `Illuminate/Routing/Middleware/ThrottleRequests.php:90–100,224–232`: метод и URI не входят в signature, default prefix пуст. Например, push POST может потратить budget, из-за которого integration test с порогом 5 ответит 429. Это подтверждённая связанность и риск UX, не обход авторизации. Не копировать числовые лимиты на новые endpoint без именованного bucket.

Cache и session — разные источники нагрузки. Production example `api/.env.production.example:21–38` задаёт **`CACHE_STORE=file`**, **`SESSION_DRIVER=database`**. Сам limiter тогда не пишет MariaDB; file cache всё равно использует диск/locks и создаёт много ключей при ротации IP/email. В `config/cache.php:18` fallback — database, `cache.limiter` не задан; отсутствие ожидаемого override может вернуть counters/locks в БД (`CacheServiceProvider.php:34–37`). Эффективные production-значения неизвестны.

Web routes начинают session до route throttle. Успешные cookie-less GET к CSRF, session и SPA создают/сохраняют новые DB sessions; TTL **525 600 минут** и lottery `[2,100]` (`config/session.php:35,117`, framework `StartSession.php:110–129,169–189`, `DatabaseSessionHandler.php:143,206,301`). Поэтому один Laravel throttle после StartSession не устраняет стоимость старта session, а даже без limiter сам публичный fallback даёт дешёвый путь к росту DB. На исключениях запись session зависит от пути обработки: нельзя считать каждый 404/429 новой DB-строкой без измерения.

### A.3. Laravel: существующая защита и границы проверки

| Механизм | Доказательство и оценка |
|---|---|
| CSRF/CORS | Cookie API размещён в `web.php`; стандартный `PreventRequestForgery` активен. Исключения health и Telegram явно перечислены, Telegram имеет собственный secret. `config/cors.php:3` не разрешает cross-origin. `/api/v1` отдельно stateless bearer, CSRF для него не нужен. Authorization/X-XSRF передаются Apache; не удалять |
| Cookie | Production example: Secure, HttpOnly, SameSite=Lax, host-only, шифрование session payload. XSRF cookie намеренно читается JS, это не дефект HttpOnly. Реальные cookies/TLS/proxy chain ещё проверить |
| Fixation/revocation | `AccountSecurity.php:120–127`, `AccessLinkController.php:66–72`, `AdminAuthController.php:47–51`: invalidate/regenerate, новый CSRF. `EnsureAccountSession.php:14–20`, `AccountProfile.php:27–42`: auth_session_version и отзыв сессий |
| Долгая session | 365 дней — idle lifetime, не абсолютный максимум; активность сдвигает срок. Украденная cookie может жить долго; MFA/step-up не защищают все обычные reads от владельца такой cookie. Это принятый UX tradeoff, не доказанная кража и не причина незаметно сократить срок |
| TOTP/Passkey | `AccountSecurity.php:175–238`: одноразовые challenge, session/user/version binding, TOTP replay guard; `AccountSecurityController.php:186–281`: origin/RP/UV verification библиотекой, проверка userHandle и пользователя. Default Fortify/Passkeys routes отключены в provider:20–21 |
| Step-up | `AccountSecurity.php:56–85`: 5 минут, session hash, user/version, policy fingerprint, DB challenge и допустимый factor. Sensitive actions повторяют проверки внутри транзакций/locks, например access link:25–34, integration controller:23–30,53–57, security controller:223–235. Подтверждённого обхода в рассмотренных потоках не найдено |
| Tenant/IDOR | `CurrentWorkspace.php:13–43`, `ProjectAccess.php:13–33`, `ProjectPermissions.php:33–59`, `TaskAccess.php:29–43`; `EnsureAdmin.php:19–30` выполняется до model binding; client middleware:15–36 связывает token/client/project. Чужие ресурсы исключаются серверными запросами; UUID сам по себе защитой не считается |
| Access links | `AccessLinkController.php:24,34,55–72`: random 32 bytes, SHA-256 в БД, format guard до lookup, rotation/revocation, redirect без секрета, no-store/no-referrer. `expiresAt` опционален; ссылка может быть многократной и бессрочной. Это увеличивает последствия раскрытия в журналах |
| Upload/download | `AttachmentRequest.php:17`: до 10 × 20 MiB, comment до 5 × 20 MiB. `SafeAttachment.php:18–50`: MIME+extension allowlist, контроль изображений до 24 MP, проверка структуры DOCX; private UUID storage; архивы не распаковываются. `AttachmentController.php:45–61`: project-scoped доступ, no-store/nosniff/sandbox CSP, inline только raster image. PHP/SVG/HTML uploads не разрешены. Это не антивирус всех документов |
| Avatar | `AccountController.php:119`, `AccountProfile.php:45–65`: 2 MiB, raster MIME/размеры до 4096 px/16 MP, перекодирование. Декодирование всё равно стоит памяти; снижения лимитов без реальных memory metrics не предлагается |
| Export | Client field restrictions на сервере в `ProjectExportData.php:23–70`. PDF JSON: 1000 задач, 2 MiB текста, 8 MiB JSON (`config/browser_pdf.php:4–7`). DOCX: тот же builder вызывается без этих параметров (`ProjectExportController.php:18–23`), затем весь документ формируется в памяти; есть реальный пробел ресурсных границ |
| Bearer API | `IntegrationTokens.php:14–18`: random 256 bit + prefix, только hash в БД, secret возвращается однократно. `AuthenticateIntegration.php:20–35`: строгий формат, expiry/revocation, scopes/workspace; `IntegrationApiController.php:18–35`: JSON/32 KiB/unknown-fields guard. Пагинация до 100. Token не использует уязвимый built-in TokenGuard |
| Webhooks | Telegram controller:65–79 — независимый constant-time secret, 16 KiB, без cookie session/CSRF; `TelegramWebhook.php:51–66` — dedup update_id. Outbound `WebhookDestination.php:10–53`, `WebhookProcessor.php:83–109`: HTTPS/public IP, повторная DNS-проверка и pinning, запрет redirect, HMAC, 3/8 s timeouts, 16 KiB response, retries. Не отключать verification/secret ради throttling |
| Errors/logs | Production example DEBUG=false; security diagnostics выводит allowlist и не прикладывает exception/SQL/body (`AccountSecurityDiagnostics.php:37–60`). `bootstrap/app.php:54` исключает чувствительный flashed input. Остальные generic exceptions остаются стандартными framework logs: это не гарантия отсутствия секретов в SQL bindings/exception text при любой будущей ошибке |
| PWA | `public/sw.js:1` — push-only, без fetch handler/приватного API cache. PDF использует worker, fonts; полный CSP требует проверки worker/blob/inline bootstrap. Автоматического service-worker cache leak в текущем коде не найдено |

### A.4. HTTP-заголовки

На уровне исходников отсутствует общая политика **HSTS, frame protection, CSP для SPA, Referrer-Policy и nosniff для всех ответов**. Отдельные security/download/export ответы уже имеют часть этих заголовков. Это подтверждение отсутствия общей политики в репозитории; наличие заголовков, добавленных Jino, неизвестно.

`TaskResource`, `ProjectResource`, часть client JSON и validation/error responses не устанавливают явно `no-store`; например `TaskController.php:22–24`, `ProjectController.php:19–39`, inline client routes `web.php:169–173`. Symfony/Laravel могут добавить `private, no-cache`, но это разрешает хранение с revalidation. Нельзя заявлять доказанную shared-cache утечку: CDN нет, SW не кеширует API. Нужна единая проверяемая политика `private, no-store` для confidential API и ошибок.

Предложение по заголовкам учитывает [OWASP HTTP Headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html): начать с nosniff, no-referrer, X-Frame-Options=DENY; CSP frame-ancestors для SPA добавлять без перезаписи строгого attachment CSP. HSTS включать только после подтверждения всей HTTPS/proxy цепочки, сначала короткий max-age, без includeSubDomains/preload. Полный CSP — отдельный P2: `index.html:8,184` содержит inline scripts, Vue использует inline styles, PDF/PWA требуют worker, blob и fonts. Жёсткий `script-src 'self'` без hash/nonce сейчас способен сломать запуск.

### A.5. Composer/npm и известные advisories

Lock-инвентарь: 106 production и 38 dev Composer packages; 133 npm lock entries вместе с корнем и optional packages. Проверка lock не удостоверяет уже установленный vendor на Jino.

После явного разрешения владельца **27 октября 2026, около 20:23 Europe/Istanbul** выполнен полный npm registry audit lock-файла и отдельный production audit. Использован только официальный `https://registry.npmjs.org`, Node **22.23.2**, npm **10.9.8**; никакие установки, lifecycle scripts, `audit fix` или обновления зависимостей не выполнялись. После первоначальной ошибки проверки сертификата использован временный CA bundle из системного `/etc/ssl/cert.pem`; проверка TLS оставалась включённой, постоянная npm-конфигурация не менялась.

| Запуск | Результат registry | Exit code | Сохранённое доказательство |
|---|---|---|---|
| Полный npm, включая dev/optional/peer | **1 vulnerable package: Vite, High**; critical/moderate/low/info package counters — 0 | 1: уязвимость найдена, это не ошибка запроса | [npm-audit-full.json](npm-audit-full.json) |
| npm `--omit=dev` | **0 известных уязвимостей** в проверенном production dependency graph | 0 | [npm-audit-production.json](npm-audit-production.json) |

Полный JSON содержит **пять advisories внутри одной Vite entry**: один High, три Moderate, один Low. Package counter использует наибольший уровень; это не пять отдельных уязвимых packages. Registry metadata полного запуска: 132 dependency entries, prod 70, dev 63, optional 53 (категории пересекаются, их нельзя суммировать). Результат отражает известные реестру advisories на момент запроса, а не отсутствие всех потенциальных уязвимостей или проверку установленного production bundle.

Область npm audit — корневой `package-lock.json`, используемый канонической сборкой frontend (`deployment/build-release.mjs:83–87`). Отдельный Laravel scaffold `api/package.json` содержит другие dev ranges, но не имеет `package-lock.json`/`npm-shrinkwrap.json` и не используется этой frontend-сборкой. Для него нет зафиксированного графа, поэтому он не объявляется проверенным; новые зависимости/lock-файл ради исследования не создавались.

Выполненные команды (stdout сохранён в указанные JSON; cache/CA только в `/private/tmp`):

```sh
npm audit --json --package-lock-only --include=dev --include=optional --include=peer --ignore-scripts --registry=https://registry.npmjs.org --cafile=/private/tmp/project-brief-security-ca-bundle.pem --cache=/private/tmp/project-brief-security-npm-cache
npm audit --json --package-lock-only --omit=dev --ignore-scripts --registry=https://registry.npmjs.org --cafile=/private/tmp/project-brief-security-ca-bundle.pem --cache=/private/tmp/project-brief-security-npm-cache
```

SHA-256 `package.json` до/после: `2a9ce2d48d5095a636445b95c5c9f9b6d12b7f037a3b37b5115a926c88891710`; `package-lock.json`: `f71a7d5d612cb6d4d2da8e51f5eba8ee0c4ab4fce79bcf9cf52720ca2eedf364`. Файлы не изменились. SHA-256 результатов: full — `3a43094df274a8a3566494ca20f9258768e8ccd1586f36ab1488e365af6285a6`, production — `2232b3cd51c356dc486bda5211f1e594f9891a42588bdfd1db8dd76b8b8e4872`.

**Composer audit остаётся незавершённым:** первоначальный `composer audit --locked --format=json` не получил данные из-за DNS/network sandbox. Последующее разрешение владельца относится к npm registry, не к отправке Composer inventory в Packagist. Результат «0» для npm production не распространяется на PHP-зависимости; для полного Composer registry audit требуется отдельное разрешение. Ручной обзор первичных Composer advisories ниже сохраняет свои ограничения.

| Пакет | Проверенное состояние / применимость |
|---|---|
| Vite 7.3.6, dev | Registry подтвердил affected range `7.0.0–7.3.6`, `fixAvailable=true`; package severity **High**, пять advisories разобраны ниже. Исправленная 7.x по первичным advisories — **7.3.7**. В текущем `vite.config.js` обычный SPA, dev/preview scripts bind 127.0.0.1. Эксплуатация здесь не доказана; loopback сам по себе не универсальная защита от browser-assisted атак. Обновление отдельной задачей P1, без major bump/fix --force |
| Другой Vite advisory | [GHSA-vfpm-58rq-9qcg](https://github.com/vitejs/vite/security/advisories/GHSA-vfpm-58rq-9qcg) указывает 8.2/8.3 ranges, **не 7.3.6**. Не включать его в находки этого проекта только по совпадению имени Vite |
| Laravel 13.34.0, production | Выше patched 13.29.0 для [TokenGuard bypass](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6), 13.27.0 для [Unauthorized Data Destruction](https://github.com/laravel/framework/security/advisories/GHSA-5wcm-3v2w-8362), 13.30.0 для [debug-page XSS](https://github.com/laravel/framework/security/advisories/GHSA-jh5r-qr3c-85q8). Эти конкретные advisories не являются открытыми уязвимостями исследованной версии. `config/auth.php:40–45` использует session guard, custom bearer проверяется отдельно |
| PHPWord 1.4.0, production | Изучены [официальные releases](https://github.com/PHPOffice/PHPWord/releases) и [advisories](https://github.com/PHPOffice/PHPWord/security/advisories). В проекте writer DOCX, не reader чужих DOCX/HTML templates (`DocxProjectExporter.php:5,90`). Конкретная применимая CVE этим обзором не подтверждена; полнота транзитивных packages не установлена |
| Fortify, Passkeys, Vue/i18n, pdfmake, production | Версии зафиксированы выше; изучены в том числе [Fortify advisories](https://github.com/laravel/fortify/security/advisories), [vue-i18n advisories](https://github.com/intlify/vue-i18n/security/advisories), [pdfmake advisories](https://github.com/bpampuch/pdfmake/security/advisories). Для npm graph теперь получен registry result 0 production findings; Composer graph ещё не проверен полностью. Vue/i18n/pdfmake код входит в browser bundle, поэтому нельзя считать все npm dependencies «только для разработки» |

Пять advisories Vite из полученного JSON:

| Advisory | Severity advisory | Условия и применимость |
|---|---|---|
| [GHSA-587h-7wmj-h5pm](https://github.com/vitejs/launch-editor/security/advisories/GHSA-587h-7wmj-h5pm) | **High** | Windows-specific обход `fs.existsSync`, в сочетании с escaping issue позволяет command injection при контроле file argument. Требование создать вредоносный файл обходится — нельзя переносить на этот advisory ограничения более ранней проблемы. По заявленной платформе не затрагивает текущую macOS и Apache production |
| [GHSA-698v-4r3x-3vcv](https://github.com/vitejs/launch-editor/security/advisories/GHSA-698v-4r3x-3vcv) | Moderate | `%` escaping Windows shell, специальные условия враждебного пути/environment/NTFS. Не относится к macOS/Linux execution по данному advisory |
| [GHSA-r825-w8mm-5pxw](https://github.com/vitejs/launch-editor/security/advisories/GHSA-r825-w8mm-5pxw) | Moderate | Некоторые редакторы трактуют path как CLI flag; требуется сочетание поддерживаемого редактора и attacker-controlled filename. Не объявляется неприменимым к macOS только из-за OS |
| [GHSA-rq7h-c2jc-7f22](https://github.com/vitejs/vite/security/advisories/GHSA-rq7h-c2jc-7f22) | Moderate | Non-Windows dev server, специальное совпадение импортируемого project path с readable system path и другие условия advisory. Уязвимая версия подтверждена; конкретный достижимый импорт в проекте не доказан |
| [GHSA-9jrq-w75r-8gcw](https://github.com/vitejs/vite/security/advisories/GHSA-9jrq-w75r-8gcw) | Low | Custom middleware/transformIndexHtml и inline module; текущая обычная SPA-конфигурация сама по себе не подтверждает выполнение условий |

`launch-editor` встроен в Vite bundle: отсутствие отдельного `node_modules/launch-editor` в lock не исключает эти findings; установленный `node_modules/vite/dist/node/chunks/config.js` содержит bundled launch-editor 2.14.1. Исправление следует планировать через совместимый Vite patch, а не неподтверждённый override отсутствующего lock node.

Исторический `docs/milestone-7/VERIFICATION.md:50` записывал одну high Vite entry; текущий registry audit независимо подтвердил такую package-level сводку. Vite server/Node/dev vendor не входят в проверенный production ZIP, Apache обслуживает собранные статические файлы. Dev-server advisory не означает наличие такого endpoint на production. После будущего утверждённого patch повторить full/prod npm audit; для Composer отдельно выполнить `--no-dev` и полный audit после разрешения. Не передавать секреты или `.env`, не запускать автоматическое исправление.

## B. Таблица рисков

Severity оценивает последствия в контексте проекта, priority — порядок работ. P0 не присваивается только из-за громкого названия scanner/CVE.

| Риск | Серьёзность | Доказательство | Возможное решение | Риск регрессии |
|---|---|---|---|---|
| К/R1: SPA 200 для scanner и неизвестных assets; PHP/session стоимость | Средняя, availability; P1 | `.htaccess:22–23`, `SpaController:16–21`, web fallback:247 | Ранние bounded Apache deny, native 404 отсутствующих assets; сохранить SPA deep links | Слишком общий regex блокирует реальные frontend URLs |
| К/R2: DOCX без throttle и task/text bounds | Средняя; авторизованный источник, P1 | `web.php:142,164`, `ProjectExportController:18–23`, builder nullable limits:23 | Именованный export budget + preflight limits до materialization/render | Большие законные экспорты; локализованный 413/429 и согласованные границы |
| К/R3: Публичные CSRF/session/SPA создают DB session; годовой idle TTL | Средняя, P1 | `web.php:44,51–52`, `session.php:21,35,117`, framework StartSession/DB handler | Ранний bootstrap guard, сбор baseline DB sessions; не увеличивать срок и не сокращать тайно | Один NAT, несколько вкладок, first-load login/PWA |
| К/R4: Соседние числовые throttles делят budget | Низкая/средняя, UX/availability; P1 | `ThrottleRequests:90–100,224–232`, `web.php:176–187,225` | Именованные действия с явными actor keys; сохранить текущие security budgets | Непреднамеренное ослабление действующих ограничений |
| К/R5: Нет общей frame/nosniff/referrer и no-store политики | Средняя, defense in depth; P1 | `.htaccess:27–31`, `EnsureAccountSecurity:22–26`, `TaskController:22–24` | Базовые headers + единый API response policy, включая ошибки | Inline images/exports; дублирование Header always и app headers |
| К/R6: Security diagnostics пишет warning на каждый отказ, включая 429; single log без ротации | Средняя, disk/IO; P1 | `AccountSecurityDiagnostics:33,53`, `.env.production.example:21–22`, `logging.php:61–72` | Ротация, size cap, bounded aggregate/sampling для повторов; серьёзные события сохранять | Потеря полезной диагностики при чрезмерном sampling |
| К/R7: Обычные authenticated REST reads/updates без общего budget, тяжёлые коллекции без pagination | Средняя, P1 для budget; P2 для redesign | `TaskController:22–24`, `ProjectController:19–21`, web routes | Мягкий actor budget отдельно от auth; детальная pagination после измерений | Autosave, polling, shared client identities, UI контракты |
| У/R8: Случайный существующий PHP/PHTML, backup или private directory в public не защищён полноценно | Высокая при ошибке публикации; P1 профилактика | `.htaccess:4–6,23`, public package filter `release-files:31–48`; проверенный ZIP чист по названным признакам | Public allowlist пакета + Apache guards из C | ACME `.well-known`, font licenses, public assets |
| У+Х/R9: Bearer client token в access/error/referrer/статистике Jino | Высокая при доступе посторонних; **условный P0** | URL содержит reusable secret (`AccessLinkController:34,55`); `DEPLOY-JINO.md:98` уже требует host check. Фактических logs не читали | Проверить ACL/копии/агрегаторы, маскирование до записи на всех слоях; при доказанном раскрытии согласовать rotation только затронутых ссылок | Смена формата magic link ломает сохранённые ссылки; массовый отзыв нарушает работу |
| У/R10: Годовая cookie без абсолютного максимума усиливает последствия кражи | Средняя/высокая при краже; P2 policy | `session.php:35`, DB session last_activity обновляется | Сохранить UX; отдельно согласовать absolute TTL/session management при необходимости | Повторный вход, PWA, access links; это продуктовая политика |
| У/R11: Concurrent bursts допускаются check-then-hit; last_used_at обновляется каждый bearer request | Низкая/средняя; P2 | `AuthenticateIntegration:16–32`, framework throttle:155–165 | Не считать throttle semaphore; при измеренном contention — concurrency guard дорогих действий, debounce timestamp | Потеря точности audit, порядок доступа, file lock contention |
| У/R12: Generic exception/SQL error может нести чувствительные bindings; прямой URL может попасть в error_log | Высокая при раскрытии; P1 review, P0 при подтверждении | `bootstrap/app.php:37–44` sanitizes только security, прочие ошибки стандартные | Безопасные synthetic regression tests ошибок; allowlist logging в нужных местах; host error-log masking | Недостаточная диагностика; нельзя просто выключить все 5xx logs |
| К+У/R13: Vite 7.3.6 в affected ranges, dev exposure требует условий | **High по npm package**, контекст см. A.5; P1 maintenance | Registry JSON: 1 package / 5 advisories; High Windows-specific, другие условия разобраны отдельно; server не в ZIP | Отдельный patch upgrade после утверждения, dev/bundle regression | Build/plugin compatibility; не менять auth/deps в исследовании |
| Х/R14: Версии/modules/AllowOverride/TLS/proxy/IP/квоты/DDоS на конкретном Jino | Неизвестно; prerequisite | Нет remote inspection; официальные страницы разного поколения | Read-only inventory и ответ поддержки, раздел E | Неподдержанная директива → 500; spoofed forwarded IP → неверные buckets |
| Х/R15: Полный актуальный **Composer** audit отсутствует; npm часть закрыта | PHP graph неизвестен; P1 verification | Composer DNS/network failure; npm full/prod JSON получены после разрешения | Согласованный Composer registry audit production/dev либо доверенная offline advisory база | npm production «0» нельзя распространять на Composer или отсутствие неизвестных уязвимостей |

**Подтверждённого P0 нет.** R9/R12 становятся P0 только при подтверждённой доступности секретов посторонним; DEBUG=true или private data в document root на production также потребуют P0, но эти состояния не наблюдались.

## C. Предлагаемый `.htaccess` — только проект изменений

Это добавления к существующему файлу, **не применённая конфигурация** и не универсальный шаблон. Предусловия: Apache 2.4 на обслуживающем домен instance; разрешены текущие Options/Indexes/AuthConfig и FileInfo для rewrite/ErrorDocument/headers; подтверждён `mod_headers`; нет конфликтующих parent rules или Alias/CGI, обходящих этот каталог. `IfModule` проверяет наличие модуля, но не разрешение директивы через AllowOverride.

### C.1. Ранние guards

Внутри существующего `<IfModule mod_rewrite.c>`, после передачи Authorization/X-XSRF и **до** reserved-prefix rewrite вставить:

```apache
# Hidden path segments, retaining ACME's .well-known namespace.
RewriteRule "(^|/)\.(?!well-known(?:/|$))" - [F,L,NC]

# No application/private trees or build manifests belong in this document root.
RewriteRule ^(?:vendor|storage|bootstrap|config|database|private|deployment|node_modules)(?:/|$) - [F,L,NC]
RewriteRule ^(?:artisan|composer\.(?:json|lock)|package(?:-lock)?\.json)$ - [F,L,NC]

# Only the root front controller may be a PHP endpoint; reject PATH_INFO too.
RewriteRule ^(?!index\.php$).*\.(?i:php[0-9]*|phtml|pht|phar)(?:[./]|$) - [F,L]
RewriteRule \.(?:cgi|pl|py|sh)(?:[./]|$) - [F,L,NC]

# Known foreign technology namespaces, anchored at root.
RewriteRule ^(?:wp-admin|wp-content|wp-includes|wp-json|phpmyadmin|pma|cgi-bin)(?:/|$) - [F,L,NC]

# Accidental backups/configuration, never application-controlled downloads.
RewriteRule (?:~|\.(?:bak|old|orig|save|swp|sql|sqlite3?|ini|log|conf|env|ya?ml|zip|tar|tgz|gz|7z))$ - [F,L,NC]
```

После существующих правил для reserved prefixes и `index.html`, но до общего `!-f → index.php`:

```apache
# A missing build asset is a real Apache 404, not an HTML SPA response.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(?:assets|font-licenses)(?:/|$) - [R=404,L]
```

Почему такой объём:

1. Hidden-segment guard закрывает `.git/config` и `.env.*` в пути; `.well-known/acme-challenge/...` не блокируется этим guard. Существующий `FilesMatch` остаётся. Дополнительные точки внутри `.well-known` не получают общего разрешения.
2. Private-tree/manifest guard ограничен корнем домена; `/api`, `/admin`, `/project`, `/access` не совпадают. `admin`, `login`, `api`, UUID, языки и query strings не используются как deny-признаки.
3. PHP guard сохраняет **ровно** root `index.php`, включая внутренние rewrite; `/index.php/anything`, `/folder/index.php`, `/probe.PHTML`, `/test.php.jpg` запрещены. Прямой `/index.php?x=...` остаётся обычным front controller. Это блокирует лишь перечисленные расширения; безопасность не должна зависеть от предположения, что неизвестных handler mappings нет.
4. WP/phpMyAdmin/CGI guard снимает стоимость заведомо чужих namespaces; `wp-login.php`/`xmlrpc.php` уже покрыты PHP guard. Jino phpMyAdmin в панели — отдельная услуга, не маршрут приложения; если hosting Alias обслуживает такой путь раньше `.htaccess`, его поведение должен подтвердить Jino.
5. Backup guard не запрещает `.json`/`.txt`/`.svg`/`.webmanifest` целиком. Вложения выдаются endpoint `.../{uuid}/download`, имя файла — в Content-Disposition, поэтому `.zip` во вложении не блокируется. Прямой публичный архив релиза не должен быть доступен.
6. Native missing-assets 404 ограничен реально существующими каталогами build. Ошибки cache устаревшего HTML будут видны, а браузер не получит HTML вместо JS. Не добавлять blanket «отклонять всё, что не файл»: Vue history reload требует Laravel.

Дополнительно, после проверки наследуемых error handlers можно добавить:

```apache
ErrorDocument 403 default
ErrorDocument 404 default
```

Это предотвращает возврат ранних отказов в приложение через унаследованную страницу ошибки. На локальном Apache доказать отсутствие запуска PHP/записи DB для deny и missing-assets. Не добавлять подробный динамический logger каждого отказа.

### C.2. Минимальные headers и cache

В существующий `<IfModule mod_headers.c>` предлагается добавить, сохранив hashed-assets правило:

```apache
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "no-referrer"
Header always set X-Frame-Options "DENY"

<Files "sw.js">
    Header set Cache-Control "no-cache"
</Files>
<Files "manifest.webmanifest">
    Header set Cache-Control "no-cache"
</Files>
```

`no-cache` здесь разрешает revalidation публичного SW/manifest; confidential API должен иметь `private, no-store` в Laravel. Не назначать глобальный public cache и не кешировать ответы с Set-Cookie. Existing hashed filename regexp оставить до отдельного asset-scope review. В Apache есть разные header tables (`onsuccess`/`always`): итоговую пару Apache+Laravel проверять на дубли/противоречия, а не только наличие одной строки. [Apache mod_headers](https://httpd.apache.org/docs/2.4/mod/mod_headers.html).

В этот минимальный patch намеренно не включены HSTS/CSP/method bans/body-limit директивы: они требуют отдельных условий. Для HSTS сначала проверить реальный TLS и принудительный HTTP→HTTPS в панели. При TLS termination `%{HTTPS}` на Apache может быть off; нельзя доверять присланному клиентом `X-Forwarded-Proto` без подтверждения очистки хостером. CSP `frame-ancestors 'none'` для SPA совместим с frame protection, но общий `Header set Content-Security-Policy ...` может заменить уже более строгий sandbox CSP вложения. Полный CSP сначала проверяется в report-only без отправки полных URL/credentials внешнему сборщику. Не добавлять COEP/COOP, `X-XSS-Protection`, deny User-Agent/стран и blanket deny OPTIONS/POST.

## D. Laravel hardening

### D.1. Адресные изменения с заметной пользой

Все числа ниже — **стартовые предлагаемые настройки для staging**, без доказательства production capacity; после baseline и NAT-тестов их нужно согласовать.

| Изменение | Предлагаемая граница | Порядок и совместимость |
|---|---|---|
| DOCX | Именованный `document-export`: 5/min/actor и 10/min/workspace; preflight 1000 tasks/2 MiB text как исходная точка | Admin actor — User ID, client — ProjectClient ID, разные prefixes. Проверять доступ раньше дорогой генерации; 429 до builder/render. Project size guard до `get()`; 413 с локализованным объяснением. Согласовать допустимые большие DOCX до внедрения |
| Bootstrap | Именованный общий CSRF/session/status budget, пробный 120/min/IP с burst, отдельный namespace | Guard до StartSession, без обращения к session/user/DB. Это требует явного middleware order/global path guard, обычный route throttle недостаточен. Не трогать `/api/v1`, Telegram, health. 429 + Retry-After + private/no-store; frontend не должен делать бесконечный retry |
| Обычный authenticated API | Пробный 240/min/actor отдельно от auth/export/uploads; разделить или исключить частый polling после измерений | Вешать после определения admin/client и до тяжёлого controller; auth endpoints и webhook не смешивать. Общий клиентский identity может использоваться несколькими людьми; учесть вкладки, autosave, мобильный reconnect |
| Числовые limiter | Заменить на имена действий с явным actor fallback | Первым делом invitation resolve/accept, push writes, integration settings/test/retry, profile/notification writes. Не менять password/TOTP/Passkey policy заодно |
| Confidential responses | Централизованный `private, no-store`, nosniff и safe referrer для `/api/*` | Единый final-response hook должен покрыть success и exception 401/403/404/419/422/423/429/500, streaming/download headers. Не полагаться только на код после `$next`, который пропустит exception |
| Logging | Daily rotation + ограничение bytes + bounded повторные security warnings | Не логировать каждый rejected bot запрос в SQL. При 429/повторяемых 4xx агрегировать code/stage/status, первый диагностический случай и счётчик; 5xx оставлять пригодными для диагностики |

Для bootstrap IP брать из реально проверенной server/proxy цепочки, не из любого входного forwarded header. Никакого `trustProxies('*')` ради исправления лимитов. В shared-hosting запрос может проходить внутренний proxy Jino даже при отсутствии CDN. До подтверждения `$request->ip()` по документации/панели нельзя подбирать production IP-пороги.

### D.2. Что сохранить и чего сейчас не добавлять

- Password + account buckets, Passkey ceremony budget, TOTP replay/challenge binding, пятиминутный proof, owner policy, revalidation внутри транзакций сохраняются. Бюджет Passkey options/verify нельзя снизить без учёта двух и более запросов одной церемонии.
- Клиентский 20/min/IP уже защищает lookup от одного источника; добавить ранний syntax rejection для malformed token можно, но это небольшая оптимизация. Отдельный limiter по произвольному plaintext token не нужен: он не ограничивает перебор и создаёт неограниченную cardinality. Подтверждённой необходимости CAPTCHA нет.
- AI workspace quotas достаточны для текущей базовой политики. Ошибочные запросы до provider всё равно нужно ограничивать общим authenticated budget. Если реальные concurrent requests насыщают PHP, отдельный короткий per-workspace in-flight lock с release в finally — P2, а не расширение существующего quota-lock на сетевой вызов без анализа.
- REST bearer 120/IP + 60/workspace уже ограничен. Per-token fairness и debounce `last_used_at` — P2 при нескольких интеграциях/наблюдаемом contention. Не убирать workspace bound в пользу одного token bound и не уменьшать IP-порог слепо.
- Telegram: не вводить CAPTCHA/interactive challenge и session. IP-limit 120/min может затронуть общий источник/bridge; улучшение — разделение cheap invalid-secret gate и authenticated update budget после подтверждения поведения retry. Сначала измерить; успешный dedup оставить. `terminating` отправка ответа Telegram может удерживать PHP worker, особенно без FastCGI; это нагрузка валидных событий, не public secret bypass.
- `/api/health` лёгкий stateless liveness; оставить без SQL limiter, например высокая host-level частота при реальной необходимости. `/up` framework route дополнительно учесть в мониторинге; не делать его административным или добавлять DB-readiness на каждом запросе.
- Полный rewrite SPA на static HTML ради экономии PHP изменит maintenance gate. Пока достаточно guards C; исключение session на безопасных HTML/fallback routes — P2 с тестом cookies и maintenance, а не слепое удаление `web` middleware.
- Пагинация тяжёлых task/history/project коллекций — отдельный контракт UI/API после измерений. Не превращать security milestone в переписывание доменной архитектуры.

### D.3. Большие запросы и cache backend

Приложение ограничивает поля и файлы, API writes — 32 KiB, Telegram — 16 KiB. Но чтение/разбор тела PHP/Laravel и multipart может произойти раньше controller validation. Нужны согласованные Apache/PHP лимиты **на входе**, не только `strlen()` после обработки. Multipart максимум текущего task-upload — 200 MiB плюс overhead; comments — 100 MiB. Глобальный `LimitRequestBody 1M` сломает существующий контракт. Согласовать допустимый batch размер, затем `post_max_size`, `upload_max_filesize`, `max_file_uploads`, web execution/memory, API-specific limits с Jino. Не помещать `php_value`/`php_flag` в production `.htaccess`, пока не подтверждён handler: CGI/FPM и mod_php имеют разные возможности.

При file cache ограничивать cardinality: counters по именованному действию и actor/IP, не по произвольному URL, UA, token или телу. Не создавать DB-строку для каждого сканирования; не использовать `array` cache в production — worker-local counters не защищают между запросами. DB counter на каждый 403/404 усилит нагрузку. Memcached/Redis рассматривать лишь при подтверждённой доступности и реальном узком месте; переключение cache store не входит автоматически в минимальный milestone.

## E. Рекомендации по Jino

### E.1. Что подтверждено документацией, а что неизвестно

Актуальная [справка Jino Hosting](https://jino.ru/spravka/hosting/) указывает Apache **2.4**, наличие `mod_rewrite`, настройку web/PHP через панель, access/error/MySQL slow/CPU logs и графики CPU/MySQL/HTTP/traffic. Это возможности сервиса, не инвентаризация данного домена. Старый [FAQ](https://o.jino.ru/help/faq/virtualhosting-general/) всё ещё пишет Apache **2.2**, monthly access logs в `~/logs`, просмотр error_log через панель, 50 DB connections и устаревшие PHP-версии. Эти старые числа **не принимаются за текущие квоты аккаунта**.

[Страница PHP](https://jino.ru/spravka/hosting/php.html) описывает `.php`/`.phtml`, 128 MiB и режим module/CGI с настройками через панель. Это также не доказывает web SAPI или memory limit выбранного PHP 8.4 на данном контейнере. Нужна effective web-конфигурация; CLI отличается.

| Возможность | Статус для Project Brief | Как установить безопасно |
|---|---|---|
| Apache patch version / MPM / web PHP SAPI / внутренний proxy | Х; product docs не дают exact domain configuration | Technical information в панели + ответ поддержки; доступный read-only `apachectl -v/-M` лишь дополнительное свидетельство |
| mod_rewrite | Заявлен Jino, используется проектом; на домене не проверен | Техническая информация, обычные deep links на staging |
| mod_headers, authz_core | Нужны для предложенных headers и существующего Require, наличие на домене не подтверждено | Modules list/поддержка, effective response headers на staging |
| mod_security / mod_evasive / CRS / request limiting | **Не подтверждены**; отсутствие найденного описания не доказывает отсутствия | Уточнить installed/enabled, доступные per-domain правила, исключения для webhooks и uploads |
| mod_ratelimit | Не подтверждён и не решает RPS | Даже если доступен, это per-response bandwidth, может дольше удерживать worker; для anti-flood не предлагать. [Apache](https://httpd.apache.org/docs/2.4/mod/mod_ratelimit.html) |
| AllowOverride / AllowOverrideList / handler mappings / parent ErrorDocument | Х | Поддержка должна подтвердить конкретно Options, DirectoryIndex, Require, RewriteRule, Header, ErrorDocument, доступные body limits |
| PHP workers / CPU / memory / DB concurrency / request & connection quotas | Х; у shared account могут быть общие квоты доменов | Панель ресурсов + actual service plan; запросить hard/soft limits и период расчёта, поведение 503/kill |
| Host edge/network DDoS и L7 protection | **Нет подтверждения условий защиты этого тарифа** | Запросить уровни L3/L4/L7, thresholds, порядок инцидента, возможность блокировки домена, доступную статистику; статья о DDoS не является SLA |
| Logs, ACL, retention, masking, third-party analytics | Сервис предоставляет logs; фактические настройки и все копии неизвестны | Уточнить web/error/frontend/proxy/statistics/backup logs, права дополнительных FTP/панель users, ротацию, raw URL/referrer masking |

В [оферте Jino](https://jino.ru/about/offer/jino-offer.pdf) предусмотрены меры при DDoS, создающем чрезмерную нагрузку другим клиентам, включая временное снятие делегирования с ресурсов провайдера. Это дополнительная причина заранее согласовать incident procedure, а не обещать безусловную DDoS-защиту. Не переносить инструкции VPS/root на shared hosting и не включать новые платные услуги автоматически.

### E.2. Безопасный read-only checklist для владельца

**Ниже только подготовленные команды; в ходе аудита они на production не выполнялись.** Пользоваться существующим SSH-доступом, не создавать публичные `phpinfo.php`, probes, debug endpoint или новый shell. Не выводить `.env`, process environment, config:show database/services, полный phpinfo или необработанные logs.

Версия CLI/безопасные ini-параметры — это не web SAPI:

```sh
php -r 'echo "php_cli=".PHP_VERSION." sapi=".PHP_SAPI.PHP_EOL; foreach (["memory_limit","max_execution_time","post_max_size","upload_max_filesize","max_file_uploads"] as $k) { echo $k."=".ini_get($k).PHP_EOL; }'
if command -v apachectl >/dev/null 2>&1; then
    apachectl -v
    apachectl -M 2>/dev/null | awk '/^[[:space:]]*(rewrite_module|headers_module|authz_core_module|security2_module|evasive20_module|ratelimit_module|mpm_event_module|mpm_worker_module|mpm_prefork_module)[[:space:]]/ { print $1 }'
fi
```

Отказ/неполный список не доказывает отсутствия модуля: CLI может читать другую конфигурацию или не иметь прав. Не применять `sudo`, reload/restart или менять права ради inventory.

Прочитать только безопасные effective **cached** Laravel flags без загрузки приложения/записи package cache:

```sh
cd "$HOME/project-brief-app"
php -r '
$p = "bootstrap/cache/config.php";
if (!is_file($p)) { echo "config_cache=absent; effective web settings require panel verification\n"; exit; }
$c = require $p;
$keys = ["app.debug", "session.driver", "session.lifetime", "session.expire_on_close", "session.encrypt", "session.secure", "session.http_only", "session.same_site", "cache.default", "cache.limiter", "logging.default"];
foreach ($keys as $key) {
    [$section, $name] = explode(".", $key, 2);
    echo $key."=".json_encode($c[$section][$name] ?? null).PHP_EOL;
}
echo "session.host_only=".json_encode(empty($c["session"]["domain"])).PHP_EOL;
'
```

Не добавлять сюда arbitrary config dump. Cached значения нужно сопоставить с web behavior/панелью; команда не проверяет TLS, IP и cookie атрибуты HTTP-ответа.

Подготовленные одиночные HTTP-проверки обычных публичных URL, без cookie, без follow redirects и без вывода Set-Cookie/body:

```sh
curl --silent --show-error --max-time 10 --max-redirs 0 --dump-header - --output /dev/null https://brief.web86.site/api/health |
    awk 'BEGIN { IGNORECASE=1 } /^(HTTP\/|[Cc]ache-[Cc]ontrol:|[Ss]trict-[Tt]ransport-[Ss]ecurity:|[Cc]ontent-[Ss]ecurity-[Pp]olicy:|[Xx]-[Cc]ontent-[Tt]ype-[Oo]ptions:|[Rr]eferrer-[Pp]olicy:|[Xx]-[Ff]rame-[Oo]ptions:)/ { print }'
```

Для проверки SPA/статики вручную заменить URL на `/` или `/sw.js`, по одному запросу; не превращать в цикл. Проверки сканер-путей, разных методов, больших тел и 429 — **только локально/staging**. Не использовать реальные access links или bearer в терминале/аргументах curl. Права и log inventory смотреть через панель без публикации raw entries.

### E.3. Особый риск `/access/{token}`

URL-secret попадает к серверу раньше PHP. `#[SensitiveParameter]`, SHA-256 в БД, redirect и no-referrer не удаляют request target из `access_log`, server error logs, TLS/front proxy logs или агрегаторов/Webalizer. Даже общий no-referrer не стирает первый запрос. Без raw production logs и ACL нельзя утверждать ни утечку третьим лицам, ни её отсутствие.

Первое действие владельца — проверить, кто может читать все эти журналы и их резервные копии, включена ли внешняя статистика, какое хранение/маскирование доступно. Для поддержки достаточно шаблона `/access/[redacted]`; реальную ссылку не передавать. При возможности требуется **нормализованный путь до записи** либо исключение таких запросов из raw URL logs с сохранением агрегированного количества/status; также обработать Referer и error logs. `LogFormat`/`CustomLog` — server/vhost directives, в `.htaccess` их помещать нельзя. [Apache mod_log_config](https://httpd.apache.org/docs/2.4/mod/mod_log_config.html).

Одного `SetEnvIf` или маскирования в последующем отчёте недостаточно: первичный log уже записан. Если Jino не поддерживает masking, минимальная мера — строго приватные logs с ограниченным сроком/доступом; при неприемлемом остаточном риске отдельно спроектировать fragment-based link (`/access#token=...` → POST обмен), сохраняя legacy links на переходный срок. Это изменение протокола, вне минимального patch. Нельзя объявить его решением для старых `/access/{token}` без миграционного плана.

### E.4. Минимальный мониторинг

Основной источник — существующие Apache/Jino logs и графики, без постоянного процесса и без второй таблицы HTTP-событий в MariaDB. План соответствует [OWASP Logging](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html): секреты/сессии/body исключаются, формат и retention ограничены.

| Метрика | Источник / реализация | Хранение и действие |
|---|---|---|
| 403/404/429/5xx и общее число запросов | Streaming read access_log, счётчики за 1/5 минут | Только timestamp bucket/status/count; 7–14 дней, без IP/UA/полного URI |
| Самые частые scanner namespaces | Только фиксированные labels `wp-admin`, `wp-content`, `phpmyadmin`, `cgi-bin`, `other-php`, `unknown`; query/fragment и identifiers отбрасывать до счётчиков | Не печатать произвольные top URLs. Любой attacker-controlled path может содержать секрет/PII |
| Laravel vs static rejects | Класс маршрута, status и host CPU/HTTP; на staging инструментировать PHP entry counter | 403 сам по себе не доказывает отказ до PHP; session DB rows/latency сравнить отдельно |
| DB/PHP pressure | Графики Jino CPU/MySQL, slow-query/CPU reports; sessions count/возраст по агрегатам при отдельно разрешённом read-only DB доступе | Не выносить SQL bindings/slow-query text/URL в отчёт; latency/RPS не вычислять без duration field |
| Ошибки security | Существующий allowlist diagnostic code/stage/status; rate-limit repeats агрегировать | ID одного обращения можно сохранять; factors/credentials/cookie/token — никогда |

Для владельца достаточно сначала панели; если нужен автоматический digest, короткое задание существующего cron каждые 5–15 минут с byte offset/inode, bounded file read и отдельным маленьким файлом состояния вне public. Это предложение, cron не создан. Для rotation/truncation обнулять offset; не перечитывать каждый раз весь месячный log. Сначала обработка в памяти, затем только агрегаты, permissions 0600. Сырые строки не копировать в новый архив и не пересылать. Не использовать raw token как ключ даже в hash: идентификация такой ссылки в мониторинге не нужна.

Предлагаемый срок raw app logs — 7–14 дней, агрегаты — 14 дней; raw host logs — минимальный доступный срок, согласовать Jino. Daily rotation ограничивает число файлов, **но не размер одного дня**: нужен доступный hosting size rotation/дисковая квота и порог уведомления, например 100 MiB на app logs как начальная политика, не лимит самого хостинга. Не удалять управляемые Jino журналы произвольной командой и не отключать все error logs.

Уведомления только о значимых состояниях: рост 5xx (например ≥10 за 5 min и ≥5% ответов), устойчивые PHP/DB quota saturation, недоступность обычного health, disk headroom ниже согласованного порога, всплеск auth 429 совместно с обращениями пользователей или нагрузкой, раскрытие секрета. Сравнивать с baseline 24–72 часа; scanner 403 при нормальной нагрузке достаточно дневного digest. Один notification на инцидент, cooldown и recovery; не отправлять сообщение на каждый 403/429. Конкретные пороги пока не подтверждены данными.

### E.5. Граница возможностей origin

`.htaccess` может отказать до PHP по известному пути/расширению, запретить directory listing и случайный public файл, задать headers/cache при разрешённых модулях. Он не является счётчиком запросов сам по себе. Обработка всё равно занимает TLS/HTTP/Apache resources, чтение правил и logging. Он не останавливает распределённые запросы к разрешённому `/`, большие сетевые потоки, SYN/slow connections или обход по alternate origin/host configuration. Body/timeouts, connection/IP/RPS limits, network фильтрация и защита PHP worker pool зависят от Jino. `mod_evasive`/ModSecurity нельзя установить через `.htaccess` без поддержки хостера; правила CRS требуют проверки ложных срабатываний.

Внешний WAF/CDN нужен по условиям/масштабу атаки, если origin/канал насыщается до приложения и возможностей Jino недостаточно. Это P2 решение отдельного инцидента/роста, с планом webhooks, Passkey origin/RP, uploads, caching, real IP/trusted proxies и закрытия прямого обхода origin. Cloudflare не подключается автоматически. KISS-слой уменьшает пустую PHP/DB работу, но не даёт численного SLA устойчивости без измерений.

## F. План реализации и предлагаемый объём Milestone 7.3

Оценки — инженерные дни разработки/проверки; ожидание ответа Jino и утверждений отдельно. Это не разрешение на выполнение.

### P0 — критично при подтверждении опасного состояния

| Задача | Сложность | Польза / условие | Риски | Нужные проверки |
|---|---|---|---|---|
| P0.1: устранить фактическую доступность secret logs посторонним | 0.5–1 день + Jino | Только если R9/R12 подтверждены. Закрыть доступ/маскирование, определить scope раскрытия | Потеря диагностики, отзыв легитимных ссылок | ACL всех log/backup/statistics consumers; синтетический canary на staging без реального секрета |
| P0.2: реагировать на подтверждённый public `.env`/dump/DEBUG либо уже скомпрометированные credentials | По факту, не плановая миграция | Условие не обнаружено данным аудитом | Простои/массовое разлогинивание | Уточнить экспозицию, точечный отзыв/rotation с владельцем; не печатать значения |

Нет оснований делать аварийную смену APP_KEY/всех токенов/авторизации по результатам текущего исследования.

### P1 — рекомендуемый небольшой основной milestone

| Задача | Сложность | Польза | Риски | Необходимые тесты |
|---|---|---|---|---|
| P1.1: Jino preflight, log/privacy inventory, baseline | 0.5 дня + поддержка | Подтверждает модули, IP/TLS, quotas и границы допустимых правил | Неполный ответ хостера | E.2 allowlist, web flags, ACL; никакого production scanning |
| P1.2: guards C.1 + public package allowlist | 1 день | Снимает obvious scanner PHP/session load, закрывает случайные artifacts | Regex/handler/AllowOverride → 500/403 | Apache deny/allow matrix, actual file cases, error handler, распакованный ZIP |
| P1.3: headers/cache C.2 + confidential final-response policy | 0.5–1 день | Frame/referrer/MIME, отсутствие private response storage | Header conflicts, exports/inline previews | 200/3xx/4xx/5xx, streams, SW/update, hashed assets, duplicate headers |
| P1.4: DOCX budget и preflight size caps | 0.5–1 день | Ограничивает одну из самых дорогих PHP операций | Большие документы и bulk usage | Admin/client permission, 413/429 до render, 4 locale exports, Retry-After |
| P1.5: bootstrap и обычные API budgets, изоляция числовых buckets | 1–1.5 дня | Закрывает реальные frequency gaps с NAT-aware политикой | Multi-tab/PWA/shared identity, порядок middleware | До-StartSession 429 без DB, distinct actions, NAT, autosave/polling, session churn |
| P1.6: ротация и bounded повторные diagnostics; generic error redaction review | 0.5–1 день | Сдерживает disk/IO, не создаёт SQL logger | Потеря stack context | synthetic SQL/credential errors, secret absence, rotation/size, no notification storm |
| P1.7: отдельное patch-обновление Vite и завершение Composer audit | 0.5 дня | npm baseline уже выполнен: 1 High dev package, 0 production findings; patch устраняет affected ranges | Plugins/build; отправка Composer inventory требует отдельного разрешения | После patch повторить full/prod npm audit; Composer full/no-dev после разрешения, build + existing JS tests, clean package composition |
| P1.8: итоговая package/Apache regression + rollback rehearsal | 1 день | Проверяемый выпуск с безопасным откатом | Отличия local/staging/Jino | Матрица G, exact ZIP/hash/size, PHP 8.4 и production-like paths |

Рекомендуемый объём на утверждение: **P1.1–P1.6 и P1.8**, ориентир **5–8 инженерных дней** с общей тестовой работой; P1.7 — отдельный небольшой dependency patch, npm audit уже разрешён и выполнен, установка исправлений ещё не утверждена. Дизайн сохраняет существующие auth/session/permissions/client links, не требует миграций БД, CAPTCHA, нового демона или внешнего WAF. При выявлении настоящего P0 работа переключается на конкретный инцидент после согласования объёма.

### P2 — опционально по измерениям

| Задача | Сложность | Польза / триггер | Риски | Проверки |
|---|---|---|---|---|
| Лёгкий cron digest по host logs | 0.5–1 день | Если панели недостаточно | Ошибка parser/offset/retention | Sanitization, malformed lines, rotation, bounded memory/IO, cooldown |
| Полный CSP с hash/nonce, HSTS после short trial | 1–2 дня | Browser hardening | Inline bootstrap/PDF/fonts/Passkey/PWA | Report-only, 4 языка, all workers/blob/resources; HTTPS recovery |
| Concurrency guards / token fairness / timestamp debounce | 1–2 дня | Реально измеренные worker/DB collisions | Lock contention, общие budgets | Parallel isolated tests и deadlock/timeout/release |
| Pagination больших task/history reads, stateless SPA/session lifecycle, absolute session TTL | 2–4 дня, отдельно | Рост данных/DB session объёма | UI/API/maintenance/login contracts | Client/admin behavior и product acceptance |
| Fragment-based access-link exchange | 1–2 дня, отдельно | Jino не может гарантировать требуемую log privacy | Старые ссылки/JS/history/PWA | Backward compatibility, fragment never leaves browser, POST CSRF/redaction, no-store |
| Memcached/Redis/host WAF/CDN | По тарифу/инциденту | Подтверждённое узкое место или flood до PHP | Proxy trust, origin bypass, стоимость, false positives | Staging policy + host limits, webhook delivery, real IP, origin isolation |

## G. План проверки и отката

### G.1. Что проверено в этом исследовании

Существующие PHP tests запускались с APP_ENV=testing, SQLite `:memory:`, array cache/sessions, array mail, выключенным AI и отключённым внешним Telegram по умолчанию; профильные tests используют fakes. Результат: **192 tests passed, 2991 assertions, 13.295 s**, PHP CLI 8.5.10. Фильтр:

```text
ProductionRoutingTest|SessionBootstrapTest|SessionSecurityTest|AccessLinkTest|
AccountSecurityTest|FlexibleAccountSecurityTest|SeamlessSecurityConfirmationTest|
TenantIsolationTest|ProjectPermissionsTest|AttachmentTest|BrowserProjectExportTest|
ProjectExportTest|IntegrationsTest|IntegrationReadTest|TelegramWebhookTest
```

Это свидетельство текущих механизмов, **не тест ещё не реализованных guards**. Некоторые business tests через `TestCase::actingAs` моделируют завершённый login; protocol security tests отдельно выполняют endpoints и signed WebAuthn. Сохранены проверки настоящего CSRF и исчерпания login throttle. Дополнительно выполнены route inventory и read-only ZIP checks из A.1. Шаблон предлагаемого PHP guard из C проверен установленным PHP PCRE на 10 синтетических строках (canonical index, mixed-case, nested PHP, PATH_INFO и разрешённые API/assets): совпадения ожидаемые; это не Apache HTTP/configuration test.

После разрешения владельца дополнительно выполнены **полный npm audit и npm production audit**, оба получили корректный JSON от официального registry; результат и команды в A.5. Целостность JSON/сводки и неизменность `package.json`/`package-lock.json` проверены локально. npm не устанавливал и не обновлял зависимости.

Не выполнялись: Apache HTTP harness, Jino/staging/production inspection, browser/PWA регрессия, PHP 8.4 в этом запуске, полный PHPUnit/npm test suite, новый build-release и полный актуальный **Composer** registry audit. Отсутствие найденного IDOR в просмотренных потоках и прохождение тестов не является доказательством отсутствия всех уязвимостей приложения.

### G.2. Будущая локальная/staging среда

Использовать Apache 2.4 с реальным mod_rewrite/headers/authz и **тем же web PHP handler и AllowOverride**, которые подтвердит Jino. Document root — только распакованный public, приложение в соседнем private path как в deploy. Без symlink обходов и без PHP built-in server: он не проверяет `.htaccess`. Bind только loopback; БД/ключи/почта/AI/Telegram/push — синтетические/отключённые, никаких production credentials и исходящих сообщений. Новые тестовые scripts добавлять только на стадии реализации.

`apachectl -t` проверяет server config, но распределённый `.htaccess` читается при запросе: успешный configtest **не гарантирует**, что правило разрешено и не даст HTTP 500. Поэтому обязательны настоящие HTTP запросы в целевой директории, плюс error log без секретов. Ссылки: [Apache .htaccess](https://httpd.apache.org/docs/2.4/howto/htaccess.html), [core/AllowOverride](https://httpd.apache.org/docs/2.4/mod/core.html).

### G.3. Автоматическая матрица будущих изменений

| Слой | Сценарии | Ожидаемый результат |
|---|---|---|
| Apache rejects | `/wp-admin/`, `/wp-content/x`, `/wp-json/`, `/phpmyadmin/`, `/pma/`, `/cgi-bin/x`; mixed case | 403 до PHP, без session/DB/log body |
| Физические файлы | Созданные только в temp fixture `.env`, `.git/config`, `backup.sql`, `archive.zip`, `test.php`, `test.phtml`, `test.phar`, `test.php.jpg`, `sub/index.php`, `index.php/x` | 403 до PHP; отсутствие fallback SPA. Проверять именно existing file, не только nonexistent path |
| Нормализация | Dot segments, percent-encoded dot/extension/slash, double encoding, повторные slash, trailing slash, PATH_INFO | Запрещённый файл не выдаётся/не исполняется. 400/403/404 по подтверждённой Apache normalization допустимы; не требовать от regex raw URL поведение |
| Allowed backend | `/api/csrf`, `/api/session`, `/api/security/*`, `/api/v1/*`, `/access/{synthetic}`, Telegram endpoint | Собственные Laravel 200/302/401/403/419/422/423/429; отсутствие Apache ложного запрета. Правила не отменяют авторизацию |
| Allowed SPA | `/`, `/app`, `/admin/login`, `/admin/account/security`, `/admin/projects/{uuid}/brief`, `/project/{uuid}/task/{id}`, `/invitation`, `/access-error`, `/task/{id}` | Shell и deep reload, затем правильная auth navigation; maintenance остаётся 503, direct `index.html` не обходит его |
| Static/ACME | Реальные JS/CSS/TTF/SVG/PNG, font-license TXT, manifest, SW, `.well-known/acme-challenge/{fixture}` | Валидные MIME/cache, no accidental 403; ACME зависит также от конфигурации Jino |
| Native 404 | Несуществующий `/assets/missing.js`, `/font-licenses/missing.txt` | 404 до PHP, не HTML со статусом 200; parent ErrorDocument не возвращает Laravel |
| Unknown backend | `/api/unknown`, `/access/x/extra`, `/sanctum/csrf-cookie` | Laravel 404, не SPA; неизвестные POST учитывают ранний CSRF 419 |
| Headers/cache | Success/redirect/401/403/404/413/419/422/423/429/500, health, stream downloads | Один непротиворечивый Cache-Control; confidential no-store; Retry-After сохранён; strict attachment CSP не потерян |
| Bootstrap limiter | Лимит/лимит+1, новый cookie на каждом запросе, TTL recovery | 429 до StartSession/DB; новый cookie не сбрасывает IP budget; после окна обычная работа |
| NAT и fairness | 10–20 пользователей/IP, 3 вкладки, Passkey options+verify+повтор, TOTP step-up, PWA reconnect | Не блокируются нормальные рабочие сценарии; несколько IP не снимают account/workspace guards; distinct actions не тратят чужой numeric bucket |
| Expensive actions | DOCX/PDF/AI limits и конкуренция с ошибочным вводом | 429/413 до render/network, права проверены, Retry-After, отсутствие дорогостоящего provider вызова сверх quota |
| Uploads | PHP/SVG/HTML masquerade, bad ZIP/DOCX, image dimensions, batch overhead, oversize | Existing safe rejection, 413/422 без 500; легитимные ZIP/download/raster preview работают |
| Auth/permissions | Real Passkey registration/login/origin/UV, TOTP replay, recovery/invitation, stale sessions, 5-min proof expiry/policy change, foreign tenant/project/attachment | Existing restrictions сохраняются; raw bearer и клиентская ссылка не заменяют step-up |
| Webhooks | Неверный secret, oversized body, valid duplicate update, 429 retry, outbound SSRF/pinning/HMAC | 403/413/429 по назначению, valid dedup не изменён, никаких внешних сообщений в тестах |
| Localization/PWA | RU/EN/TR/ZH-CN, light/dark/mobile, PDF/DOCX, attachment, SW update/push navigation | Нет raw diagnostic/stack trace в UI; WebAuthn/PDF worker/fonts/PWA не ломаются |
| Logging | Synthetic secrets в URL/query/body/header/SQL exception; scanner path с control chars | Ни одно значение не попадает в экспортируемые app logs/metrics; host canary только staging; counter labels bounded |
| Unpacked ZIP | Новая canonical build, проверка состава/hash/size, запуск из extracted tree на PHP 8.4 + Apache | Единственный разрешённый public executable, private вне root, `.htaccess` packaged=approved, assets/headers/routes работают без dev dependencies |

Затем узкие новые тесты и существующие security suites; при реализации полноценный canonical release builder обязателен. Проверку production делать отдельным согласованным smoke без brute force, synthetic attack и массовых запросов. Успешный локальный Apache run не подтверждает AllowOverride и modules Jino.

### G.4. Быстрый откат `.htaccess`

Это план будущего согласованного изменения, **не команда к выполнению сейчас**.

1. До публикации сохранить точную текущую working `.htaccess` и checksum **вне document root**. Подготовить независимый SSH/SFTP/панельный доступ; откат через приложение может стать недоступен при 500. Не хранить `.htaccess.bak` или backup ZIP в public.
2. Обновлять только утверждённый файл через загрузку временного файла **в приватную папку на той же файловой системе** и atomic rename в public. Убедиться, что rename действительно atomic; если панель этого не поддерживает, использовать подготовленный SFTP/SSH способ. Не оставлять `.htaccess` частично записанным.
3. После замены сделать по одному безопасному запросу health, SPA, static. Синтаксический 500, блокировка разрешённого пути, сломанный login/export или private cache regression — немедленный rollback, без попыток исправлять regex на живом сайте.
4. Восстановить **точный прежний файл** atomic replace, вернуть permissions, повторить те же короткие smoke и проверить checksum. Не удалять `.htaccess` целиком: это уберёт маршрутизацию и защиту. Не чистить cookies/DB sessions и не откатывать миграции ради Apache rollback.
5. Read-only проверить безопасную выдержку error log локально владельцем, не публикуя URL/секреты. Если error сохраняется с прежним checksum, проверить parent config/изменения панели и обратиться к Jino; не добавлять случайные AllowOverride/Options/handler directives.

Цель процедуры — восстановление файла за 1–2 минуты при доступном независимом канале; это операционный ориентир, не измеренный SLA Jino. `.htaccess` обычно перечитывается на запросе, restart сервера для отката не требуется, если hosting не вводит свою процедуру. Для HSTS HTTP-header rollback не стирает browser max-age: поэтому начинать с короткого срока и не включать preload. Laravel changes откатывать отдельным согласованным code release; пакет с новыми headers/limiter не смешивать с неотносящимися миграциями или сменой ключей.

### G.5. Условия завершения будущей реализации

Milestone готов к выпуску после подтверждённого Jino preflight, прохода Apache/Laravel/package матрицы, проверки log privacy и согласованных NAT/экспортных границ; registry audit должен иметь явный статус и источник. Изменения должны быть маленькими и обратимыми. Push, merge, deployment, включение внешнего WAF, изменение срока сессий/клиентских ссылок и любые действия на production остаются отдельными решениями владельца. В текущем этапе результат — этот документ и предложенный объём, реализация не начата.
