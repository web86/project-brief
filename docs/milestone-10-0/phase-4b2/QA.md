# Phase 4B.2 — результаты QA

## Исторические результаты

### Первые проверки локального этапа

- PHP 8.5.10 / SQLite. `PlansPreparationTest` и `OnboardingUxTest` — 18 тестов, 145 утверждений. Проверены owner-only preview, отказ разработчику и постороннему оператору, метаданные Compatibility без срока и отсутствие имён в платформенном ответе.
- Фронтенд — 453 теста и production Vite build. Сборка выдала предупреждение о размере основного chunk.
- Chromium на локальном HTTPS. Onboarding — 20 сценариев, 108 снимков. Проверены четыре языка, три темы, мобильная и настольная ширина, standalone security для onboarding, прямое открытие и reload рабочего раздела безопасности для Compatibility-владельца.
- Chromium. Platform Plans — 6 сценариев RU/EN в light/dark/system, включая frozen preview владельца, HTTP 200 при повторной проверке, новый URL и старую ссылку. Для проверки двух маршрутов разделены окна лимитера тестовой среды.
- Проверка выполнения реальных переходов, PHP 8.4/MariaDB race tests, Jino и проверка извлечённого Production ZIP остаются открытыми.

### Backend checkpoint

- PHP 8.4.26 / MariaDB 11.8.9. Целевой набор lifecycle, migration, race, preparation и onboarding прошёл 31 тест с 485 утверждениями. Независимые процессы проверили один expiry и четыре epochs при конкурирующих вызовах catch-up и изменении проекта/клиента.
- Первый полный MariaDB-прогон выполнил 1184 теста и обнаружил одну ошибку в исторической фикстуре v0.9.10. Фикстура теперь отделяет legacy bootstrap от runtime после миграции. Исправленный сценарий прошёл в целевом MariaDB-наборе. Полный прогон после всех backend-изменений ещё требуется.
- Финальный целевой SQLite-сценарий lifecycle прошёл 2 теста и 102 утверждения. Verified owner прошёл Trial, Basic, manual paid, выборочное восстановление и своевременное продление. Проверены равенство UTC boundary, прежний API idempotency key, независимый webhook retry, 100 проектов, сохранение задачи и статусов.
- Регрессия интерфейса этого backend-прохода — 453 фронтенд-теста и 20 браузерных сценариев onboarding. Отдельный браузерный сценарий новых операций подтверждения ещё требуется.

### Restore и rearm

- Backend-сценарий на SQLite прошёл 2 теста и 128 утверждений. После restore и отдельного rearm проверены два выбранных проекта в External API, отказ для held-проекта, отказ по старому API replay, старому shared Agent run ID и неистёкшему SSO handoff, успешная выдача нового SSO handoff, отказ ручного Webhook retry и отмена stale работы в ограниченном batch.
- Дополнительный целевой набор SQLite прошёл 115 тестов и 1878 утверждений с одним MariaDB-only пропуском.
- Фронтенд прошёл 453 теста. Production Vite build прошёл на `2677634` с предупреждением о размере основного chunk.
- Source browser E2E на `2677634` прошёл семь сценариев без page errors. Lifecycle fixture создаёт verified Trial только в отдельной тестовой БД. Браузер проходит expiry, Basic selection, approval primary PlanAuthority, owner restore и отдельный rearm. Это проверка исходников, не извлечённого Production ZIP.
- Полный PHP 8.4.26/MariaDB 11.8.9 на runtime `2677634` выполнил 1184 теста, 17119 утверждений, с одним failure и одной error в двух вариантах `WorkspaceMigrationTest`. Историческая фикстура загружала современный runtime до создания таблиц планов. Она теперь применяет все последовательные миграции, проверяет значения новых nullable-полей и по-прежнему сравнивает исходные данные. На SQLite исправленная фикстура прошла 4 теста и 183 утверждения. Чистый повтор полного набора остаётся условием выпуска.
- Повторный полный PHP 8.4.26 / MariaDB 11.8.9 на `9a917e3` прошёл 1184 теста и 17278 утверждений. Отдельный набор с нативной legacy-миграцией MariaDB, lifecycle и race tests прошёл 7 тестов и 356 утверждений.

- Capacity на runtime `7cbb3d1`. Целевой PHP 8.4.26/MariaDB 11.8.9 — 18 тестов и 256 утверждений. Два независимых процесса конкурируют за одно место Basic, один создаёт проект, другой получает отказ. На SQLite прошли 46 тестов и 760 утверждений с одним MariaDB-only пропуском. Повторный полный regression, расширенный Browser E2E и extracted ZIP остаются условиями выпуска.

## Runtime `02982ad`

- Целевой PHP/SQLite набор прошёл 95 тестов с 978 утверждениями. Он включает проверку operator canary, учёт первого клиента при provisioning и отказ присоединить существующего разработчика к held-проекту.
- Расширенный Chromium E2E на исходниках прошёл семь сценариев без page errors. После выбора Basic созданы второй и третий клиенты. Четвёртый клиент и второй проект получили HTTP 403. Затем прошли manual paid, restore и отдельный rearm.

## Runtime `3a94b36`

- Целевой тест canary после закрепления точного Basic контракта — один тест, 13 утверждений. PHP lint, Pint и `git diff --check` прошли.
- PHP 8.4.26 / MariaDB 11.8.9. Отдельный набор lifecycle, concurrency и native upgrade на `ffcd79f` с тем же runtime прошёл 12 тестов и 480 утверждений.
- Chromium на исходниках `ffcd79f` — семь сценариев без page errors. Фронтенд — 453 теста и production Vite build с предупреждением о размере основного chunk.

## Текущий runtime `57653eb`

- Целевой тест canary после проверки owner-only Basic seats — один тест, 13 утверждений. Pint прошёл.
- Полный PHP 8.4.26/MariaDB 11.8.9 на `57653eb` — 1185 тестов и 17317 утверждений, включая concurrency и native MariaDB legacy upgrade. `Innodb_deadlocks = 0` после прогона. Код приложения, routes, config и migrations совпадают с текущим checkpoint.
- Chromium на исходниках `9e458a0` с тем же runtime прошёл семь сценариев без page errors, включая Basic capacity, manual paid, restore и rearm.

## Изолированная репетиция до канонической сборки

- Временный public/private стенд со свежим кодом, Vite assets и production vendor прошёл Chromium E2E с копией SQLite. После остановки origin и восстановления БД совпали хэши одного suspension cycle и четырёх epochs. После explicit rearm старые External API replay и shared Agent run получили HTTP 409, неистёкший SSO handoff перенаправлен на страницу отказа, ручной Webhook retry получил HTTP 409. Независимый worker отменил старый delivery без внешнего вызова.
- Повтор на MariaDB 11.8.9 прошёл семь браузерных сценариев без page errors. После остановки origin дамп импортирован в отдельную БД. SHA-256 строк cycles и epochs совпали. Разрешённый Basic проект остался доступен, создание второго проекта получило HTTP 403. После paid/restore/rearm подтверждены отказы старого API replay, Agent run, SSO handoff, ручного Webhook retry и отмена независимой delivery без отправки.
- Оба прогона выполнены на временном стенде до канонической сборки. Проверки самого ZIP ведутся отдельно.

## Финальный production ZIP

- `dev2done-v0.9.17-99cf55b.zip`, 39 833 976 байт, manifest commit `99cf55b3afdb0dd909b8f53e9af33f45784d1ae2`. SHA-256 `644321bb6219403c6d7885827aaded5a3e24b9fbdd53750d71a7ea57824db657`. Проверены ZIP CRC, внутренний `checksums.txt`, внешний sidecar, отсутствие development vendor и локальных секретов.
- Каноническая сборка проверила 9026 файлов и все встроенные smoke tests после извлечения, включая runtime, WebAuthn, plans, onboarding и UX/privacy. На build host PHP 8.5.10 / SQLite прошли 1179 из 1185 тестов, шесть MariaDB-only сценариев пропущены в этом прогоне и пройдены в отдельном PHP 8.4/MariaDB regression выше.
- Upgrade из извлечённого v0.9.16 в извлечённый v0.9.17 под PHP 8.4.26/MariaDB 11.8.9 применил ровно две миграции из manifest. Повторный `migrate` без изменений. Опубликованных версий и Trial-периодов после upgrade было ноль.
- Chromium на финальном извлечённом ZIP с PHP 8.5.10/MariaDB 11.8.9 прошёл семь сценариев без page errors. Lifecycle включал ограничения Basic, остановку origin при выключенном cron, восстановление отдельной БД из дампа, сравнение SHA-256 cycles и epochs, manual paid, selective restore и explicit rearm. Старый API replay, Agent run, SSO handoff и Webhook retry отклонены. Независимая delivery отменена до транспортного вызова.
- Репетиция проводилась на локальном loopback с синтетическими данными и перехваченным исходящим HTTP. Реальная доставка почты и подтверждение canary на Jino относятся к отдельному операторскому допуску.

## One-Time Canary Registration v0.9.18

- Runtime commit `6c413a981b17c284739acd333fe6e6904a29b117`. PHP 8.4.26/MariaDB 11.8.9 прошли 20 целевых тестов и 429 утверждений, включая существующие независимые onboarding workers. InnoDB deadlocks — 0. Покрыты primary и fresh MFA, TTL, несовпадение email, погашение и повтор, audit, email verification и pending claim без периода.
- На исходниках и извлечённом ZIP прошли по 20 Chromium-сценариев. Новый сценарий использовал закрытый обычный `/register`, выданное приглашение, отказ после погашения и существующий onboarding до готовности владельца. Для письма использован локальный перехват доставки.
- Каноническая сборка прошла 453 frontend-теста, Vite build и 1181 PHP-тест на PHP 8.5.10/SQLite с шестью MariaDB-only пропусками. Проверены 9028 файлов архива, внутренние checksums, CRC и runtime smoke tests.
- Upgrade из v0.9.17 в v0.9.18 на PHP 8.4.26/MariaDB 11.8.9 применил только `2026_11_03_215440_create_canary_registration_invites`. Повторный migrate без изменений. После обновления приглашений, опубликованных версий и Trial-периодов было ноль.
- `dev2done-v0.9.18-6c413a9.zip`, 39 837 800 байт. SHA-256 `f238fe1127070192ed3538a0ca2c07e7717efa359f53e7fbe7cced4e050f526e`. Операторские действия описаны в [JINO.md](JINO.md) и [CANARY.md](CANARY.md).

## Email Verification UX, canary diagnostics и селекты v0.9.19

- Runtime commit `429edcb4f452d9343715350b321a5dd8f6e0dfe8`. PHP 8.4.26/MariaDB 11.8.9 прошли 45 целевых тестов и 736 утверждений. Проверены существующие onboarding и lifecycle guards, диагностика до допуска, при действующем допуске и после остановки, приватность UUID claims и evidence.
- На исходниках прошёл 21 Chromium-сценарий. Проверены canary invite, экран ожидания письма, та же вкладка с изменением fragment, reload до входа и на MFA, другая сессия, новая вкладка с действующей сессией, использованная и недействительная ссылка, TOTP, recovery codes и ready for activation. Почта и внешние OAuth endpoints были перехвачены локально. Матрица охватила RU/EN/TR/ZH-CN, light/dark/system и desktop/mobile.
- На финальном извлечённом ZIP прошли 23 браузерных сценария, включая те же 21 Chromium-сценарий и две группы Playwright WebKit 1.62.1 в изолированном контейнере. WebKit проверил 36 комбинаций экранов, desktop/iPad viewport и тем. Покрыты All projects с брендированным workspace, workspace selector, Workspaces and owners, Workspace inventory, Registration and Trial, register и account security. Проверены reset, один декоративный caret, отступ, стабильная высота и изменение фильтра. Нативный Safari WebDriver требует разрешения Allow remote automation. Локальный macOS Playwright WebKit зависал до открытия вкладки. Прямой прогон в приложении Safari остаётся открытым.
- Каноническая сборка прошла 453 frontend-теста, Vite build и 1181 PHP-тест на PHP 8.5.10/SQLite с шестью MariaDB-only пропусками. Проверены 9028 файлов архива, CRC, checksums и встроенные runtime smoke tests.
- Upgrade из извлечённого v0.9.18 в извлечённый v0.9.19 на PHP 8.4.26/MariaDB 11.8.9 не потребовал миграций. В одноразовой БД после него не появилось приглашений, опубликованных тарифов или Trial.
- `dev2done-v0.9.19-429edcb.zip`, 39 841 647 байт. SHA-256 `cef84b8ed42d958f7b37d726387bb7974cdc0aa32ec1c3d249b8cf96173c5036`.

## Platform Admin Direct Navigation и MFA v0.9.20

### Исходники

- Runtime commit `34135e7b20e800262e8893f8916c954c57cd2e30`. Оба Platform endpoints при истёкшем fresh MFA возвращают HTTP 423 с `security_step_up_required` и `mutationNotStarted: true`. Причина исходного fallback была в порядке запуска Vue. Роутер ожидал подтверждения, пока глобальный диалог ещё не был смонтирован.
- PHP 8.4.26 и MariaDB 11.8.9 прошли 69 целевых тестов и 960 утверждений для Platform Admin, Account Security и Passkey. Каноническая сборка прошла 455 frontend-тестов и 1181 PHP-тест на PHP 8.5.10/SQLite с шестью MariaDB-only пропусками.
- Chromium, Firefox и Playwright WebKit прошли прямое открытие трёх Platform URL, reload, SPA-навигацию с истёкшим подтверждением, TOTP, отказ гостю и владельцу без платформенных прав, отдельную обработку 401, 403, неизвестного 423, 429 и 500. После TOTP тот же URL продолжил загрузку, ожидающий запрос повторился один раз.
- В Chromium виртуальный аутентификатор прошёл серверную проверку подписи и user verification после отмены первой попытки. Проверено, что оба вызова WebAuthn начались по клику, а кнопки выбора метода разделены при ширине 360 и 1200 px. Тайм-аут проверен frontend-тестом. Полный Chromium onboarding-прогон прошёл 21 сценарий.

### Канонический ZIP

- `dev2done-v0.9.20-34135e7.zip`, 39 842 507 байт. SHA-256 `f3ed085119c494daabaeb4c0c9495d77a150901fca872c7c6dcad587270c80bf`. Проверены CRC, внутренние checksums, внешний sidecar и все встроенные проверки 9028 файлов и извлечённого runtime.
- На извлечённом ZIP Chromium, Firefox и Playwright WebKit прошли Platform-сценарии. Chromium подтвердил серверную проверку Passkey после отмены первой попытки и зазор между кнопками на двух ширинах. При первом прогоне цепочка быстрых reload исчерпала минутный лимит синтетического оператора. После успешного Passkey сервер вернул 429 на повторный plan-access, приложение показало `platform_rate_limited`. Положительный сценарий повторён с отдельным бюджетом этого пользователя в изолированном стенде на том же ZIP.
- Пробное обновление извлечённого v0.9.19 на извлечённый v0.9.20 под PHP 8.4.26/MariaDB 11.8.9 вернуло `Nothing to migrate`. Число canary invitations, опубликованных версий и Trial-периодов осталось нулевым в изолированной БД. Manifest фиксирует закрытые defaults регистрации и Trial, Compatibility в Observe.

## Platform Canary Management UI v0.9.21

### Исходники

- Runtime commit `325e8a51b85c12b9e901795ebae64d94dbb10975`. PHP 8.4.26/MariaDB 11.8.9 прошли 78 тестов и 1264 утверждения для Platform Admin, onboarding, Account Security и subscription lifecycle. InnoDB зарегистрировала 0 deadlocks. Каноническая сборка прошла 455 frontend-тестов и 1181 PHP-тест на PHP 8.5.10/SQLite. Шесть тестов, требующих MariaDB, в SQLite-прогоне пропущены.
- Chromium на изолированном HTTPS-стенде прошёл выдачу приглашения, закрытую обычную регистрацию, email verification через перехваченное письмо, TOTP, recovery codes, pending claim, Approve, Admit и Stop. Покрыты отмена, срок ссылки 30 минут, отказ на replay ссылки, отсутствие URL в browser storage, отказ без CSRF, отказ владельцу при чтении списка, истёкшее MFA, конфликт ревизии, закрытый ENV gate и повтор Admit с исходным ключом.
- Проверены локальный расчёт SHA-256, отправка только хэшей, начало Trial от `verifiedAt`, конец через 604800 секунд и сохранность периода после Stop. Проверены RU, EN, TR и ZH-CN, light/dark/system, desktop и ширина 390 px.

### Канонический ZIP

- `dev2done-v0.9.21-325e8a5.zip`, 39 856 040 байт. SHA-256 `24e5f87a19313b4c11258ea62bfe9e197efcc8575da5c0dbc8f1f9beff7c3289`. Прошли CRC, внутренние checksums, внешний sidecar, проверка 9029 файлов и встроенные smoke-тесты извлечённого runtime.
- Chromium повторил сценарий canary и проверки языков, тем и ширины на извлечённом ZIP. Пробное обновление с v0.9.20 на PHP 8.4.26/MariaDB 11.8.9 вернуло `Nothing to migrate`. В изолированной БД после обновления число приглашений, опубликованных версий и Trial-периодов осталось нулевым.
- После форматирования step-up regex повторная production-сборка дала 114 файлов фронтенда, побайтно совпадающих с ZIP. Фактическая доставка на Jino и утверждение политики хранения остаются отдельными операторскими проверками. Почта и хэши подтверждений в E2E были синтетическими.
