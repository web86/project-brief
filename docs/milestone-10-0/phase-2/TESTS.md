# Phase 2 — verification

Дата: 02.11.2026. Проверки локальные: disposable MariaDB 11.8.9/tmpfs,
изолированные SQLite fixtures, PHP 8.4.26 и host PHP 8.5.10. Production не
открывалась. API/AI/provider запросов: **0**; секретные provider env-файлы не
читались. Исходники в PHP 8.4 container mounted read-only, приложение/storage
скопированы в /tmp. Release ZIP не создавался.

## Итоговые результаты

| Проверка                                            | Результат                                                                                           |
| --------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Full backend, host PHP 8.5.10 / SQLite              | 1102 tests: 1099 passed, 16096 assertions, 3 явных MariaDB-only skip                                |
| Full backend, PHP 8.4.26 + GD/WebP / MariaDB 11.8.9 | 1102 passed, 16172 assertions; 0 skipped/risky/failures                                             |
| Frontend, полный `npm test`                         | 441 passed, 0 skipped                                                                               |
| Production frontend build                           | Passed; прежнее предупреждение chunks >500 kB                                                       |
| Реальный browser workflow                           | 6 RU/EN × light/dark/system scenarios, desktop 1440/mobile 390, 0 JS errors, no horizontal overflow |
| Target MariaDB foundation inventory                 | 27 / 27 таблиц подтверждены через information_schema после full run                                 |
| Pint, PHP syntax, route inventory, diff check       | Passed                                                                                              |

Финальный full MariaDB/backend запуск включает последние изменения backend,
включая selected published source, archive dependencies, canonical policy maps,
operator transfer и обе независимые concurrency suites. Frontend full suite/build
и browser workflow повторены после финальных UI/i18n правок.

## Backend / безопасность

PlatformPlansTest проверяет primary-only API/direct shell, сохранение diagnostics
других platform_admin, strong MFA/fresh proof, verified email, CSRF, session
revocation и повторную authorization до replay. Bootstrap/transfer operator
интерактивен; неверные targets/confirmation/revision не назначают primary, успешная
передача аудируется и отзывает sessions обоих аккаунтов.

Проверены create/read/update drafts, более трёх планов, immutable published,
ready preview/hash/revision/expiry/authority checks, keep_existing only, UUID
parent binding, selected published source, archive/unarchive/history/filtering,
policy target classes и immutable publication, current future pointer без
subscription/Trial activation. Free paid-only configuration отклоняется.

Rule create/edit и copied disabled/manual rules проходят transactional API;
repeat одного key не дублирует результат. Copy snapshot содержит fingerprint
связанных source/target rule revisions; unsafe active block_growth запрещён.
При mandatory plan audit failure вся HTTP mutation откатывается, safe error не
возвращает exception/SQL/payload. Middleware учитывает exception→response handling
внутри Laravel routing pipeline и откатывает HTTP errors.

Обе MariaDB concurrency suites запускают два **независимых PHP процесса** с общей
стартовой barrier. Foundation suite проверяет domain operations; новая
PlatformPlansConcurrencyTest проходит настоящий Laravel HTTP kernel/middleware
через postJson/putJson, существующие session/MFA proofs и реальные DB transactions:

- разные publication keys, одна revision: один 200, один 409;
- тот же key/hash после одновременного отправления/неизвестного client outcome:
  оба 200, один publication outcome/version;
- edit против publish на одной revision: один 200, один 409;
- published revision/history не повреждаются, subscriptions/changes не создаются.

Этот PHP-process HTTP-kernel тест не выдаётся за live production/TCP stress test.
Настоящие browser HTTP calls выполняются отдельным изолированным сервером.

Phase 1 migration suite повторно покрывает все additive tables, populated v0.9.10
upgrade, hashes прежних колонок всех legacy таблиц, shared credentials/private
collaboration, independent webhooks/SSO source, interrupted MariaDB DDL repair,
FK/index inventory и EXPLAIN. Существующие Agent API, Webhooks, Client SSO,
Projects, account security и permissions входят в полный regression suite.

## Frontend / браузер

Unit/SSR/router tests: динамический registry, explicit unlimited, unavailable/
derived без editable inputs, reactive-safe serialization, timeout key reuse,
If-Match, existing step-up path selection, primary capability check без чтения
workspace content. Общая i18n проверка проходит для всех четырёх языков.

Реальный browser scenario дополнительно:

1. Guest denied; другой MFA platform admin получает diagnostics, но не API,
   direct server shell и Vue navigation управления тарифами.
2. Create четвёртого плана с RU/EN/TR/ZH-CN names → save → preview → publication;
   published controls disabled, новый draft создаётся из published version.
3. Unsaved metadata не теряются при внутреннем new-draft navigation; unsaved text
   блокирует уход. При concurrent server revision введённый текст остаётся в форме.
4. Global policy создаётся и публикуется через UI с published paid/free targets.
5. Rule создаётся через UI, затем preview/copy на новую published version;
   копии disabled, обновлённый список отображается сразу.
6. RU/EN, light/dark/system, desktop/mobile, direct routes и no-overflow checks.

Rendered desktop/mobile screenshots просмотрены локально. Они остаются в
ignored artifacts и не входят в commit. Rate-limit cache очищается **только
между независимыми scenarios изолированной QA-копии**, как в существующем
platform browser runner. Рабочий throttle не отключён и не изменён. Ожидаемый
409 в browser log — deliberate concurrent edit, не необработанная ошибка.

## Воспроизведение

```sh
PHP_INI_SCAN_DIR=:$PWD/api/dev-php php -d memory_limit=512M api/vendor/bin/phpunit -c api/phpunit.xml
npm test
npm run build
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/platform-plans.browser.mjs
```

MariaDB: использовать exclusive disposable server с двумя БД:
`projectbrief_agent_ux_full_qa`, `projectbrief_m10_upgrade_qa`. Concurrency suite
делает migrate:fresh первой БД; upgrade suite очищает вторую. **Не запускать
несколько DB suites одновременно** на этих БД. Credentials только synthetic,
передаются через environment; файлы env из repo/production не копируются.

Host runner `tests/agent-ux.mariadb.sh`; PHP 8.4 runner
`tests/plans-foundation.php84.sh`. Последний принимает обычные phpunit arguments;
без filter запускает весь suite. APP_ENV=testing, DB_CONNECTION=mysql,
DB_HOST=127.0.0.1, указанная первая DB обязательны. PHP container использует общую
network namespace с disposable MariaDB, repository `/workspace:ro`.

Использованный дополнительный QA image (не production image):

```dockerfile
FROM project-brief-release-php84:latest
RUN apt-get update && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && docker-php-ext-install -j4 gd && rm -rf /var/lib/apt/lists/*
```

Локальная base image содержит PHP 8.4.26, PDO MySQL/SQLite и остальные Laravel
extensions. Для воспроизведения без неё подготовить эквивалентное PHP 8.4 CLI
окружение с GD **и WebP**, zip/mbstring/XML и существующим composer.lock/vendor.
Image tag QA: `projectbrief-phase2-php84-gd:local`; зависимости проекта не менялись.

## Найденное и исправленное

- Phase 1: archive dependencies, canonical policy JSON comparison, explicit
  published source/version-parent binding для newDraft.
- UI: explicit submit type существующего LoadingButton, refresh catalog/history
  после publish/copy, поддержка Vue reactive values, защита unsaved metadata,
  checkbox sizing против общих input styles и собственный responsive layout.
- i18n: feature names преобразованы в nested message tree, чтобы все ключи
  корректно разрешались общим Vue i18n и regression validator.
- QA environment: первый full PHP 8.4/GD run имел один avatar failure — GD без
  WebP. Расширен только QA image; существующий avatar/runtime код не изменялся.

Не заявляются готовыми live deployment/production inventory, workspace impact
providers, capacity/storage IO/epoch races и коммерческие adapters следующего
этапа. Список границ — [NEXT.md](NEXT.md).
