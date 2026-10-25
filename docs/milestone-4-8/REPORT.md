# Milestone 4.8 — архитектура и проверка

Ветка: `codex/milestone-4-8-developer-invitations-account`, база `e4ebc27`. Работа выполнена в существующем checkout, связанном с `web86/projects-manager` через remote `projects-manager`. Push, deployment и GitHub Release не выполнялись.

## Участники и приглашения

`User` сохраняет единую идентичность. `WorkspaceMember` с активными `owner/admin` и `ProjectMember` остаются единственным основанием workspace/project доступа. В интерфейсе admin отображается как «Разработчик». Владелец видит все проекты пространства и управляет командой; разработчик получает прежние инструменты только назначенных проектов. `ProjectAccess` продолжает фильтровать API и получателей персональных уведомлений.

`DeveloperInvitation` хранит workspace, автора приглашения, нормализованный Email, необязательное имя, список project IDs, locale, SHA-256 token hash, expiry/accepted/revoked timestamps. Секрет генерируется `random_bytes(32)`, срок — 7 суток. Ссылки используют fragment, API принимает токен в POST-body, браузер убирает fragment из адреса. Сырые токены/пароли не записываются в application logs; password/token fields исключены из flash. Mail transport log не используется для новых секретных ссылок.

Принятие работает в транзакции: workspace lock → повторная проверка invitation под lock → проверка authenticated Email или создание нового User → memberships → accepted timestamp. Повторная отправка вращает токен, отзыв блокирует его. Unique constraints и повтор транзакции при deadlock предотвращают дубли. Существующий аккаунт без memberships может войти для принятия только при валидном приглашении с совпадающим Email и правильном пароле. Одного токена недостаточно для входа в существующий аккаунт. Доступ к mailbox подтверждается доставленным invitation-token в сочетании с входом для существующего User; отдельный второй verification-token для того же mailbox не требуется.

Операции команды сервером проверяют текущий workspace, доступ к route project и роль owner. Все project UUID выбранного списка проверяются в этом workspace. Owner-участники защищены от удаления/изменения назначений. Удаление developer из workspace делает membership inactive, удаляет только назначения этого workspace и отзывает pending invitations; User, комментарии и история сохраняются. Изменение списка проектов не удаляет workspace membership — при пустом списке показано понятное пустое состояние. Список проектов обновляется при открытии dashboard.

## Аккаунт, сессии и аватары

Глобальные `/admin/account` и `/admin/account/security` не требуют выбранного workspace; профиль меняет только текущего authenticated User. Изменение Email через общий PATCH запрещено. Новый Email проверяется по текущему паролю, уникальности и rate limit, хранится отдельно до одноразового подтверждения за 60 минут. Подтверждение под user lock меняет адрес того же User, удаляет старый password-reset token и отправляет извещение прежнему mailbox. Конфликт конкурентной уникальности обрабатывается как validation error. Отзыв, истечение и повтор ссылки не меняют аккаунт.

Пароли 9–1024 символа, current-password check, confirmed, запрет прежнего пароля. Laravel Argon2id поддерживает длинные пароли; legacy bcrypt проверяется и постепенно перехешируется при входе. Password Broker использует существующую `password_reset_tokens`, expiry 60 минут, throttle 60 секунд, дополнительные IP/email rate limits и одинаковый публичный ответ. Reset очищает pending login-email change. Session version увеличивается; другие database sessions удаляются, remember token меняется. Version middleware блокирует старые sessions независимо от session driver.

Аватары отключены от внешних сервисов по умолчанию. Gravatar включается явно, URL использует SHA-256 trim/lowercase Email согласно [официальной документации](https://docs.gravatar.com/rest/hash/). Upload: Laravel MIME/image validation, 2 MiB, JPEG/PNG/WebP, максимум 4096×4096 и 16 млн пикселей, GD decode/reencode в квадратный 256px WebP без EXIF/исходного имени. Файлы private, URLs содержат случайный key; image endpoint разрешает владельца изображения или активного участника общего workspace. Заменённый файл удаляется после успешного сохранения, если больше не используется. Выбор initials/Gravatar удаляет прежнюю загрузку.

UserAvatar используется в меню, профиле и команде. URL аватара добавляется к developer-комментариям только в admin API; клиентам не выдаются developer Email, Gravatar hash или private image URLs. Поля истории не изменяются. Notification preferences, Telegram и Push сохраняются по user ID при смене имени/Email.

## Навигация и совместимость

Единые ProjectNavigation/ProjectSidebar работают в desktop и mobile drawer. `adminMenu.js` задаёт section/icon и owner-only Team; AppIcon предоставляет проверенные имена. Иконки decorative (`aria-hidden`), 20px, currentColor активного пункта; текст задаёт доступное имя. Unread badge справа, Trash отделён border, Account link и отдельный Logout. Иконки добавлены также в глобальный sidebar и account tabs. Проверены светлая/тёмная темы и RU/EN.

Существующие клиентские magic links, CSRF/session cookies, API/attachment/export ограничения и project notification preferences сохранены. Новых JS/PHP пакетов нет. Миграция additive, не пересоздаёт существующие таблицы и не переписывает роли/данные владельца. Проверки legacy migrations дополнены default-полями и продолжают сравнивать прежние значения.

## Проверка

Машинные результаты: `verification.json`, `browser-results.json`, `concurrency-results.json`, `regression-navigation/browser-results.json`, `regression-notifications/browser-results.json`, `regression-task-management/browser-results.json`.

- Полный Laravel suite на SQLite и отдельной MariaDB 11.8.9; результаты и assertions в verification.json.
- Полный frontend suite, production Vite build, Pint и Prettier.
- Реальный Laravel API + database sessions + локальный SMTP sink: 6 функциональных цепочек и 8 visual configurations (1440/390 × RU/EN × light/dark). Создание аккаунта по письму, повторное приглашение, отзыв доступа в активной сессии, профиль/аватар, Email proof/replay, смена пароля с отзывом второго браузера, Broker reset с отзывом прежней сессии. Во всех конфигурациях проверены меню/активные иконки, компактные checkboxes и отсутствие горизонтального overflow.
- MariaDB concurrency: 6 сценариев (new/existing × 3), каждый с 6 отдельными PHP-процессами. Всегда один успешный accept и пять 410, ровно один User/membership/project grant.
- Браузерные регрессии: 32 конфигурации навигации, 60 конфигураций клиентских/личных/проектных уведомлений плюс 5 функциональных сценариев, 20 конфигураций управления идеями/комментариями/корзиной/экспортом.
- Визуально просмотрены desktop profile, team и мобильные invitation/drawer. При проверке исправлено наследование глобальной ширины input чекбоксами выбора проектов; добавлено геометрическое assertion 18×18.

Письма реально прошли SMTP-протокол локального тестового приёмника. Это не проверка доставки Jino и не проверка реального Gravatar-профиля: внешний Gravatar заблокирован в E2E, проверяются opt-in URL/hash/fallback. Проверку Jino пользователь отложил для своей ручной приёмки. Загрузка на production не выполнялась.

## Скриншоты и передача

- `team-1440-ru-light.png`, `team-390-en-dark.png` — команда и статусы.
- `invitation-1440-ru-light.png`, `invitation-390-ru-light.png` — приглашение с выбором проектов.
- `profile-1440-ru-light.png`, `profile-390-en-dark.png` — личный профиль.
- `security-1440-en-dark.png`, `security-390-ru-light.png` — Email/пароль.
- `drawer-390-en-dark.png` — общая мобильная навигация с иконками.

Все 8 сочетаний языка/темы/размера представлены снимками. Production ZIP формируется штатным `bin/build-release`: no-dev vendor, assets, release.json с commit, checksums и runtime verification. Передача и откат описаны в PRODUCTION.md. SMTP Jino остаётся единственным намеренно отложенным внешним шагом.
