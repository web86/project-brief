# Project Brief 0.8.0 — Integration Foundation: обновление и откат на Jino

База обновления: проверенная 0.7.8. Этот документ описывает подготовку; push, merge и deployment не выполняются без отдельного разрешения.

## Подготовка

1. Сверьте SHA-256/размер ZIP с внешним `RELEASE-ARTIFACT.json`, CRC и внутренним `checksums.txt`. Используйте весь комплект одной версии. Production ZIP содержит runtime, vendor, готовый frontend, инструкции установки/отката; тесты, скриншоты и PHP-пример интеграции остаются в репозитории.
2. Сделайте backup БД, public/private/vendor, `.env`, APP_KEY, storage/uploads/branding/sessions, deployed `.htaccess` и scheduler/cron. Сохраните независимый доступ для отката. Не меняйте origin/RP ID, MFA, passkeys, cookies и HTTP hardening flags. `HTTP_BOOTSTRAP_LIMIT_ENABLED` остаётся выключенным до отдельного Jino proxy/NAT/AllowOverride preflight.
3. На закрытом staging повторите обновление со своей 0.7.8 на фактических Jino PHP 8.4/MariaDB. Локальные PHP 8.5, SQLite/MariaDB 11.8.9, Chromium и SMTP-приёмник не подтверждают конфигурацию или доставку Jino.
4. Проверьте SMTP и HTTPS `APP_URL`/`FRONTEND_URL`: постоянные ссылки строятся только из доверенной конфигурации. Драйвер `log` не отправляет секреты и возвращает состояние приглашения `failed`. API-токен храните только на сервере внешней системы; в browser JavaScript его быть не должно.

## Обновление с 0.7.8

Остановите записи/прежний scheduler, дождитесь активных запросов и включите maintenance в private app: `php artisan down`. Замените public/private/vendor из ZIP по `DEPLOY-JINO.md`, сохраняя `.env`, APP_KEY, весь рабочий storage и maintenance marker. Не запускайте `first-install`, seed, `migrate:fresh`, `db:wipe`, `key:generate` или security recovery на рабочей базе.

```sh
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
php artisan migrate:status --no-interaction
php artisan account:security-diagnostics
```

Новая миграция `2026_10_29_092022_add_project_provisioning_foundation` добавляет:

- Nullable `projects.description`/`language`, `project_clients.workspace_client_id` и выключенный по умолчанию `integration_tokens.provisioning_enabled` для существующих токенов.
- `workspace_clients`: контакт/UUID, workspace, нормализованный email, его hash, имя, необязательные company/website. Уникальность `(workspace_id,email_hash)`. Это контактные данные, не учётная запись и не разрешение входа.
- `project_provisionings`: операция/UUID, workspace, integration token, hash внешнего ID/нормализованного тела, связи контакта/проекта/участия, состояние приглашения. Уникальность workspace/token/external hash. При окончательном удалении проекта связи обнуляются, запись-дедупликатор остаётся.
- `provisioning_operations`: последние 500 запросов с проверенным токеном на workspace: outcome, HTTP status, token/operation ID и timestamp. Тела запросов, email и секреты в журнал не копируются.

Backfill и массовое объединение клиентов не выполняются. Старые клиенты, роли, пользователи, доступы, scopes, replay hashes и webhook-настройки сохраняются. Существующие токены не получают новый scope. Новый токен с явно выбранным `projects:provision` включает автоматическое создание для своего workspace; владелец может выключить его независимо от отзыва токена.

Новых PHP/Node зависимостей, Redis, WebSocket, daemon или cron нет. По отдельному разрешению пользователя существующие Vue I18n и Vite обновлены до 11.4.14 и 7.3.7; patch-версии исправляют найденные npm advisories. Финальный npm audit полного/runtime набора: 0 vulnerabilities. Для webhook используется **прежняя** команда `integrations:webhooks` и её существующее расписание. Не добавляйте вторую cron-задачу. Семидневная очистка Live Sync и прежние уведомления продолжают работать.

## Smoke перед открытием

- Новый отдельный тестовый токен `projects:provision`: POST `/api/v1/projects/provision` с `externalId`, `client.name/email`, `project.name/language` и `sendInvitation`. Убедитесь, что проект создан только в его workspace, `projectUrl` — HTTPS `/project/{uuid}`, в ответе нет `/access/` или секретов. `language`: `ru`, `en`, `tr`, `zh-CN`.
- Повторить точное тело и `Idempotency-Key` → тот же результат, `Idempotency-Replayed: true`. Новый ключ с прежним ID/телом → 200, `created:false`. Изменённое тело с прежним ID → 409. Параллельные запросы не дают дублей. Сохраните токен, ID и ответ во внешнем заказе; замена токена создаёт новое пространство внешних ID, поэтому сначала сверяйте сохранённые связи.
- Без scope/при выключенном переключателе → 403; отозванный токен → 401. Попытка передать workspace/permissions → 422. Owner видит успех, ошибку, повтор и статус письма в «Последние операции»; developer/client не имеют доступа к настройкам.
- `sendInvitation:false` не создаёт токен входа. При рабочем SMTP приглашение → `sent`; временный отказ SMTP → `failed`, проект и постоянная ссылка сохраняются. Повтор API не рассылает письмо заново. Для явной отправки используйте прежнюю вкладку «Клиенты». `pending` возобновляется повтором; `sending` после сбоя процесса означает неподтверждённую отправку и требует явного решения владельца. Письмо не отправляется внутри транзакции бизнес-записей.
- Гость по `projectUrl` получает форму email, затем письмо и возврат в проект. Клиент с верной сессией открывает проект; сессия другого проекта получает отказ. Отключение участия немедленно блокирует доступ. Повторный вход по Magic Link работает; публичный запрос письма не отзывает уже проверенные сессии. Публичный login ограничен по IP и email/project и не раскрывает существование email/проекта. Отзыв одного access-link блокирует именно эту ссылку/сессию; для полного отзыва доступа отключайте клиента.
- Webhook `project.created` содержит project ID/URL/external ID; `project.invitation_changed` — состояние письма. Проверить подпись, фильтр проектов, прежние retry/history. События ожидают commit и отправляются прежним обработчиком; login-секретов и email в payload нет.
- Регрессия: старые v1 API, клиенты/приглашения, developer MFA/passkeys, проекты/задачи, trash/restore, обсуждения, PDF/DOCX, Live Sync, обе темы и четыре языка.

После smoke и разрешения выполните `php artisan up`, возобновите прежний scheduler и обновите вкладки, чтобы загрузились новые assets. Реальную доставку письма и webhook на внешний сервер проверьте отдельно на согласованном staging; не принимайте статус SMTP `sent` за подтверждение попадания в inbox.

## Откат

Выключите автоматическое создание/отзовите тестовые integration-токены и остановите запросы внешних систем. В maintenance верните точный комплект 0.7.8 public/private/vendor, сохраняя `.env`, APP_KEY, рабочий storage/branding/sessions, `.htaccess` и marker. Очистите/пересоберите caches старым кодом, выполните smoke и возобновите HTTP/прежнее расписание.

**Оставьте добавленную схему и данные.** 0.7.8 игнорирует новые таблицы/nullable-поля; новые проекты и обычные project-client membership остаются доступными по уже выданным ссылкам. В старом frontend нет новой формы входа по постоянному URL, поэтому для входа используйте существующий Magic Link или явное приглашение владельца. Scope `projects:provision` старый код не реализует; остановленные внешние вызовы не возобновляйте до повторного обновления.

Не выполняйте общий `migrate:rollback`: он может задеть прежние миграции и удалить критичные состояния. `down()` новой миграции допускает удаление только при пустых новых контактных/операционных привязках; при существующих данных он намеренно отказывает, сохраняя дедупликацию и контакты. Откат приложения не требует отката схемы. Уже отправленные письма/webhook нельзя отозвать, но доступ можно отключить прежними средствами. Возврат к backup БД — отдельная операция с учётом записей, созданных после backup.
