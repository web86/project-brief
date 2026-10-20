# Telegram: настройка Milestone 4.5

Один бот обслуживает платформу. Привязки персональные: `User` администратора либо
`ProjectClient` конкретного проекта. Секреты и chat ID не передаются frontend.
Chat ID шифруется Laravel `APP_KEY`; ключ приложения нужно сохранять при обновлениях.

## Перед запуском

В API требуется применить миграцию:

```sh
cd api
php artisan migrate
php artisan config:clear
```

Миграция `2026_10_20_161516_add_client_communications.php` добавляет персональные
предпочтения клиентов, `client_comment_reads`, `telegram_connections` и
`telegram_updates`. Старые `comment_reads`, настройки Email/Push и история
доставок сохраняются. Новое поле `telegram` в каждом событии по умолчанию `false`;
оно добавляется при чтении настроек, без перезаписи сохранённой матрицы.

Публичный `APP_URL` должен указывать на HTTPS-адрес приложения: из него строятся
кнопки перехода. Клиентская ссылка доступа не добавляется в сообщения.
Если клиентская сессия истекла, пользователь открывает свою прежнюю персональную
ссылку и затем возвращается к задаче или обсуждению.

## Сначала direct и диагностика с Jino

Добавьте значения только в серверные secrets / `api/.env`:

```dotenv
TELEGRAM_TRANSPORT=direct
TELEGRAM_BOT_TOKEN=<token от BotFather>
TELEGRAM_BOT_USERNAME=<username бота без @>
TELEGRAM_WEBHOOK_SECRET=<отдельная случайная hex-строка, минимум 32 символа>
```

`TELEGRAM_WEBHOOK_SECRET` должен соответствовать ограничению Telegram:
латинские буквы, цифры, `_` и `-`. Удобно сгенерировать 32 случайных байта в hex.
Ни токен, ни секрет не должны иметь префикс `VITE_`.

План диагностики без сети и БД:

```sh
cd api
php artisan telegram:check
```

На Jino вручную выполните:

```sh
php artisan telegram:check --live
```

Это один `getMe`, без сообщения пользователю, повторов и вывода токена. Успех
проверяет доступность Bot API с данного сервера, но **не подтверждает доставку**.
Timeout запроса — 8 секунд, подключения — 3. Редиректы запрещены, домен и методы
фиксированы в коде. Проверка TLS остаётся включённой.

Не меняйте транспорт автоматически при ошибке. Сначала выясните причину
`telegram_unconfigured`, `telegram_unauthorized` или `telegram_unavailable`.

## Webhook и регистрация

Webhook в обоих транспортных режимах принимает **Laravel**:

```text
POST https://<app-host>/api/telegram/webhook
X-Telegram-Bot-Api-Secret-Token: <TELEGRAM_WEBHOOK_SECRET>
```

Это отдельный stateless-маршрут: без session и CSRF, с обязательной проверкой
секрета, лимитом размера и собственным rate limiter. Общие клиентские API остаются
под проверкой сессии/CSRF. В режиме Deno мост обслуживает только исходящие запросы;
переадресация webhook через AI-маршрут не требуется и не реализована.

Зарегистрируйте webhook через метод `setWebhook`, передав `url`, `secret_token`
и `allowed_updates: ["message"]`. Не передавайте `drop_pending_updates: true`
без отдельного решения оператора: ожидающие события удалять не нужно.

Пример регистрации из доверенного окружения с **уже заданными** секретными env
`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET` и `APP_URL`. Значения не печатаются
и не попадают в аргументы запуска. Команда ниже — инструкция для оператора;
в рамках разработки она не запускалась.

```sh
python3 - <<'PY'
import json, os, urllib.request
try:
    token = os.environ['TELEGRAM_BOT_TOKEN']
    body = json.dumps({
        'url': os.environ['APP_URL'].rstrip('/') + '/api/telegram/webhook',
        'secret_token': os.environ['TELEGRAM_WEBHOOK_SECRET'],
        'allowed_updates': ['message'],
    }).encode()
    request = urllib.request.Request(
        'https://api.telegram.org/bot' + token + '/setWebhook',
        data=body, headers={'Content-Type': 'application/json'}, method='POST')
    with urllib.request.urlopen(request, timeout=8) as response:
        result = json.load(response)
    print('Webhook registered' if result.get('ok') is True else 'Registration failed')
except Exception:
    print('Registration failed; check server configuration without printing secrets')
    raise SystemExit(1)
PY
```

Если Jino не может обратиться к Bot API, регистрацию можно выполнить из отдельного
доверенного операторского окружения. Адрес webhook при этом остаётся Laravel.
Не размещайте токены в ссылках тикетов, скриншотах, истории команд и логах proxy.

## Необязательный Deno transport

Изолированный entry point: [deployment/telegram-bridge.mjs](../../deployment/telegram-bridge.mjs).
Это самостоятельный сервис; он не читает Groq/AI env, не принимает `/ai`,
не пересылает AI payload и не использует AI-авторизацию.

Deno secrets:

```dotenv
TELEGRAM_BOT_TOKEN=<тот же бот>
TELEGRAM_BRIDGE_SECRET=<новый независимый случайный секрет, минимум 32 символа>
```

Laravel secrets:

```dotenv
TELEGRAM_TRANSPORT=deno
TELEGRAM_BOT_USERNAME=<тот же username>
TELEGRAM_WEBHOOK_SECRET=<секрет webhook>
TELEGRAM_BRIDGE_URL=https://<telegram-service>.deno.dev/telegram/send
TELEGRAM_BRIDGE_SECRET=<тот же отдельный секрет моста>
```

При `deno` токен бота в Laravel не требуется. Поддерживаются HTTPS-хосты в
`*.deno.dev` / `*.deno.net`, точный путь `/telegram/send`, без query, fragment,
userinfo и явного порта. Серверная конфигурация не берётся из клиентского запроса.
Передавайте точный URL своего сервиса, не URL AI-провайдера.

Мост принимает только `POST /telegram/send`, Bearer-авторизацию и методы
`sendMessage` / `getMe`. Исходящий адрес всегда `api.telegram.org`.
Лимит тела — 24 КБ, timeout Telegram — 6 секунд, редиректы запрещены.
В Laravel возвращаются только `ok` и числовой `error_code`, без содержимого чата,
пользовательского сообщения и текстовых описаний ошибок Telegram.

После изменения config повторите `config:clear`, `telegram:check` и одиночную
`telegram:check --live` на Jino. Развёртывание Deno в рамках этого изменения
не выполнялось.

## Подключение получателя

1. Администратор открывает «Уведомления проекта», клиент — собственные
   «Уведомления» через правое меню.
2. «Подключить Telegram» создаёт одноразовую ссылку с 256-битным кодом на 10 минут.
   В БД сохраняется только SHA-256 кода. Новая ссылка заменяет старую ожидающую.
3. Пользователь открывает бота и нажимает Start. Принимаются только личные чаты,
   где Telegram `from.id` совпадает с `chat.id`.
4. Backend повторно проверяет активность получателя и доступ клиента, атомарно
   погашает код и привязывает chat ID. Бот подтверждает подключение на RU/EN.
5. Вернувшись, пользователь видит новый статус при фокусе окна либо нажимает
   «Обновить статус». «Проверить Telegram» делает одну тестовую отправку
   исключительно в собственный связанный чат.
6. Администратор включает Telegram для нужных событий в матрице проекта.
   Клиент может выключить собственный канал независимо от этой матрицы.

Перепривязка сохраняет прежний активный чат до успешного погашения нового кода.
Отключение удаляет активную привязку и отменяет ожидающий код. Повтор одного
`update_id` и повтор погашенного кода не меняют привязку и не отправляют повторное
подтверждение. Webhook не принимает произвольный клиентский chat ID для доставки.

## Доставка и проверка

Используется `ProjectNotificationService` и прежняя таблица дедупликации.
Для новых общих комментариев добавлены `client.project_comment_created`
(администраторам) и `admin.project_comment_created` (активным клиентам).
Прежние события задач сохраняют направление доставки.

Перед доставкой проверяются активность проекта, доступ получателя, персональные
предпочтения и отсутствие самоуведомления по личности / совпадающему email.
Telegram также требует активную привязку и включённый канал события.
Каждое сообщение строится отдельно на RU/EN; цены, часы, технические заметки,
служебные идентификаторы и токены доступа в его текст не включаются.
Пользовательский текст ограничивается и экранируется для Telegram HTML.
Предпросмотр ссылок отключён.

429 возвращает `telegram_rate_limited`, 401 — `telegram_unauthorized`,
400 — `telegram_chat_unavailable`, недоступность — `telegram_unavailable`.
403 отключает соответствующую заблокированную привязку; более новая привязка
защищена атомарным сравнением. У события не бывает бесконечных повторов:
автоматических retry/fallback нет, неуспешная попытка записывается с безопасным
кодом ошибки. После исправления причины оператор может отправить отдельный тест.

Для **реальной** приёмки нужны тестовый бот, публичный webhook, два клиента и
администратор. Проверьте привязку, ответ бота, перепривязку и отключение; включите
события, создайте комментарии/изменение статуса, проверьте фактическое получение,
язык и переход по inline-кнопке. Отдельно повторите для direct с Jino и для Deno,
проверьте blocked chat и повтор webhook. HTTP `ok: true` означает принятие Telegram,
а визуальное получение подтверждается на устройстве получателя.

В этой работе использовались mocks; реальные credentials не предоставлены,
регистрация webhook и реальная Telegram E2E-доставка **не выполнялись**.

Контракт сверен с официальной документацией:
[Bot API](https://core.telegram.org/bots/api),
[deep links](https://core.telegram.org/bots/features#deep-linking).
