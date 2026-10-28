# Проверки Live Sync + UI

## Автоматические проверки

Каноническая сборка исходного коммита `5b999c2`: **819/819 Laravel-тестов, 12 302 assertions; 333/333 frontend-тестов**. Успешны brand check, Vite build, подписанный WebAuthn и HTTP runtime распакованного ZIP, включая новый sync endpoint. Полные метаданные указаны в `RELEASE-ARTIFACT.json`. Канонический `./bin/build-release` запускает оба набора, brand check, Vite build, production Composer install, проверку секретов/allowlist/checksums и HTTP runtime распакованного пакета.

Новые Laravel feature-проверки: обычный bootstrap с cursor, единственный ограниченный запрос журнала, scope owner/developer/client, чужие workspace/project и cursor, rollback, переполнение и истечение cursor, stale editVersion и изменение названия раздела, цикл завершения и повторного предложения, единичная отправка отзыва, owner-only API, общий набор URL и сохранение сайта через прежний API.

Frontend-проверки: таймер 300000 ms и отсутствие стартовой проверки; скрытие/возврат вкладки; сетевые ошибки; объединение Push; Push во время запроса; координация вкладок; смена identity; отложенные invalidation; повреждённые часы localStorage; очередь refresh; занятый Web Lock при недоступном storage; общий набор URL. Проверены сохранение и явное разрешение конфликта developer draft, удаление задачи, прежнее системное уведомление SW без дублирования, доступная кнопка просмотра отзыва.

## Browser: настоящие PHP/SQLite, Vite и Chromium

[Полный успешный прогон](BROWSER-RESULTS.json): 10 UI-конфигураций, 9 функциональных сценариев, отдельная запись ограничения Push permission; ошибок JavaScript нет. После уточнения заголовка просмотра отзыва повторный [финальный функциональный прогон](BROWSER-FUNCTIONAL-FINAL.json) также успешен (9 сценариев и запись ограничения Push). Данные синтетические, память PHP 128 MB, одноразовая БД. Пароль и recovery codes относятся только к disposable fixture; проверка не обходит MFA.

| Ширина | Light | Dark  |
| ------ | ----- | ----- |
| 1440   | RU    | EN    |
| 1280   | TR    | ZH-CN |
| 768    | RU    | EN    |
| 390    | TR    | ZH-CN |
| 360    | RU    | EN    |

Это 10 сочетаний с покрытием всех ширин/тем/языков, а не полный набор из 40 сочетаний. Сняты проекты, уведомления, брендинг, сайт, раскрытая история клиента, мобильное меню; дополнительно обсуждения на 1440/390. Проверены отсутствие горизонтального overflow, полное название компании и уменьшение истории на desktop. Визуально просмотрены contact sheets всех финальных страниц и отдельные desktop/mobile, ошибка сайта и модальное окно отзыва.

Функционально проверены три одновременно авторизованные роли, удалённые статусы/комментарии, сохранность черновиков, реальные HTTP 409 и явное разрешение конфликта формы клиента, новая идея в открытом brief, общие/последние обсуждения, независимая ручная проверка, сайт с нормализацией и inline error, просмотр отзыва, один опрос для двух вкладок с native BroadcastChannel/Web Locks, три fallback-проверки за 15 минут.

Исходные изображения: frontend из неизменяемого `4de88df`, тот же новый disposable backend и те же данные. Это сравнение оформления, не прежнего backend. Известное старое переполнение обсуждений 390 TR/ZH зафиксировано, новые страницы проходят строгую проверку. [Сравнения](COMPARISONS.md) используют одинаковый масштаб внутри каждой пары.

## Нагрузка

Отдельный [baseline](LOAD-BEFORE.json) и [новый прогон](LOAD-AFTER.json): часы Chromium продвигаются по 60 секунд, приложение выполняет настоящие HTTP-запросы, перед отсчётом и после каждого шага ожидается networkidle. Это воспроизводимые виртуальные 15 минут, а не wall-clock soak.

| За 15 минут после штатной загрузки  |   До | После |
| ----------------------------------- | ---: | ----: |
| Дополнительный `/sync` при открытии |    0 |     0 |
| `/security/status`                  |   15 |    15 |
| `/security/passkey/confirm/options` |    7 |     7 |
| `/admin/sync`                       |    0 |     3 |
| Всего HTTP-запросов                 |   22 |    25 |
| Декодированные ответы, байт         | 7922 |  8894 |

Частые security-запросы принадлежат прежнему механизму step-up; он не изменён. Заголовки HTTP/TLS не входят в байты. При отсутствии изменений полных загрузок проектов/комментариев от sync нет. Общий функциональный прогон продвигает время шагами 300 секунд для проверки sync cadence; его общий security-трафик не является метрикой нагрузки. Авторитетные числа нагрузки — отдельные LOAD-файлы с минутным шагом.

[SQL evidence](SQL-MEASUREMENT.json): 9 запросов на HTTP-проверку, из них один к журналу, ответ с одним изменением 370 байт. SQLite EXPLAIN использует индекс `(workspace_id,id)`, затем PK projects; N+1 на события отсутствует. Анализ MariaDB/Jino на реальной схеме и нагрузке не выполнялся.

## Границы проверки

Headless Chromium в этой среде возвращает `Notification.permission=denied` даже после grantPermissions. Проверены настоящий SW, message, BroadcastChannel и Web Locks; в ветке denied системный showNotification перехватывается тестом. Доставка через внешний Push provider и отображение операционной системой **не подтверждены**. Поведение прежнего showNotification/click дополнительно покрыто unit-тестом. Работа без Push и недоставленный Push покрываются fallback-сценарием. Hidden/visibility и временная недоступность сети проверены детерминированными coordinator-тестами, не отдельным OS/network soak.

Локально: PHP 8.5, SQLite, Node 22 и Chromium. Production/Jino, реальные клиенты и данные не затрагивались. Не выполнялись push, merge, deployment и ручное тестирование на физических устройствах.

## Воспроизведение

```sh
npm test
npm run build
cd api
vendor/bin/pint --dirty --format agent
php artisan test
cd ..
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/live-sync.browser.mjs
LIVE_SYNC_BASELINE=1 LIVE_SYNC_UI_ONLY=1 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/live-sync.browser.mjs
LIVE_SYNC_IDLE_ONLY=1 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/live-sync.browser.mjs
LIVE_SYNC_BASELINE=1 LIVE_SYNC_IDLE_ONLY=1 PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/live-sync.browser.mjs
./bin/build-release
```

Browser fixture требует одноразовую SQLite с префиксом `brief-live-sync-` и APP_ENV=testing. Не направлять её на действующую БД. Исходные PNG и промежуточные browser-логи находятся локально в игнорируемом `artifacts/live-sync-ui/`, выбранные сравнения сохранены в этом отчёте.
