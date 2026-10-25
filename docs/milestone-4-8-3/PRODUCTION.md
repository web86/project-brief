# Milestone 4.8.3 — production patch и откат

Patch применяется к полной поставке Milestone 4.8.2, коммит `be2b883f99ef35bbe61bc2113bece51a543b6822`, архив `project-brief-20261025-182822-be2b883.zip`. Он не заменяет установку 4.8.2 на более старом сервере. Если исходная версия отличается, сначала установите соответствующую базу либо соберите полный ZIP командой `./bin/build-release`.

Новые зависимости, миграции и настройки .env не требуются. Клиентский notifications API не изменён. Backend обсуждений расширен совместимо: старые unreadCount, kind/filter/cursor и read без kind продолжают работать.

## Содержимое

- `private/project-brief-app/app/Http/Controllers/ProjectDiscussionController.php`.
- `private/project-brief-app/app/Services/ProjectDiscussions.php`.
- Изменённые `public/` assets, index.html и сопутствующие файлы сборки.
- PATCH.json (база, SHA нового коммита, список файлов и SHA-256), BASE-CHECKSUMS.txt, checksums.txt, release.json и инструкции.

Vendor, активные .env, uploads, sessions, БД, логи и developer tooling в patch не включаются. Прежние hashed assets не удаляются: они нужны уже открытым вкладкам и откату. Не загружайте ZIP или private-каталог в document root.

## Установка

1. Сравните базовую версию/SHA с release.json установленной поставки. Проверьте архив по файлу `.zip.sha256`. Сохраните backup двух PHP-файлов, public/index.html, старых assets и release metadata. Действующие .env, APP_KEY, vendor и storage сохраните.
2. В действующем `~/project-brief-app/` включите maintenance: `php artisan down`.
3. Скопируйте private-файлы из patch в `~/project-brief-app/`, public-файлы — в `~/domains/brief.web86.site/`. Накладывайте только перечисленные файлы, не синхронизируйте с удалением старых assets. Замените оба backend-файла и frontend одной поставкой. Не копируйте vendor и не меняйте APP_KEY.
4. Выполните:

```sh
php artisan optimize:clear
php artisan optimize
php artisan up
```

У этого patch нет новых миграций. Не выполняйте migrate:fresh, db:wipe, first-install или seed. Node/npm/Composer на Jino не требуются. Перезагрузите страницу после обновления.

## Ручная приёмка

- Клиент → личные уведомления: каждый из трёх каналов сохраняется без кнопки. Перезагрузка сохраняет значения; при временной ошибке видна ошибка и прежнее подтверждённое значение. Быстро измените разные каналы: запросы выполняются последовательно, итог содержит все изменения. Изменение Telegram preference не подключает аккаунт; отдельно проверьте connect/reconnect/test.
- Владелец, назначенный разработчик и клиент → обсуждения: по умолчанию общая переписка; только одна лента видна; вкладки и меню показывают правильные счётчики. Переключение ничего не читает автоматически.
- Отметьте сообщение, затем всю активную вкладку: другая категория остаётся непрочитанной. Проверьте пагинацию обеих категорий и неотправленный текст при переключении.
- Откройте #comment-ID, отсутствующий в первой странице: выбирается нужная вкладка, сообщение видно и сфокусировано. «Показать последние сообщения» возвращает начало ленты. Удалённая/недоступная ссылка даёт понятное сообщение.
- Другой проект/workspace не наследует ленту и счётчики. Повторите на mobile, RU/EN и light/dark; проверьте прежние идеи/вложения, exports, project permissions и личные настройки администратора.

## Откат

Maintenance → восстановить из backup оба PHP-файла, public/index.html и прежнюю release metadata → очистить/пересобрать caches → проверить owner/client вход и обсуждения → up. Новые assets можно оставить; они не используются старым index.html и не мешают старым открытым вкладкам. БД и персональные preferences не откатываются, поскольку их схема и бизнес-контракт не менялись. Миграции откатывать не требуется.

Воспроизводимая сборка patch из чистого локального коммита:

```sh
node deployment/build-milestone483-patch.mjs
```

Для другого расположения проверенного базового ZIP используйте `M483_BASE_ZIP=/absolute/path/to/base.zip`. Сборщик проверяет точную базу, allowlist backend, assets/checksums/secrets, затем накладывает patch на временную копию полной поставки и запускает packaged runtime. Deployment и push выполняются отдельно только по разрешению пользователя.

## Если требуется полный ZIP

`./bin/build-release` собирает полную версию 0.4.8.3 с production vendor и assets. Для установки используйте DEPLOY-JINO.md из архива: backup БД/code/vendor/public/.env/storage → maintenance → замена private application/vendor и public assets с сохранением действующих .env, APP_KEY и storage → `php artisan optimize:clear` → `php bin/check-server` → `php artisan migrate --force` → `php artisan optimize` → `php artisan up`. Не используйте first-install, key:generate или migrate:fresh на существующем production. Новых миграций 4.8.3 нет, но более старой установке необходимы прежние неприменённые миграции 4.8/4.8.2.
