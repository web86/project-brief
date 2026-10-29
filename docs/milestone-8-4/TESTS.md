# Milestone 8.4 — проверки, безопасность и нагрузка

Локальный synthetic QA, 2026-10-29–30. База `2d909d7`. Не использовались production БД, реальные клиентские credentials, Jino или внешние inbox. Отдельная ветка `codex/milestone-8-4-ux-live-notifications`; публикация не выполнялась.

## Авторизация и UX

PasswordRecoveryController использует активное workspace membership, а не устаревшее users.role. Запросы клиента и неизвестного email не создают reset-токен и возвращают тот же ответ, что owner/developer. Только действительный email/token и активная staff-роль разрешают inspect TOTP. Hash/TTL/одноразовость и ограничение forgot/reset сохранены; inspect имеет отдельный ограниченный IP budget. Под блокировкой пользователя проверяются текущая роль и существующий второй фактор. Сброс не выдаёт authenticated session и сохраняет owner enrollment. Резервные коды расходуются транзакционно; сессии отзываются существующим механизмом. Валидация исключает одновременную передачу двух факторов.

Форма хранит token только в памяти и сразу очищает URL fragment. Успех запрещает вторую отправку, очищает секреты и запускает отменяемый при уходе timer1500ms. Ошибка оставляет пароль в текущем компоненте; в storage он не записывается. Общая шапка даёт единственный комплект переключателей. Списки интеграций и действия сохранены, а сворачивание создания ожидает закрытия отдельного одноразового secret-dialog. Переключение SSO вкладки не меняет API/права источника.

LiveNotifications только читает существующие записи и не отмечает read markers. Доступ повторно ограничивается workspace, текущим project assignment/клиентской сессией, активностью проекта и soft deletion. Свои actor IDs и совпадающие email исключаются. Личные browser/push-настройки и существующие project recipient/event rules учитываются. Тексты ограничиваются на SQL/DTO уровнях и выводятся Vue text interpolation. Приватные developer notes, email и полные комментарии не включены. Toast metadata не попадает в BroadcastChannel/localStorage; там только opaque cursor, время и ограниченные event-ID receipts. Смена identity очищает превью. Короткое перекрытие чтения предотвращает пропуск обычных запоздавших commit; квитанции исключают повтор. Поток ограничен 51 строкой на каждый существующий источник и максимум100 DTO; переполнение задаёт свежий baseline, а UI группирует события.

## Проверки

- Milestone84Test: owner/developer/client/unknown email, TOTP/recovery/no-factor, expiry/replay, членство revoked/inactive, безопасные metadata, собственные действия, чужой workspace, developer assignment, client scope, настройки/получатели, отсутствие новых notification/read-записей и постоянное число SQL.
- live-events.test.js и live-sync.test.js: повторные/перекрывающиеся ответы, общие ID-квитанции, ограничение banner burst, переходы, смена identity, скрытая вкладка, один лидер, отсутствие превью в BroadcastChannel, backoff и запрет обхода backoff сменой видимости.
- milestone84.browser.mjs: настоящий HTTP/Vue/Laravel, одноразовые секреты до сворачивания, черновик раскрываемой формы, отдельная SSO-вкладка, task/comment/status и comment anchor, несколько вкладок, сохранение unsent draft, RU/EN/TR/ZH-CN и темы/размеры, проверка reset UI с/без TOTP, recovery, ошибки, повторное нажатие, replay, success redirect. SQL измеряется только в одноразовой тестовой копии bootstrap, без production-инструментации.
- client-sso.browser.mjs: сохранена регрессия через два HTTPS-сайта и настоящий PHP/cURL — source provenance, provisioning-first-open, credential rotation, один consumer кода, чужая persona, Magic Link fallback и отсутствие секретов в URL/logs. Настройки открываются в новой SSO вкладке.

Предварительно: 378 frontend tests — PASS; focused Laravel (reset, Live Sync, профиль) 25 tests /285 assertions — PASS; PHP8.4.26/MariaDB11.8.9 selection 161 tests /1657 assertions — PASS. SQLite и MariaDB browser: по6scenario groups,0JS errors; SSO через два HTTPS-сайта на SQLite:12groups,0JS errors. Полные canonical counts и архивный runtime фиксируются после окончательной сборки. Browser evidence: `artifacts/milestone-8-4/{sqlite,mysql}/`; SSO: `artifacts/milestone-8-4/sso/`. Это выбранные комбинации четырёх языков, двух тем и viewport, а не полный Cartesian product браузеров/CMS.

## Нагрузка

Дополнительных HTTP polling loops: **0**. Один существующий coordinator меняет интервал с300 до60секунд: максимум60 вместо12 периодических проверок в час на видимую identity/workspace/project-группу вкладок, то есть +48/час. Скрытая вкладка не делает проверок. Web Locks и общий clock исключают дубли при поддержке браузером; без этих API работает безопасный ограниченный fallback. Backoff растёт до300секунд; push-сигналы не обходят его.

Feature-замер: прежняя часть пустой проверки9SQL, новая12SQL (**+3**); при12событиях13SQL (**+4**, включая один пакетный settings-query). Тела: пустое около0,6KiB,12событий около4,6KiB. Начальная авторизованная загрузка получает один дополнительный MAX-запрос для водяных знаков; N+1 на списке проектов нет. Создание событий не получает новых SQL/notification-row writes от toast. Число запросов не растёт на каждый event/автора/проект. Локальные timings и реальные HTTP counters записаны в `load.json` и `http-sql.json`; HTTP включает обычную database-session и MFA middleware и потому больше узкого feature-замера.

Локальная SQLite HTTP-проверка до окончательной сборки: owner idle15SQL, события16SQL; client с событием11SQL. Эти числа и SQL milliseconds не являются прогнозом Jino CPU/latency. Реальный hosting/proxy/NAT, SMTP inbox, внешние Telegram/Web Push consumers и staging backup/restore требуют отдельного согласованного smoke по PRODUCTION.md.

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

MariaDB runner очищает только явно разрешённую одноразовую projectbrief_m80_qa; не направлять на рабочую базу. PHP8.4 selection выполняется через api/tests/provisioning.php84.sh после завершения browser-run на этой же QA БД. Источники монтируются read-only. Destructive DDL проверяется SQLite, а не транзакциями MariaDB. Полные процессы используют test-only512MiB; production limits не меняются. Production ZIP строится только штатным ./bin/build-release из чистого local commit; vendor dev/test/source/report/example credentials исключаются, CRC/manifest/SHA проверяются отдельно.
