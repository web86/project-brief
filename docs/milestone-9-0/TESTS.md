# Milestone 9.0 — проверки и производительность

Все проверки относятся к локальным одноразовым БД и копиям приложения. Production/Jino/FPM и внешние получатели не проверялись. Ни одна QA-команда не обращается к production.

## Проверки

Полный Laravel: **953 теста / 13548 assertions**, ошибок/пропусков нет (PHP 8.5.10 / SQLite); JUnit сохранён в `artifacts/milestone-9-0/laravel.xml`.

- Frontend: `npm test` — 381 тест. Дополнены проверки общего session destination, platform-only navigation без workspace bootstrap и гостевого перехода на существующий login. RU/EN/TR/ZH-CN сохраняют parity.
- Focused Laravel: 77 тестов / 1134 assertions, включая платформенную изоляцию/MFA/step-up/аудит, legacy upgrade, security и signed passkeys. Новые platform/API tests: 16 сценариев / 179 assertions.
- PHP 8.4.26 / MariaDB 11.8.9: 131 тест / 1390 assertions. `tests/platform.php84.sh` в одноразовом PHP-контейнере, репозиторий read-only, отдельная БД `projectbrief_m90_qa`. Охвачены platform, metrics, sessions, step-up, integration reads, SSO, requests, webhooks и фильтры проектов. Один существующий integration migration down/reapply test с transactional DDL исключён из этой MariaDB-выборки; он входит в полный native SQLite прогон.
- Browser: Chromium, живой Laravel HTTP и Vue/Vite, реальная SQLite/session/CSRF/MFA. Проверяются guest/owner denial, отсутствие скрытого членства/доступа к контенту, invalid CSRF => 419, поиск, пагинация, детали, нормализованные маршруты и аудит. Восемь конфигураций: RU/EN/TR/ZH-CN × desktop Light/mobile Dark; отдельно expired-proof dialog. Это не полная декартова матрица всех viewport/theme/browser. Артефакты: `artifacts/milestone-9-0/browser/`.
- Полный Laravel, сборка и ZIP runtime: канонический `./bin/build-release` прошёл; результаты в `RELEASE-ARTIFACT.json`, runtime log — `artifacts/milestone-9-0/release-runtime.txt`. Из реального ZIP проверены также platform-only MFA/session, owner denial, диагностика, отсутствие project access и интерактивный отзыв полномочия с инвалидированием сессии. Для существующего image/export suite локальному PHP необходим memory_limit=512M; исходная системная настройка 128M прерывала этот suite, приложение из-за этого не менялось.

Backend tests проверяют также интерактивное назначение/отзыв и отказ неинтерактивной команды, невозможность mass assignment, default-false при реальном legacy upgrade, session revocation, password recovery и signed UV passkey для platform-only account, запрет выключения TOTP, закрытие панели при отказе аудита, точность weighted averages/max/hour boundaries, общую anonymous category, отсутствие query/body/headers/real IDs ресурсов, fail-open optional metrics и отсутствие повторной записи при двойном terminate. Webhook checks используют existing history, старый event с новыми retry attempts и другой workspace.

## MariaDB: конкурентность и время

В `tests/platform.concurrent.mjs` восемь синхронно стартующих PHP 8.4 процессов записывают по 200 запросов в одну часовую строку через настоящий production `ApiUsage` и отдельное bounded connection. Проверяются все counters, суммарная длительность и максимум. Затем выполняются cold/warm UPSERT benchmark, on/off HTTP kernel measurement и ожидание намеренно удерживаемого row lock. JSON с измерениями: `artifacts/milestone-9-0/performance.json`.

Ожидаемые точные результаты: 1600 requests, 400 2xx, 800 4xx, 400 5xx, 400 429 (подмножество 4xx), сумма 160800000 μs, максимум 200000 μs. Один SQL UPSERT на измерение, без предварительного SELECT. В отдельной серии 201 измерение должно дать 201 SQL statement. Locked row: wait около 1 s, ответ API остаётся `200 {"ok":true}`. В реальном FPM terminable запись выполняется после ответа, но занимает worker до завершения/таймаута.

HTTP benchmark — прогретый Laravel kernel, GET `/api/v1/projects` с пустым списком, 30 запросов в каждой из четырёх чередующихся on/off серий. Отбрасываются первые пять значений каждой совокупности on/off, остаётся по 55. Измеряются время до готового ответа и полная занятость worker с terminate; это не сеть/браузер/production FPM. Локальный startup/соединение измеряется отдельно. Результаты чувствительны к другим Docker/browser/test процессам на компьютере. Повтор с конкурирующими тестами сохранён отдельно, если выполнен.

Дополнительное соединение и запись имеют ненулевую стоимость; обещания «нулевой нагрузки» нет. Оценка Jino обязательна перед включением при большом трафике: max_connections/worker budget, p95 latency и termination/lock waits. Выключатель `PLATFORM_API_METRICS_ENABLED=false` сохраняет API и ранее собранную историю. Диагностические потери допустимы; финансовые/квотные решения по этой таблице запрещены.

Изолированный прогон на этом компьютере: cold UPSERT 7.56 ms; warm UPSERT p50 1.79 / p95 2.41 ms. Восемь writers: p50 2.95 / p95 5.14 ms, 1600/1600 учтено. HTTP ready p50/p95 без метрик 1.70/2.24 ms, с метриками 1.88/2.73 ms; полная занятость worker p50/p95 1.83/2.40 → 3.93/5.56 ms. Намеренная блокировка: 1012 ms, HTTP 200 сохранён. Измерение 201 SQL statement означает явные Laravel query events; connector/session initialization на новом PDO-соединении дополнительно учитывается временем cold measurement, но не этим query counter.

Нагруженный параллельными suites прогон сохранён в `performance-loaded.json`: counts также точны, p95 writers выше. Его нельзя сравнивать с изолированным baseline как изменение кода.

## Воспроизведение

На macOS выполнить `npm test`, `cd api && vendor/bin/pint --dirty --format agent`, `php artisan test --compact` с PHP memory_limit 512M, затем `PLAYWRIGHT_MODULE=/absolute/path/playwright/index.mjs node tests/platform.browser.mjs` из корня. Browser harness самостоятельно создаёт и удаляет `brief-platform-*`, не использует `.env` проекта для БД.

Для PHP 8.4/MariaDB создать отдельную Docker-сеть и MariaDB с БД **только** `projectbrief_m90_qa`, host/container name `project-brief-m90-db`. В PHP 8.4 контейнере подключить репозиторий как `/workspace:ro`, задать testing DB env и выполнить `sh /workspace/tests/platform.php84.sh`. Для concurrent test подготовить отдельную копию через `platform.php84.sh prepare`, оставить PHP-контейнер запущенным и вызвать с хоста `DB_DATABASE=projectbrief_m90_qa M90_PHP_CONTAINER=CONTAINER_NAME node tests/platform.concurrent.mjs`. Не запускать benchmark одновременно с migration/test процессами на этой же БД. Одноразовые значения QA credentials в harness не являются production секретами.

Каноническая сборка требует чистый локальный commit и выполняет frontend/Laravel/build, production vendor, known-local-secrets/forbidden-path scan, ZIP CRC, все внутренние SHA-256 и fresh extracted runtime + signed WebAuthn checks. Source tests/docs/QA artifacts исключены из runtime ZIP. Финальный размер и hash — в RELEASE-ARTIFACT.json/SHA256SUMS.txt.

Готовый production runtime из канонического ZIP дополнительно проверен без dev-пакетов на PHP 8.4.26/MariaDB 11.8.9: lint PHP исходников, полный fresh schema, шесть platform routes, атомарные usage updates и platform audit. Данные — `artifacts/milestone-9-0/packaged-php84.json`. Проверка выполнялась только на копии и одноразовой БД.
