# Milestone 4.5.1 — ручное обновление production

Подготовлено от `b1b0e73` в ветке
`codex/milestone-4-5-1-bidirectional-telegram-bridge`.
Все действия ниже выполняет оператор. Агент не делал push, deployment,
регистрацию webhook или GitHub Release.

Бот: **@BriefWeb86Bot**. Схема после переключения:

```text
Telegram → https://projectbrief-telegram-bridge.web86.deno.net/telegram/webhook
         → https://brief.web86.site/api/telegram/webhook
Laravel  → https://projectbrief-telegram-bridge.web86.deno.net/telegram/send
         → Telegram Bot API
```

## 1. Сохранить состояние

Сохраните резервную копию БД, Laravel `.env` и текущей production-версии Deno.
Сохраните прежний URL webhook, его `secret_token` из своего хранилища секретов,
параметры доставки и исходное число `pending_update_count` командой из раздела 5
(выберите `info`). `getWebhookInfo` не возвращает сам секрет.

Сохраните существующие `APP_KEY`, `TELEGRAM_BRIDGE_SECRET` и bot token.
Нельзя очищать `telegram_updates`, `telegram_connections` или создавать новую БД.
Сохраните uploads и настройки Email/Push. Создайте **один новый независимый**
`TELEGRAM_FORWARD_SECRET` в менеджере секретов: 32 случайных байта в hex
(64 символа). Он должен отличаться от двух существующих секретов.
Webhook secret должен содержать 32–256 символов `A-Z a-z 0-9 _ -`.

Ни один шаг не использует `getUpdates`, `deleteWebhook` или сброс очереди.
`setWebhook` ниже явно задаёт `drop_pending_updates: false`.
Telegram хранит ожидающие updates ограниченное время, до 24 часов; смена URL
не продлевает этот срок. Источник: [Telegram Bot API](https://core.telegram.org/bots/api#getting-updates).

## 2. Обновить Laravel

Загрузите изменённые файлы из локального `api/` в соответствующие пути
**существующего** приватного Laravel-приложения на Jino
(`private/project-brief-app` в принятой структуре релиза):

```text
app/Console/Commands/CheckTelegram.php
app/Http/Controllers/TelegramController.php
app/Models/TelegramConnection.php
app/Services/TelegramLinking.php
app/Services/TelegramTransport.php
app/Services/TelegramWebhook.php                         ← новый
config/telegram.php
database/migrations/2026_10_20_184031_add_chat_hash_to_telegram_connections.php ← новая
```

Зависимости, маршруты и frontend не менялись. `.env.example` и
`.env.production.example` служат образцами; не заменяйте ими действующий `.env`.
В production должны оставаться `APP_DEBUG=false`, прежний `APP_KEY` и БД.

Сначала оставьте текущую входящую авторизацию:

```dotenv
TELEGRAM_TRANSPORT=deno
TELEGRAM_WEBHOOK_TRANSPORT=direct
TELEGRAM_BOT_USERNAME=BriefWeb86Bot
TELEGRAM_BRIDGE_URL=https://projectbrief-telegram-bridge.web86.deno.net/telegram/send
TELEGRAM_BRIDGE_SECRET=<прежний секрет исходящей отправки>
TELEGRAM_WEBHOOK_SECRET=<прежний секрет webhook>
TELEGRAM_FORWARD_SECRET=<новый независимый секрет>
TELEGRAM_DEFAULT_LOCALE=ru
```

Команды выполнять из каталога Laravel, где находится `artisan`:

```sh
php artisan config:clear
php artisan migrate --force
php artisan telegram:check
php artisan telegram:check --live
```

Новая миграция добавляет индексированный HMAC чата и заполняет его для прежних
зашифрованных привязок. Коды, даты, chat ID и receipts сохраняются. Если при
миграции возникла ошибка расшифровки, остановитесь и проверьте прежний `APP_KEY`;
не удаляйте записи. Команда без `--live` проверяет конфигурацию без сети/БД;
`--live` выполняет один исходящий `getMe`, без отправки сообщения.

Сбросьте OPcache/перезапустите PHP через штатную панель Jino, если там отключена
автоматическая проверка изменённых файлов. CLI `opcache_reset` не заменяет
перезапуск web PHP. Очередь задач и новый worker не требуются.

## 3. Обновить существующий Deno-сервис

В production environment **существующего** приложения
`projectbrief-telegram-bridge` задайте:

| Переменная                     | Значение / действие                                 |
| ------------------------------ | --------------------------------------------------- |
| `TELEGRAM_BOT_TOKEN`           | Сохранить прежний token @BriefWeb86Bot; только Deno |
| `TELEGRAM_BRIDGE_SECRET`       | Сохранить прежнее значение, совпадающее с Laravel   |
| `TELEGRAM_WEBHOOK_SECRET`      | Прежний Telegram webhook secret из раздела 2        |
| `TELEGRAM_FORWARD_SECRET`      | Новый секрет, точно такой же в Laravel              |
| `TELEGRAM_LARAVEL_WEBHOOK_URL` | `https://brief.web86.site/api/telegram/webhook`     |
| `TELEGRAM_DIAGNOSTICS`         | Удалить                                             |

Секреты вводите через защищённые настройки Deno, без значений в аргументах CLI,
истории shell, Git и сообщениях. Все три секрета имеют разные роли.
Bot token в Laravel для `TELEGRAM_TRANSPORT=deno` не нужен; удалите его оттуда,
если он остался, после проверки исходящего транспорта.

Замените **содержимое entrypoint** полным файлом
`deployment/telegram-bridge.mjs`. Предпочтительно оставить расширение `.mjs`.
Сохраните домен, production-приложение и существующую конфигурацию запуска.
Entry point должен запускаться как основной модуль, чтобы выполнялся `Deno.serve`.
В настройках запуска приложения укажите этот файл; не используйте его как
побочный import из другого entrypoint.

Можно загрузить единственный файл через настроенный Deno CLI с Mac:

```sh
cd /Users/sonoma/Desktop/project-brief
telegram_bridge_dir=$(mktemp -d /tmp/projectbrief-telegram-bridge.XXXXXX)
cp deployment/telegram-bridge.mjs "$telegram_bridge_dir/telegram-bridge.mjs"
cd "$telegram_bridge_dir"
# Укажите существующую организацию; runtime entrypoint приложения — telegram-bridge.mjs.
deno deploy --org YOUR_EXISTING_ORGANIZATION --app projectbrief-telegram-bridge --prod
```

Организация и entrypoint берутся из текущих настроек Deno. Не выполняйте
`deno deploy create`. CLI загружает только отдельную папку с этим файлом;
Laravel `.env`, исходники приложения и uploads не должны попасть в Deno.
Альтернатива — применить файл через уже используемый интерфейс публикации
этого приложения. [Документация Deno CLI](https://docs.deno.com/runtime/reference/cli/deploy/).

Повторите `php artisan telegram:check --live` на Jino: ожидается `Telegram getMe: OK`.
С Mac проверьте отсутствие доступа без секретов (эти команды ничего не отправляют боту):

```sh
curl -sS -o /dev/null -w 'send: %{http_code}\n' -X POST \
  https://projectbrief-telegram-bridge.web86.deno.net/telegram/send -d '{}'
curl -sS -o /dev/null -w 'webhook: %{http_code}\n' -X POST \
  https://projectbrief-telegram-bridge.web86.deno.net/telegram/webhook -d '{}'
curl -sS -o /dev/null -w 'diagnostics: %{http_code}\n' -X POST \
  https://projectbrief-telegram-bridge.web86.deno.net/telegram/diagnostics -d '{}'
```

Ожидаются **401, 401, 404**. Временного diagnostics endpoint больше нет в коде,
в том числе если старый флаг случайно остался равен `1`.

## 4. Включить пересылку в Laravel и проверить callback

В серверном Laravel `.env` измените **только входящий режим**:

```dotenv
TELEGRAM_WEBHOOK_TRANSPORT=deno
```

Затем на Jino:

```sh
php artisan config:clear
php artisan telegram:check
```

Ожидаются `Transport: deno`, `Transport configured.`, `Webhook transport: deno`,
`Webhook configured.` и код завершения 0. Laravel теперь принимает только
`X-Telegram-Forward-Secret`; прежний заголовок Telegram и Bearer исходящей
отправки не дают доступа. Для альтернативного direct-режима требуется явное
`TELEGRAM_WEBHOOK_TRANSPORT=direct`; смешанной авторизации нет.

Прежде чем менять реальный webhook, выполните с Mac следующую **безопасную пробу**.
Она отправит через Deno synthetic update без сообщения. Laravel его проигнорирует,
не создаст receipt/привязку и не отправит ответ в Telegram.
Секрет вводится скрыто, в командную строку не попадает.

```sh
python3 - <<'PY'
import getpass, json, time, urllib.request, urllib.error
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None
secret = getpass.getpass('TELEGRAM_WEBHOOK_SECRET: ')
request = urllib.request.Request(
    'https://projectbrief-telegram-bridge.web86.deno.net/telegram/webhook',
    data=b'{"update_id":0}', method='POST',
    headers={'Content-Type':'application/json', 'X-Telegram-Bot-Api-Secret-Token':secret})
started = time.monotonic()
try:
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
        data = json.loads(response.read(1025))
        print(json.dumps({'status':response.status, 'ok':data.get('ok') is True,
                          'durationMs':round((time.monotonic()-started)*1000)}))
except urllib.error.HTTPError as error:
    print(json.dumps({'status':error.code, 'ok':False}))
    raise SystemExit(1)
except Exception:
    print('Probe failed (network or invalid response); no private details printed')
    raise SystemExit(1)
PY
```

Ожидается **status 200, ok true** за время меньше 8000 мс. При ошибке не переходите
к регистрации: проверьте пару forward secrets, режим Laravel, миграцию,
production-переменные Deno и OPcache. После исправления повторите пробу.
Во время этого короткого переключения старый прямой webhook получает 403;
это не успешное подтверждение доставки, pending updates не сбрасываются.

## 5. Зарегистрировать webhook и проверить очередь

С Mac запустите один и тот же блок: сначала с действием `info` (снимок до
обновления), после успешной пробы раздела 4 — с `set`, затем снова с `info`.
Token вводится через `getpass`; полный ответ Bot API и текст ошибок не печатаются.
`set` — **единственный шаг, изменяющий настоящий Telegram webhook**.

```sh
python3 - <<'PY'
import getpass, json, urllib.request
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None
try:
    action = 'info'  # Для регистрации после раздела 4 замените на 'set'.
    if action not in ('info', 'set'):
        raise ValueError()
    token = getpass.getpass('TELEGRAM_BOT_TOKEN: ')
    body = {}
    method = 'getWebhookInfo'
    target = 'https://projectbrief-telegram-bridge.web86.deno.net/telegram/webhook'
    if action == 'set':
        method = 'setWebhook'
        body = {'url':target, 'secret_token':getpass.getpass('TELEGRAM_WEBHOOK_SECRET: '),
                'allowed_updates':['message'], 'max_connections':5,
                'drop_pending_updates':False}
    request = urllib.request.Request('https://api.telegram.org/bot'+token+'/'+method,
        data=json.dumps(body).encode(), method='POST', headers={'Content-Type':'application/json'})
    with urllib.request.build_opener(NoRedirect()).open(request, timeout=12) as response:
        data = json.loads(response.read(16384))
    if data.get('ok') is not True:
        raise ValueError()
    if action == 'set':
        print('Webhook registered; pending updates preserved')
    else:
        result = data['result']
        print(json.dumps({
            'webhook_is_deno':result.get('url') == target,
            'webhook_is_laravel':result.get('url') == 'https://brief.web86.site/api/telegram/webhook',
            'pending_update_count':result.get('pending_update_count'),
            'last_error_date':result.get('last_error_date'),
            'has_last_error':bool(result.get('last_error_message')),
            'max_connections':result.get('max_connections'),
            'allowed_updates':result.get('allowed_updates'),
        }))
except Exception:
    print('Telegram operation failed; no token, URL or response details printed')
    raise SystemExit(1)
PY
```

Для чтения оставьте `action = 'info'`; для регистрации после раздела 4
замените на `action = 'set'`. `getpass` читает секрет из терминала Mac
(`/dev/tty`). Выполняйте в обычном Terminal, без записи терминальной сессии.

`max_connections=5` уменьшает параллельную нагрузку на Jino. Telegram повторяет
доставку при неуспешном HTTP-ответе; локальных повторов моста нет.
Смена `allowed_updates` не отбрасывает уже созданные updates других типов:
такие типы Laravel примет и проигнорирует. [Контракт setWebhook](https://core.telegram.org/bots/api#setwebhook).

Повторный `info` должен показать `webhook_is_deno: true` и уменьшение очереди.
Старый `last_error_date` может оставаться; следите за отсутствием **новых** ошибок.
Ожидающая команда с уже истёкшим кодом получит понятный ответ об устаревшей ссылке,
не привязку задним числом. Пользователь создаёт новую ссылку в Project Brief.

## 6. Реальная приёмка и логи

Выполните на тестовых получателях после обновления:

1. `/start` в личном чате: приветствие RU для неизвестного пользователя.
2. Новая ссылка из Project Brief → Start: одна успешная привязка и подтверждение,
   обновление статуса в приложении, «Проверить Telegram» доставляет сообщение.
3. Уже использованная, неверная и просроченная (старше 10 минут) ссылка:
   одинаковое объяснение с предложением создать новую, без чужих данных.
4. Перепривязка: прежняя активна до успешной новой; отключение отменяет pending-код.
   Проверьте клиента и администратора, RU и EN; известный чат получает свой язык.
5. Включите Telegram для события проекта и создайте комментарий/изменение статуса.
   Проверьте фактическое сообщение, кнопку и прежние каналы Email/Push.
6. Группы, чужой `@username` и произвольные сообщения не получают ответов.
   HTTP повтор того же update **покрыт локальными тестами**; не подделывайте в
   production Start с настоящим одноразовым кодом ради проверки повторов.

Laravel пишет только стадии `telegram.webhook_received`, `start_recognized`,
`code_redeemed`, `code_rejected`, `start_processed`, `update_duplicate`,
`start_reply_sent`, `start_reply_failed`, `webhook_failed` с безопасными кодами.
Для успешных стадий нужен `LOG_LEVEL=info`; верните прежний уровень после проверки.
Ошибки — `warning`. Не включайте `APP_DEBUG`, запись request bodies, headers,
HTTP debug или экспорт полных логов с другими данными приложения.
Deno пишет `telegram_forward`, HTTP-статус Laravel, durationMs и безопасный код.

| Ответ Deno → Telegram | Значение                                                              |
| --------------------- | --------------------------------------------------------------------- |
| 200                   | Laravel вернул 2xx + `ok:true` после обработки/дедупликации           |
| 401                   | Неверный Telegram webhook secret                                      |
| 400 / 413             | Невалидный или превышающий 16 КБ update                               |
| 503                   | Неверная конфигурация Deno либо Laravel 429/503                       |
| 502                   | Сеть, redirect, прочая ошибка HTTP или неверное подтверждение Laravel |
| 504                   | Превышен deadline 8 секунд на callback и его тело                     |

Receipt и привязка фиксируются одной транзакцией. При сбое БД — rollback и
503 Laravel, Deno также не отвечает успехом. После commit подтверждение боту
выполняется один раз в Laravel termination; повтор update не повторяет отправку.
В web PHP-FPM Symfony завершает HTTP-ответ через `fastcgi_finish_request` перед
этим запросом, разрывая синхронную цепочку Laravel → Deno → Telegram.
В CLI `telegram:check` показывает fallback: это свойство CLI, не диагноз PHP-FPM.
Если web SAPI не поддерживает ранний flush, проверьте реальную задержку callback:
отправка остаётся ограничена 8 секундами, но ответ может задерживаться до неё.

Это сознательная семантика **не более одной попытки подтверждения**: при сетевой
ошибке, аварийном завершении PHP после commit или потере ответа Telegram бот может
не показать подтверждение. Привязка остаётся; проверьте статус в приложении и
выполните отдельный персональный тест. Автоповторы сообщения отсутствуют, поскольку
Bot API не предоставляет ключ идемпотентности для `sendMessage`. Ответ 403 Telegram
по-прежнему безопасно отключает заблокированную привязку.

## 7. Откат

Если исходящие сообщения работают, сохраните Deno и исправьте входящий конфиг:
успешно обработанные updates нельзя заставлять обрабатываться заново очисткой БД.
Для полного отката к прежней схеме:

1. Верните Laravel `TELEGRAM_WEBHOOK_TRANSPORT=direct`, выполните `config:clear`.
   Сохраните прежний `TELEGRAM_WEBHOOK_SECRET` и working outbound deno-настройки.
2. В операторском блоке `set` замените только `target` на сохранённый прежний
   URL и используйте прежний secret_token/параметры доставки. Оставьте
   `drop_pending_updates:False`. Прямой путь имел известный timeout, поэтому
   это откат конфигурации, а не подтверждение его работоспособности.
3. Только после переключения Telegram можно вернуть предыдущую версию Deno.
   Затем при необходимости вернуть PHP-код. Не запускайте `migrate:rollback`:
   дополнительный nullable-индекс совместим с прежним кодом. Не восстанавливайте
   старую БД поверх новых receipts/привязок: это отменит дедупликацию.
4. Повторите `getWebhookInfo` и исходящий `telegram:check --live`.

Тесты нового моста выполнены локально с fake HTTP/Fetch. Production Deno → Laravel
200/403 до реализации подтверждены пользователем; полный новый путь Telegram →
Deno → Laravel → ответ бота требует описанной ручной приёмки.
