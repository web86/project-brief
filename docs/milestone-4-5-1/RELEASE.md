# Project Brief 0.4.5.1 — двусторонний Telegram-мост

Полный архив обновления Laravel/Vue с production vendor и миграциями.
Входящий webhook переносится на существующий Deno-сервис; исходящий
`/telegram/send` сохранён. Добавлены отдельный forward secret, атомарная
дедупликация `/start`, ответы RU/EN и безопасные логи. Временный диагностический
endpoint удалён. Автоматического deployment или переключения Telegram нет.

## Что загружать

- `private/project-brief-app/` → существующий `~/project-brief-app/` на Jino.
- `public/` → существующий `~/domains/brief.web86.site/`.
- `deployment/telegram-bridge.mjs` → entrypoint существующего Deno-приложения
  отдельным ручным обновлением, **не** в публичную папку Jino.
- `PRODUCTION.md` → пошаговая инструкция по env, Deno, проверке callback,
  регистрации webhook с Mac и откату; читать перед переключением.
- `DEPLOY-JINO.md` → общие требования и схема размещения приложения.
- `release.json`, `checksums.txt` → исходный commit и SHA-256 файлов пакета.

Серверные Composer/Node/npm не требуются. Не загружайте весь архив в document root.
Сохраните резервную копию БД, прежних файлов, `.env` и `storage/` вне public.
**Прежние APP_KEY, uploads, сессии, VAPID и секрет исходящего моста сохраняются.**

## Обновление Laravel и миграция

В существующем `~/project-brief-app` до замены файлов:

```sh
php artisan down
```

Загрузите новый код, замените `vendor` целиком и обновите public assets.
**Не перезаписывайте существующие `.env` и `storage/` пустыми папками пакета.**
Сохраните maintenance marker. Очистите прежние файлы config/routes/events/package
cache в `bootstrap/cache` по общей инструкции DEPLOY-JINO.md.

Добавьте новые env по PRODUCTION.md, первоначально оставив
`TELEGRAM_WEBHOOK_TRANSPORT=direct`. Затем в `~/project-brief-app`:

```sh
php artisan config:clear
php artisan migrate --force
php artisan optimize
php artisan up
php artisan telegram:check
php artisan telegram:check --live
```

Если migration/optimize завершились ошибкой, не выполняйте `up`, сначала исправьте
причину. Для уже работающей версии 4.5 новая миграция:
`2026_10_20_184031_add_chat_hash_to_telegram_connections.php`.
Она заполняет HMAC-индекс прежних зашифрованных чатов, сохраняя привязки,
ожидающие коды и receipts. Все предыдущие migrations тоже находятся в пакете;
Laravel применяет только ещё не выполненные.

Не используйте `first-install`, `migrate:fresh`, `db:wipe`, seed, новый APP_KEY
или восстановление старой БД поверх новых receipts как способ обновления.

После Laravel выполните шаги Deno и Telegram из **PRODUCTION.md**.
При последующей смене `TELEGRAM_WEBHOOK_TRANSPORT=deno` обязательно снова выполните
`php artisan config:clear` (либо пересоберите config cache).
Не используйте `drop_pending_updates=true`: инструкция сохраняет очередь.
Существующее ожидающее сообщение с истёкшим кодом получит объяснение ошибки;
пользователь создаёт новую ссылку подключения.

## Проверки исходной реализации

512 Laravel-тестов (5251 assertions), 182 Node-теста; отдельные проверки
входящего/исходящего моста, миграции, rollback, повторов и ошибок отправки.
Сборщик дополнительно запускает тесты и проверяет **сам упакованный runtime**
в отдельной временной SQLite: migrations, повторную установку с сохранением
APP_KEY, login/CSRF, клиентский доступ, экспорт и maintenance mode.

Production Deno → Laravel 200/403 подтверждены пользователем до реализации.
Новый путь Telegram → Deno → Laravel и сообщения бота нужно принять вручную
после обновления. Автоповторов подтверждения нет: ошибка отправки не отменяет
успешную привязку; её статус проверяется в приложении.
