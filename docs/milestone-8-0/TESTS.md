# Milestone 8.0 — проверка и границы доказательств

Локальные проверки выполняются на отдельной базе/временном storage. Рабочая база, credentials и Jino не используются. Изменения создаются от `5918f51` (0.7.8). Результаты ниже относятся к финальному исходному коду; проверка production ZIP фиксируется отдельно после канонической сборки.

## Покрытие

- Laravel feature: контакт + проект + membership; повторное использование контакта и legacy email внутри workspace; одинаковый email в разных workspace; same-key replay; конфликт ключа/external ID; независимые token namespace; ошибочный/expired/revoked token; dedicated scope и disabled provisioning; повторная проверка отзыва внутри write transaction; чужие поля/workspace/templateId, размеры/ключ; все четыре языка; SMTP после operation transaction; отказ SMTP без потери проекта/секретов; атомарный rollback при сбое outbox; tombstone после удаления; owner-only toggle/history; пустой schema rollback и сохранение legacy данных; запрет разрушительного rollback после использования; явная повторная отправка через прежний UI; восстановление `pending` без автоматической повторной отправки `sending`.
- Login feature: постоянная ссылка требует проверки email, повторный Magic Link, unknown project/email с единым ответом, сохранение прежней сессии при запросе письма, отказ сессии другого проекта, немедленная блокировка revoked link/inactive membership, expired link и отказ почты, per-mailbox/project throttling. Публичный login не создаёт memberships.
- Frontend: существующие owner navigation/sidebar контракты, новый guest/foreign-client/pending-MFA routing и SSR формы на RU/EN/TR/ZH-CN; остальной Node-набор сохраняется.
- Реальный browser/HTTP: upgrade с существующими project/client через схему без новой миграции, затем новая миграция; локальный SMTP sink проверяет видимость `sending` и созданных записей **из отдельного процесса до SMTP acceptance**; восемь same-key и шесть same-external-ID запросов через два независимых PHP HTTP-процесса; нет дублей; потеря ответа/новый key; конфликт; другой workspace; ошибка SMTP; постоянная ссылка → форма → письмо → проект; повторный Magic Link; чужой проект и отзыв membership; owner switch/history и прежний webhook UI; desktop/mobile, четыре языка, две темы; ноль page errors.
- В тех же HTTP-прогонах сканируются конкретные синтетические API/login секреты в ответах, history, webhook payload и локальном application log. Outbox сохраняет safe IDs/URL и текущий status; сами SMTP-письма ожидаемо содержат login link и остаются только в памяти теста.

## Воспроизведение

```sh
# Из api/
php artisan test --compact tests/Feature/ProjectProvisioningTest.php tests/Feature/ClientProjectLoginTest.php tests/Feature/WorkspaceMigrationTest.php
vendor/bin/pint --dirty --format agent

# Из корня
node --test tests/integrations.test.js
npm test
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/provisioning.browser.mjs
```

Browser script копирует приложение в `brief-provisioning-*`, использует временные SQLite/cache/sessions/storage, локальный SMTP-приёмник и существующий bundled Playwright. Новые application dependencies не устанавливаются. Локальные порты требуют разрешения среды выполнения. После сценария процессы и временная копия удаляются; JSON и PNG сохраняются в `artifacts/milestone-8-0/sqlite/`.

MariaDB-прогон: запустить **одноразовую** MariaDB 11.8.9 с отдельной `projectbrief_m80_qa`, пользователем `briefqa`, синтетическим паролем `Disposable-m80-only` и loopback port. Передать этот порт как `PROVISIONING_MYSQL_PORT`; результаты попадут в `artifacts/milestone-8-0/mysql/`. Скрипт намеренно очищает эту тестовую базу перед миграцией; не направлять его в рабочую БД. Guard fixture допускает только указанное имя MySQL QA-базы либо временный путь SQLite.

Дополнительный `api/tests/provisioning.php84.sh` запускается внутри существующего disposable PHP 8.4 Docker image с read-only `/workspace` и этой же QA MariaDB. Он проверяет новую функциональность вместе с прежними API, приглашениями и webhooks; destructive DDL rollback исключён на MariaDB из-за implicit commit и отдельно покрыт SQLite. Сначала завершить browser-прогон: feature suite использует `migrate:fresh` на QA-базе.

Полный Laravel-набор требует test-only `memory_limit=512M`: общий процесс с существующим DOCX/image stress-тестом превысил локальный PHP default 128M. Параметры приложения/production не изменялись. Каноническая сборка получает этот лимит через временный `PHP_INI_SCAN_DIR`, наследуемый PHP-процессами, и выполняет полный набор повторно.

## Production граница

Локальная MariaDB/PHP 8.4-проверка не является Jino preflight. На Jino не запускались миграции, команды, SMTP или webhook. Реальная inbox-доставка, внешний consumer/его подпись в своей системе, фактические proxy/NAT/Apache настройки, staging backup/restore и rollout остаются deployment smoke из PRODUCTION.md. Никаких новых клиентских Passkey/MFA/SSO методов или workspace assurance policies этот milestone не вводит.

PHP-пример проверяется синтаксически; готовые WordPress/MODX плагины и реальные CMS hooks не реализуются и не заявляются протестированными.

## Результаты исходного кода (2026-10-29)

| Проверка                                                 | Результат                                                  |
| -------------------------------------------------------- | ---------------------------------------------------------- |
| Полный Laravel / PHP 8.5 / SQLite                        | **860 tests, 12 602 assertions — PASS**                    |
| Полный frontend Node                                     | **351 tests — PASS**                                       |
| PHP 8.4.26 / MariaDB 11.8.9, scoped regression           | **78 tests, 848 assertions — PASS**                        |
| SQLite HTTP + Chromium + local SMTP                      | **7 scenario groups, 9 screenshots, 0 page errors — PASS** |
| MariaDB HTTP + Chromium + local SMTP                     | **7 scenario groups, 9 screenshots, 0 page errors — PASS** |
| Eight same-key / six same-external-ID concurrent calls   | **One project per operation on both engines — PASS**       |
| npm audit, полный / omit-dev после patch updates         | **0 / 0 vulnerabilities**                                  |
| Scoped Pint / Prettier / diff check / PHP example syntax | **PASS**                                                   |

Скриншоты формы входа RU/mobile/dark и журнала desktop/mobile просмотрены визуально. Матрица показывает отдельные сочетания четырёх языков/двух тем и desktop/mobile, а не полное декартово произведение. Все адреса, токены, письма и пользователи синтетические. JSON browser-сценариев сохранены рядом с PNG; команды и миграции не обращались к Jino.

## Финальный production ZIP

Каноническая повторная сборка после разрешённых patch-обновлений: **PASS**. Полный frontend/backend набор повторно прошёл; extracted ZIP проверен на production vendor, signed WebAuthn, новый provisioning flow (contact/project/membership, failed SMTP, same-key replay и recovery по external ID, язык/описание, защищённый canonical URL), прежние API, приглашения, comments/files, trash/restore, CLI/CSRF/SPA и maintenance.

Все CRC и внутренние checksums сверены после сборки; QA-материалы, исходники frontend, node_modules, development vendor и CMS-пример в ZIP отсутствуют. Инструкции внутри ZIP содержат финальные patch-версии. Точные source commit, SHA-256, размер и результаты сохранены в `RELEASE-ARTIFACT.json` и `SHA256SUMS.txt`.

Первый промежуточный ZIP до patch-обновлений заменён финальным. После сборки изменялись только внешние отчёты; runtime исходники соответствуют указанному source commit. Push, merge и deployment не выполнялись.

## Регрессии client layout и пустого проекта

Исправлена причина верхнего отступа: общие `.workspace` / `.topbar-inner` использовали `margin: auto`, что создавало вертикальные auto margins при размещении workspace в desktop Grid. Используется `margin: 0 auto`; отрицательных отступов нет. На 1440 px зафиксировано изменение промежутка между topbar и workspace с 273.55 px до 0, заголовок остаётся на обычных 26 px от панели. На 390 px промежуток равен 0, заголовок — 20 px; горизонтального переполнения нет.

Пустой проект показывает «+ Создать задачу» и открывает существующий TaskForm. Первую секцию клиент создаёт в прежнем LocationInput вместе с задачей; вторая задача выбирает ту же секцию, существующие проекты принимают дополнительные задачи. Новых экранов, permissions или backend-ограничений нет; production PHP не менялся. Проверены owner и назначенный developer с прежними правами. RU/EN/TR/ZH-CN сохранены.

Добавлены 14 frontend регрессий и 5 Laravel случаев; сначала 12 frontend случаев воспроизвели отсутствие CTA. Финальный отдельный HTTP/Chromium прогон: **23 проверки**, 0 page errors, 17 итоговых скриншотов. Существующий browser-набор на compiled frontend: **19 проверок**, Chromium/Firefox, desktop/mobile/tablet, 0 page errors. Сырые метрики и PNG: `artifacts/client-project-regressions/{before,after,export}/`. Синтетические PDF/DOCX загрузки удалены после проверок; JSON/PNG сохранены.

Команды: `node --test tests/empty-project.test.js`; `php artisan test --compact tests/Feature/EmptyProjectTest.php tests/Feature/ProjectPermissionsTest.php`; `PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/client-project-regressions.browser.mjs`; `EXPORT_QA_DIRECTORY=/path/to/evidence PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs node tests/export.browser.mjs`; полный `npm test`, `npm run build`, Laravel suite, scoped Pint/Prettier и `git diff --check`. Прежние PHP 8.4/MariaDB доказательства относятся к неизменённому production PHP; новые UI-проверки выполнялись локально на PHP 8.5/SQLite.
