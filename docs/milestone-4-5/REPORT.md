# Milestone 4.5 — Client Workspace & Notifications

Дата: 20 октября 2026. Ветка: `codex/milestone-4-5-client-communications`.
Рабочая копия содержит remotes `project-brief` и `projects-manager`; использована
имеющаяся Vue 3 / Laravel архитектура. Push, deployment, GitHub Release,
регистрация Telegram webhook и изменение удалённых сервисов не выполнялись.
Пользовательские изменения README, package.json, .gitignore, preview и материалов
предыдущих milestone сохранены.

## Реализация

- Клиентские имя, тема, язык и гамбургер собраны справа. На мобильных имя скрыто,
  контролы компактные, шапка однострочная. На главной слева логотип; у задачи —
  прежняя кнопка возврата и навигация задач. Клиентский Push перенесён со шапки/
  footer в собственные «Уведомления». Административный ResponsivePushControls
  продолжает прежнюю работу.
- Из `TaskCard.vue` удалены отдельные стрелки порядка и резерв места для них.
  Сортировка разделов и существующие серверные reorder API сохранены.
- Настройки проекта — постоянно открытый раздел «Уведомления проекта» с Email,
  Push, Telegram и адаптивной матрицей из трёх каналов. Сохранены валидация,
  сохранение, Email/Push-тесты; Telegram имеет персональный тест.
- Правый клиентский drawer переиспользует native modal dialog, анимации и
  `createAdminDrawer`. Четыре пункта: идеи, обсуждения, уведомления, экспорт.
  Закрытие: Escape, крестик, backdrop, переход; возврат фокуса и взаимное исключение
  с мобильной левой панелью. Административных пунктов нет.
- Клиентские обсуждения и экспорт используют существующие представления и store
  factory, без второго генератора документов и без копирования экранов.
- Клиентская непрочитанность находится в отдельной `client_comment_reads`.
  Собственные сообщения не считаются непрочитанными. Маркеры администратора и
  клиентов не пересекаются; ответы старой личности и её черновики очищаются при
  смене клиента. Прочтение видимых комментариев задачи обновляет общий счётчик.
- Общие комментарии создаются в прежней `comments` без task ID. Сохраняются
  фильтры, cursor pagination по 25, автор, время, раздел и переход на комментарий
  задачи. События общих обсуждений проходят через прежний notification service.
- Клиентская страница уведомлений управляет только собственными каналами и
  подпиской текущего устройства. Email показывается при наличии адреса.
  Личная Telegram-привязка, перепривязка, статус, отключение и тест — общие
  компоненты администратора и клиента. Матрица проекта доступна только админу.
- Авторизованный клиентский POST export использует прежние PDF/DOCX renderers.
  В builder введён отдельный серверный allowlist; `price`, `estimateHours`,
  `developerNotes` принудительно выключаются **до SQL SELECT**, независимо от
  JSON-параметров. Неизвестные верхнеуровневые опции отклоняются.

## Telegram

Персональная привязка через 256-битный одноразовый код, SHA-256 в БД, TTL 10 минут,
транзакции и блокировка строки личности. Chat ID хранится encrypted.
Webhook проверяет отдельный секрет, приватность чата, совпадение Telegram
sender/chat, доступ получателя и повтор `update_id`. Отключение отменяет и
ожидающую ссылку. Бот подтверждает успешную привязку.

Direct — значение по умолчанию. Независимый Deno entry point, env, Bearer secret,
ограниченные методы и адреса; зависимостей от AI/Groq нет. Webhook всегда получает
Laravel, Deno используется для исходящих запросов. Есть безопасная CLI-диагностика
с Jino, ограниченные тайм-ауты, дедупликация, обработка 429/401/403/недоступности,
отключение заблокированной привязки, HTML escaping и inline-переходы. Повторных
автоматических отправок нет.

Настройка и ручная E2E-приёмка: [TELEGRAM.md](TELEGRAM.md).
Реальные тестовые credentials не предоставлены: mock-тесты не считаются
фактической доставкой; live `getMe`, регистрация webhook, Jino и реальные
Telegram direct/Deno доставки не проверялись.

## Миграция и API

Миграция: `api/database/migrations/2026_10_20_161516_add_client_communications.php`.
Проверены fresh schema и обновление legacy schema. Миграция применялась только
в изолированных тестовых БД; рабочая/production БД не менялась.

Новые клиентские маршруты под существующим middleware `client`:

```text
GET/PATCH /api/client/notifications
GET/POST  /api/client/project/{project}/discussions
POST      /api/client/project/{project}/discussions/read
POST      /api/client/project/{project}/export
```

Персональные Telegram-маршруты есть для `admin` и `client`:

```text
GET/DELETE /api/{persona}/telegram
POST       /api/{persona}/telegram/connect
POST       /api/{persona}/telegram/test
POST       /api/telegram/webhook   # stateless, Telegram secret required
```

Все project endpoints сверяют клиентскую сессию с проектом. Подстановка чужого
проекта/комментария, отзыв ссылки и подделка автора покрыты серверными тестами.

## Проверки

Полный прогон: **Laravel 498/498 (5054 assertions)**, **frontend 172/172**,
**48/48 браузерных конфигураций**, **10/10 сценариев навигации**. Production build,
Prettier и Pint прошли. Результаты и команды приведены в `checks.json` рядом с отчётом.
Проверки включают frontend unit/integration, Laravel, production build,
Prettier, Pint и браузерные сценарии.

Браузерная матрица: 48 сочетаний = 1440/1280/768/390 × light/dark/system × RU/EN ×
администратор/клиент. Клиенты Anna/John в отдельных контекстах. Проверены root/task
шапки, четыре пункта drawer, Escape/backdrop/focus и исключение панелей,
навигация, общие комментарии, фильтры, настройки, персональный Telegram UI,
экспорт без закрытых полей и отсутствие горизонтального overflow/runtime errors.
Для system также переключалась ОС light → dark. API браузерной матрицы mocked;
права доступа, выборки, реальные PDF/DOCX и миграции проверены Laravel отдельно.

Ещё 10 существующих navigation-polish сценариев проверяют сохранение заметок,
отмену перехода/выхода с черновиком, Cmd/Ctrl+S и прежнюю административную навигацию.
Скриншоты после исправления отступов и размеров чекбоксов проверены визуально.

Воспроизведение:

```sh
npm test
npm run build
npm run format:check
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Браузерные проверки используют уже установленный Playwright, без новых зависимостей:

```sh
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/milestone45.browser.mjs
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/navigation-polish.browser.mjs
```

Потребуется возможность запускать локальный Vite на 127.0.0.1:5189 / 5188 и браузер.

## Скриншоты

Репрезентативные 28 PNG рядом с отчётом: desktop light/RU, desktop dark/EN,
tablet system/RU, mobile dark/EN; администратор и клиент. Полный результат матрицы:
[browser-results.json](browser-results.json).

| Экран                      | Desktop                                                 | Mobile                                              |
| -------------------------- | ------------------------------------------------------- | --------------------------------------------------- |
| Клиентское меню            | [1440 light RU](drawer-client-1440-light-ru.png)        | [390 dark EN](drawer-client-390-dark-en.png)        |
| Обсуждения клиента         | [1440 light RU](discussions-client-1440-light-ru.png)   | [390 dark EN](discussions-client-390-dark-en.png)   |
| Уведомления клиента        | [1440 light RU](notifications-client-1440-light-ru.png) | [390 dark EN](notifications-client-390-dark-en.png) |
| Экспорт клиента            | [1440 light RU](export-client-1440-light-ru.png)        | [390 dark EN](export-client-390-dark-en.png)        |
| Уведомления администратора | [1440 light RU](notifications-admin-1440-light-ru.png)  | [390 dark EN](notifications-admin-390-dark-en.png)  |
| Обсуждения администратора  | [1440 light RU](discussions-admin-1440-light-ru.png)    | [390 dark EN](discussions-admin-390-dark-en.png)    |
| Экспорт администратора     | [1440 light RU](export-admin-1440-light-ru.png)         | [390 dark EN](export-admin-390-dark-en.png)         |
