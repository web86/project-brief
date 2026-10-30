# Project Brief 0.9.1 — Jino deployment и rollback

Это инструкция для отдельного согласованного deployment. В рамках Milestone 8.5 production DB, Jino/FPM, remote main и live deployment не изменяются. Общая раскладка private/public и cron остаются из DEPLOY-JINO.md.

1. До окна обслуживания проверить SHA-256 поставленного ZIP по SHA256SUMS.txt и CRC архива. Сделать согласованные backup production DB, `.env`, `APP_KEY`, private storage и текущего private/public runtime. Сохранить предыдущий архив 0.9.0; не копировать QA/test files на хост.
2. На копии production DB проверить PHP 8.4/extensions, MySQL/MariaDB и применимость миграций. Если база старше 0.9.0, учесть все промежуточные миграции и раздел platform administration в DEPLOY-JINO.md. Полномочия platform admin автоматически не выдаются. Live hosting preflight и фактическая SMTP-доставка требуют отдельной проверки на Jino.
3. Включить штатное maintenance (`php artisan down`), установить private/public из 0.9.1, сохранив `.env`, `APP_KEY`, persistent storage и права файлов. Не использовать `migrate:fresh`, uninstall или пересоздание пользователей.
4. Выполнить `php artisan migrate --force --no-interaction`, затем `php artisan optimize:clear` и штатные cache-команды из DEPLOY-JINO.md. Новая миграция `2026_10_30_054311_add_administrator_management_fields` добавляет nullable имя в membership и sent_at в invitation, заполняя sent_at из created_at. На большой таблице приглашений проверить время ALTER/backfill на копии. Существующие email, токены, TTL и project permissions сохраняются.
5. Проверить `/api/health` и вход владельца с TOTP/Passkey. Владелец видит «Администраторы», разработчик/клиент не открывают раздел/API. Проверить смену workspace, существующие приглашения, приглашение без проектов, duplicate email, права двух проектов, удаление только одного назначения и owner protection. Деактивация отзывает назначения/приглашения текущего workspace; аккаунт и доступ к другим workspace сохраняются.
6. Проверить несколько SSO-источников и их ключи, Origin, автоматические/ручные проекты, вход тестового клиента через **два HTTPS-сайта**, отзыв источника/ключа/прав клиента и Magic Link fallback. Секрет ключа не копировать в URL, localStorage, HTTP/APM logs. Проверить RU/EN/TR/ZH-CN и mobile drawer.
7. Выполнить `php artisan up`, проверить обычные проекты/комментарии/экспорт, почту, webhook worker и действующий cron. Нового cron нет. Проверки локального ZIP не являются live-проверкой Jino.

## Rollback приложения

Включить maintenance, остановить запись и вернуть проверенные private/public 0.9.0. Сохранить `.env`, `APP_KEY`, storage и additive schema; выполнить optimize:clear и штатные cache-команды старого runtime, затем up. **Не выполнять migrate:rollback**: это удалит локальные имена и информацию об отправке. Старый runtime игнорирует новые nullable поля. Созданные в 0.9.1 User/membership/permissions/SSO sources сохраняются. Старый UI требует проект для нового приглашения, но существующие workspace-only приглашения могут быть приняты через прежние ссылки. Изменения доступа, включая деактивацию, откатом runtime не отменяются.

Восстановление всей БД из backup — отдельный согласованный disaster recovery с учётом новых данных, не обычный application rollback. Не удалять integration sources, provenance, клиентские binding или данные platform administration.
