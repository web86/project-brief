# Milestone 4.8 — инструкция обновления production

Изменения подготовлены в локальной ветке `codex/milestone-4-8-developer-invitations-account` от `e4ebc27`. ZIP содержит private Laravel application с production vendor и public frontend assets. Публикация, push, deployment и GitHub Release не выполнялись. Живую проверку SMTP Jino пользователь выполняет самостоятельно после установки.

## Требования и резервная копия

PHP 8.3+ (целевой production PHP 8.4), прежние расширения из DEPLOY-JINO.md, **Argon2id**, GD с JPEG/PNG/WebP, writable `storage/` и `bootstrap/cache/`. Проверка до обновления:

```sh
php -r 'exit(in_array("argon2id", password_algos(), true) && function_exists("imagewebp") && function_exists("imagecreatefromwebp") ? 0 : 1);'
```

Новые пароли используют штатный Laravel Argon2id: это позволяет поддерживать длинные пароли без ограничения bcrypt в 72 байта. Прежние bcrypt-хеши продолжают проверяться; после успешного входа Laravel обновляет хеш. Production параметры по умолчанию: 64 MiB, time cost 4. Сниженные параметры `ARGON_MEMORY=1024`, `ARGON_TIME=1` используются исключительно в автоматических тестах, переносить их в production нельзя.

Сохраните SQL backup БД, `.env`, APP_KEY, весь `storage/`, avatars/uploads, старые код/vendor/public assets, конфигурацию веб-сервера и secrets SMTP/Telegram/Push. Запишите версию исходного production-кода. Не меняйте APP_KEY.

## Установка

1. Включите maintenance в действующем `~/project-brief-app`: `php artisan down`.
2. Загрузите `private/project-brief-app/` из ZIP в `~/project-brief-app/`, `public/` — в `~/domains/brief.web86.site/` по прежней схеме Jino. ZIP и private-каталог не должны находиться в document root.
3. Замените vendor полностью. Сохраните действующие `.env` и `storage/`, включая maintenance marker. Пустые каталоги архива не должны удалять рабочие файлы. Удалите устаревшие compiled cache files в `bootstrap/cache`, сохранив каталог.
4. Выполните:

```sh
php artisan config:clear
php bin/check-server
php artisan migrate --force
php artisan optimize
php artisan up
```

Не выполняйте `key:generate`, `migrate:fresh`, `db:wipe`, `seed` или first-install на существующем production. Node/npm/Composer на Jino не нужны.

Новая миграция `2026_10_25_125153_add_developer_invitations_and_account_profile` создаёт только `developer_invitations` и добавляет nullable pending Email, источник/путь аватара и session version к `users`. `users`, `workspace_members`, `project_members`, `sessions`, `password_reset_tokens` не пересоздаются. Старые owner/admin memberships и данные проектов не переписываются. Если production отстаёт от 4.7.1, Laravel также выполнит прежние неприменённые миграции.

`SESSION_DRIVER=database` рекомендуется и проверен в реальных E2E: пароль удаляет другие строки sessions, а версия аккаунта дополнительно блокирует старые сессии независимо от драйвера. Cookies/CSRF/magic links клиентов сохранены.

## SMTP Jino и ручная приёмка

Настройте прежние `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, а также правильный HTTPS `APP_URL`. Если `MAIL_URL` не используется, **удалите эту переменную**, не оставляйте пустую строку. Новые ссылки с токенами никогда не отправляются через Laravel log transport. При отсутствии SMTP форма сообщает об ошибке; приглашение можно отправить повторно после настройки.

После изменения конфигурации очистите и пересоберите config cache. Синтетическая проверка двух шаблонов, без создания аккаунтов и действующих токенов:

```sh
php artisan account:mail-check
php artisan account:mail-check --live --to=YOUR_TEST_MAILBOX
```

Первый вызов ничего не отправляет. Второй отправляет ровно два письма выбранному тестовому получателю. SMTP acceptance не доказывает доставку: проверьте Inbox/Spam, отправителя, RU/EN и кликабельность ссылок. Внешняя отправка Jino в рамках разработки не выполнялась по согласованию с пользователем.

Проведите реальную приёмку:

- `/api/health`, вход прежнего владельца, список проектов и рабочая клиентская magic link.
- Владелец: «Команда» → приглашение на новый тестовый Email и один проект; письмо → создание аккаунта → виден только назначенный проект.
- Повторное приглашение того же аккаунта в другой проект и другое workspace: после входа принимается без второго User; доступны оба workspace.
- Повторная отправка делает прежнюю ссылку недействительной; отзыв и истечение блокируют принятие. Принятую ссылку нельзя использовать снова.
- Уберите проект через «Изменить проекты». В открытой сессии разработчика API проекта возвращает 404; после перехода к списку проект исчезает. Доступ к другому проекту/workspace сохраняется.
- Профиль: имя, RU/EN, initials → upload → Gravatar → initials. Файлы JPEG/PNG/WebP до 2 МБ; остальные форматы отклоняются.
- Безопасность: новый Email + текущий пароль. До подтверждения вход по прежнему Email; затем по новому. На старый адрес приходит извещение. Членства, Telegram/Push и настройки сохраняются.
- Пароль: текущий пароль, минимум 9 символов, совпадающее подтверждение, запрет прежнего пароля. Во втором браузере прежняя сессия недействительна.
- «Забыли пароль?» → письмо → новый пароль. Ссылка одноразовая, действует 60 минут, старые сеансы и pending Email-change отзываются.
- Проекты, идеи, комментарии, PDF/DOCX, личные уведомления, Telegram и Push; desktop/mobile, RU/EN, light/dark.

## Откат

Безопасный полный откат: maintenance → восстановить согласованную резервную копию БД, private code/vendor, public assets и storage → сохранить исходную APP_KEY/SMTP-конфигурацию → очистить cache → проверить owner/client доступ → up. Такой откат теряет изменения после backup; предварительно сохраните новую БД и uploads для последующего восстановления данных.

Для временного отката к прежнему интерфейсу с сохранением новой БД не запускайте `migrate:rollback`: оставьте additive columns/table. **Сохраните новый `config/hashing.php` с Argon2id/проверкой legacy bcrypt**, иначе прежний код с bcrypt verify может перестать принимать уже обновлённые пароли. Очистите все developer sessions, чтобы старый код не восстановил сеансы, отозванные version-проверкой. Повторно проверьте memberships и доступ до снятия maintenance; старый UI не умеет управлять приглашениями/аккаунтом. Предпочтителен полный согласованный restore.

`down()` новой миграции удаляет invitation/profile поля и не предназначен для обычного production rollback: без backup он уничтожит pending-состояния и метаданные аватаров. Не выполняйте его на живой базе.
