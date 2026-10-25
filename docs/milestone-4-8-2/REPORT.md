# Milestone 4.8.2 — Client Navigation & Notifications UI Polish

Ветка: `codex/milestone-4-8-2-client-notifications-ui`.

## Изменения

- Переиспользуемый Telegram-баннер под шапкой: проверенное состояние подключения и персонального разрешения, маршруты клиента/администратора, переход к Telegram-карточке, постоянное закрытие отдельно для типа и ID аккаунта. На публичных страницах, страницах отказа в доступе и страницах задач баннера нет.
- Общий Pinia store для Telegram и подтверждённых персональных настроек. Запросы кешируются и объединяются; старые ответы не попадают в другой аккаунт и не перезаписывают подтверждённое сохранение. Закрытый баннер не инициирует запросы; недоступный Telegram не вызывает дополнительный запрос предпочтений ради баннера.
- Единые карточки Telegram / Email / Push, доступные с клавиатуры переключатели, понятные статусы, инструкция подключения, повторное подключение, тестовая отправка, обработка ошибок и загрузки. Клиент сохраняет разрешения кнопкой; администратор видит подтверждённое сервером состояние. Подтверждение и отложенная смена Email, legacy addresses и Push enrollment сохранены.
- Клиентский sidebar >960px на проекте, обсуждениях, уведомлениях и экспорте. Меню берётся из `useClientNavigation()`, сохраняет active state и счётчик обсуждений. На мобильном используется ClientDrawer; при переходе на desktop открытый Drawer закрывается. На страницах создания/просмотра/редактирования задачи sidebar отсутствует.
- Existing design tokens, light/dark и RU/EN. Без новых зависимостей и миграций. Единственное backend-дополнение: непрозрачный `notificationPreferenceId` в существующем профиле администратора; он не зависит от Email и не раскрывает внутренний ID.

## Скриншоты

| Экран                                      | Light                                           | Dark                                          |
| ------------------------------------------ | ----------------------------------------------- | --------------------------------------------- |
| Клиентский проект, 1440px, Telegram-баннер | [Light](project-client-1440-ru-light.png)       | [Dark](project-client-1440-ru-dark.png)       |
| Клиентский проект, 390px, Telegram-баннер  | [Light](project-client-390-ru-light.png)        | [Dark](project-client-390-ru-dark.png)        |
| Клиентские уведомления, 1440px             | [Light](notifications-client-1440-ru-light.png) | [Dark](notifications-client-1440-ru-dark.png) |
| Клиентские уведомления, 390px              | [Light](notifications-client-390-ru-light.png)  | [Dark](notifications-client-390-ru-dark.png)  |
| Администраторские уведомления, 1440px      | [Light](notifications-admin-1440-ru-light.png)  | [Dark](notifications-admin-1440-ru-dark.png)  |
| Администраторские уведомления, 390px       | [Light](notifications-admin-390-ru-light.png)   | [Dark](notifications-admin-390-ru-dark.png)   |

## Проверки

- `npm test`: 217 tests passed. Новые unit regressions проверяют состояния баннера, постоянное закрытие, разделение пользователей/типов, недоступный localStorage, дедупликацию и устаревшие ответы.
- `PHP_INI_SCAN_DIR=:$PWD/dev-php php artisan test --compact` в `api/`: 571 tests passed, 5729 assertions. Расширена проверка стабильности непрозрачного ID после подтверждения нового Email; внутренний ID по-прежнему не раскрывается.
- `tests/notifications-ui.browser.mjs`: 32 сочетания 1440/1024/768/390px × light/dark × RU/EN × клиент/администратор, плюс 3 сценария сохранения/ошибок/изоляции аккаунтов. Результаты: [browser-results.json](browser-results.json). Включены длинные имена, отсутствие горизонтального скролла, keyboard Space, Drawer/Escape/смена breakpoint, reload, CTA/hash, connected/disconnected/unavailable, retry, отсутствие Email, тест Telegram, ошибки API и отсутствие sidebar/баннера на исключённых страницах.
- `tests/milestone46.browser.mjs`, `M46_QUICK=1`: 320px, RU/light, обе роли и 5 функциональных сценариев: project event rules, сохранение каналов, переходы между workspace, отложенный Email и подтверждение fragment-token, reduced motion/состояния задач. Селекторы обновлены для новой структуры и серверного подтверждения переключателя.
- `tests/navigation-polish.browser.mjs`: 10 существующих сценариев полноэкранных задач и навигации.
- `tests/milestone48.browser.mjs`: 14 существующих интеграционных сценариев с реальным Laravel API, временной SQLite, database sessions и локальным SMTP (6 писем).
- Production build, Prettier, Pint и `git diff --check` — выполнены.

Browser-тесты Telegram используют управляемые ответы API; внешняя доставка в Telegram не выполнялась. Browser-тесты Push используют существующие fixtures; реальные разрешения на пользовательских устройствах и внешняя push-доставка не запрашивались. Email integration использует локальный SMTP. Визуально просмотрены desktop/mobile и обе темы.

## Release

[Установка и откат](PRODUCTION.md). Patch предназначен для установленного 4.8.1 `edc141d`; сборка выполняется из чистого коммита, SHA записан в `PATCH.json`. Перед выпуском сборщик проверяет объединённый packaged runtime. Deployment и push не выполнялись.
