# Milestone 8.4 — проверки, безопасность и нагрузка

Локальный synthetic QA, 2026-10-29–30. База `2d909d7`. Не использовались production БД, реальные клиентские credentials, Jino или внешние inbox. Отдельная ветка `codex/milestone-8-4-ux-live-notifications`; публикация не выполнялась.

## Авторизация и UX

PasswordRecoveryController использует активное workspace membership, а не устаревшее users.role. Запросы клиента и неизвестного email не создают reset-токен и возвращают тот же ответ, что owner/developer. Только действительный email/token и активная staff-роль разрешают inspect TOTP. Hash/TTL/одноразовость и ограничение forgot/reset сохранены; inspect имеет отдельный ограниченный IP budget. Под блокировкой пользователя проверяются текущая роль и существующий второй фактор. Сброс не выдаёт authenticated session и сохраняет owner enrollment. Резервные коды расходуются транзакционно; сессии отзываются существующим механизмом. Валидация исключает одновременную передачу двух факторов.

Форма хранит token только в памяти и сразу очищает URL fragment. Успех запрещает вторую отправку, очищает секреты и запускает отменяемый при уходе таймер на 1500 мс. Ошибка оставляет пароль в текущем компоненте; в storage он не записывается. Общая шапка даёт единственный комплект переключателей. Списки интеграций и действия сохранены, а сворачивание создания ожидает закрытия отдельного одноразового secret-dialog. Переключение SSO вкладки не меняет API/права источника.

LiveNotifications только читает существующие записи и не отмечает read markers. Доступ повторно ограничивается workspace, текущим project assignment/клиентской сессией, активностью проекта и soft deletion. Свои actor IDs и совпадающие email исключаются. Личные browser/push-настройки и существующие project recipient/event rules учитываются. Тексты ограничиваются на SQL/DTO уровнях и выводятся Vue text interpolation. Приватные developer notes, email и полные комментарии не включены. Toast metadata не попадает в BroadcastChannel/localStorage; там только opaque cursor, время и ограниченные event-ID receipts. Смена identity очищает превью. Короткое перекрытие чтения предотвращает пропуск обычных запоздавших commit; квитанции исключают повтор. Поток ограничен 51 строкой на каждый существующий источник и максимум 100 DTO; переполнение задаёт свежий baseline, а UI группирует события.

## Проверки

- Milestone84Test: owner/developer/client/unknown email, TOTP/recovery/no-factor, expiry/replay, членство revoked/inactive, безопасные metadata, собственные действия, чужой workspace, developer assignment, client scope, настройки/получатели, отсутствие новых notification/read-записей и постоянное число SQL.
- live-events.test.js и live-sync.test.js: повторные/перекрывающиеся ответы, общие ID-квитанции, ограничение banner burst, переходы, смена identity, скрытая вкладка, один лидер, отсутствие превью в BroadcastChannel, backoff и запрет обхода backoff сменой видимости.
- milestone84.browser.mjs: настоящий HTTP/Vue/Laravel, одноразовые секреты до сворачивания, черновик раскрываемой формы, отдельная SSO-вкладка, task/comment/status и comment anchor, несколько вкладок, сохранение unsent draft, RU/EN/TR/ZH-CN и темы/размеры, проверка reset UI с/без TOTP, recovery, ошибки, повторное нажатие, replay, success redirect. SQL измеряется только в одноразовой тестовой копии bootstrap, без production-инструментации.
- client-sso.browser.mjs: сохранена регрессия через два HTTPS-сайта и настоящий PHP/cURL — source provenance, provisioning-first-open, credential rotation, один consumer кода, чужая persona, Magic Link fallback и отсутствие секретов в URL/logs. Настройки открываются в новой SSO вкладке.

Окончательная штатная сборка: **937 Laravel tests / 13 366 assertions — PASS**, **378 frontend tests — PASS**. Focused Laravel (reset, Live Sync, профиль): 25 tests / 285 assertions — PASS. PHP8.4.26/MariaDB11.8.9 selection: **161 tests / 1657 assertions — PASS**. Pint, brand:check, production Vite build и production Composer platform requirements — PASS. Vite сохраняет прежнее предупреждение о крупном общем chunk; сборка завершена.

SQLite и MariaDB browser: по 6 групп сценариев, 0 ошибок JavaScript и по 21 скриншоту. SSO через два HTTPS-сайта на SQLite: 12 групп, 9 скриншотов, 0 ошибок JavaScript, production frontend build. Основной 8.4 runner использует Vite dev server и настоящий Laravel HTTP; production assets отдельно проверены сборкой и извлечённым runtime. Browser evidence: `artifacts/milestone-8-4/{sqlite,mysql}/`; SSO: `artifacts/milestone-8-4/sso/`. Это выбранные комбинации четырёх языков, двух тем и viewport, а не полный Cartesian product браузеров/CMS.

Извлечённый production ZIP: валидный reset-token возвращает только TOTP-флаг; ошибочный — 422 без флага. Sync возвращает новые поля без начальных событий. Сохранены packaged HTTP/CSRF/tenant/API/invitation/provisioning/Project Request/SSO и signed WebAuthn проверки, CLI и maintenance gates. Production vendor без dev-пакетов; тесты, QA, примеры и реальные локальные настройки исключены.

Архив: `release/project-brief-v0.8.4-d6328c0.zip`, **39 258 554 bytes**, source commit `d6328c0e8e5e5b279e3da5ab3b3523ceb2ba50d8`. Независимо проверены CRC и 8 862 внутренних SHA-256; 8 863 файла с checksums.txt, 10 108 ZIP entries. SHA-256: `b27237ff42d9357631b4059fddaa8a4893d15579aae72d90867c9060fec58f6e`. Реквизиты и машинные результаты — [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json), [SHA256SUMS.txt](SHA256SUMS.txt). Последующий commit отчёта не изменяет runtime архива.

## Нагрузка

Дополнительных HTTP polling loops: **0**. Один существующий coordinator меняет интервал с 300 до 60 секунд: максимум 60 вместо 12 периодических проверок в час на видимую identity/workspace/project-группу вкладок, то есть +48/час. Скрытая вкладка не делает проверок. Web Locks и общий clock исключают дубли при поддержке браузером; без этих API работает безопасный ограниченный fallback. Backoff растёт до 300 секунд; push-сигналы не обходят его.

Feature-замер: прежняя часть пустой проверки — 9 SQL, новая — 12 SQL (**+3**); при 12 событиях — 13 SQL (**+4**, включая один пакетный settings-query). Тела: пустое около 0,6 KiB, 12 событий — около 4,6 KiB. Начальная авторизованная загрузка получает один дополнительный MAX-запрос для водяных знаков; N+1 на списке проектов нет. Создание событий не получает новых SQL/notification-row writes от toast. Число запросов не растёт на каждый event/автора/проект. Локальные timings и реальные HTTP counters записаны в `load.json` и `http-sql.json`; HTTP включает обычную database-session и MFA middleware и потому больше узкого feature-замера.

Локальная SQLite HTTP-проверка до окончательной сборки: owner idle — 15 SQL, события — 16 SQL; client с событием — 11 SQL. Эти числа и SQL milliseconds не являются прогнозом Jino CPU/latency. Реальный hosting/proxy/NAT, SMTP inbox, внешние Telegram/Web Push consumers и staging backup/restore требуют отдельного согласованного smoke по PRODUCTION.md.

## Повторение

```sh
# api/
php -d memory_limit=512M vendor/bin/phpunit
vendor/bin/pint --dirty --format agent
# repository root
npm test
npm run build
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/milestone84.browser.mjs
PROVISIONING_MYSQL_PORT=33383 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/milestone84.browser.mjs
```

MariaDB runner очищает только явно разрешённую одноразовую projectbrief_m80_qa; не направлять на рабочую базу. PHP8.4 selection выполняется через api/tests/provisioning.php84.sh после завершения browser-run на этой же QA БД. Источники монтируются read-only. Destructive DDL проверяется SQLite, а не транзакциями MariaDB. Полные процессы используют test-only 512 MiB; production limits не меняются. Production ZIP строится только штатным ./bin/build-release из чистого local commit; vendor dev/test/source/report/example credentials исключаются, CRC/manifest/SHA проверяются отдельно.
