# Milestone 4.5.1 — временная диагностика Deno → Laravel

Это **первый этап**, до реализации входящего моста. Он не меняет Telegram webhook,
привязки, секреты, Laravel и существующий `/telegram/send`. Проверка должна быть
выполнена из опубликованной версии существующего Deno-сервиса, затем её результат
нужно передать для продолжения Milestone 4.5.1.

## Что подготовлено

В `deployment/telegram-bridge.mjs` добавлен временный:

```text
POST https://projectbrief-telegram-bridge.web86.deno.net/telegram/diagnostics
Authorization: Bearer <существующий TELEGRAM_BRIDGE_SECRET>
Content-Type: application/json

{}
```

Endpoint существует только при `TELEGRAM_DIAGNOSTICS=1` в **Deno**. Если флага нет,
он равен `0` или любому значению кроме строки `1`, маршрут возвращает 404. При
неверном Bearer или отсутствующем/коротком серверном секрете возвращается 401.
Тело может быть пустым либо `{}`; аргументы, query-параметры и тела более 1024 байт
отклоняются. Ни URL, ни метод, ни payload из клиентского запроса не используются.

Два фиксированных запроса выполняются параллельно непосредственно Deno:

| Имя       | Запрос                                                          | Ожидается |
| --------- | --------------------------------------------------------------- | --------- |
| `health`  | GET `https://brief.web86.site/api/health`                       | 200       |
| `webhook` | POST `https://brief.web86.site/api/telegram/webhook`, JSON `{}` | 403       |

Ни `Authorization`, ни `X-Telegram-Bot-Api-Secret-Token`, ни bot token к Laravel
не передаются. Подтверждение боту не отправляется. На каждый запрос установлен
таймаут 8000 мс; повторов нет, перенаправления не выполняются. При 3xx возвращается
сам полученный HTTP-статус, адрес Location не раскрывается. Содержимое ответов не
читается: response stream отменяется без ожидания его завершения.
`durationMs` измеряет время до получения заголовков либо сетевой ошибки.

Ответ диагностического endpoint — HTTP 200 с `checks`, в том числе при ошибке
одной из проб. `ok` равен `true` **только** для сочетания 200/403. Пример формы
ответа, не результат выполненной проверки:

```json
{
  "ok": true,
  "checks": [
    { "name": "health", "status": 200, "durationMs": 120, "errorCode": null },
    { "name": "webhook", "status": 403, "durationMs": 150, "errorCode": null }
  ]
}
```

При сетевой ошибке `status: null`, а `errorCode` — один из `timeout`, `dns_error`,
`tls_error`, `connection_error`, `network_error`. Ни текст исключения, ни заголовки,
ни содержимое серверного ответа не возвращаются и не записываются приложением в log.

## 1. Обновить существующий Deno-сервис вручную

Push и deployment агент не выполнял. На этой стадии не нужны изменения Laravel
`.env`, Telegram webhook или production secrets.

1. В Deno Deploy откройте **существующее** приложение, обслуживающее
   `projectbrief-telegram-bridge.web86.deno.net`. Сохраните ID текущей production
   версии и её entrypoint для отката.
2. В его Deno environment добавьте только несекретный флаг
   `TELEGRAM_DIAGNOSTICS=1` для production runtime. Существующие
   `TELEGRAM_BRIDGE_SECRET` и `TELEGRAM_BOT_TOKEN` сохраните без изменений.
3. Замените код runtime entrypoint содержимым локального
   `deployment/telegram-bridge.mjs`. Сохраните существующие домен и настройки
   приложения. Если имя entrypoint отличается, сохраните его прежнее имя;
   содержимое файла подходит и для `.mjs`, и для `.ts`.
4. Создайте/примените новую production-версию тем способом, которым обслуживается
   текущий проект. Не создавайте отдельное приложение с другим URL.

Если используете CLI, загружайте из отдельной папки **только этот файл**,
без Laravel `.env`, `.git`, uploads и других файлов репозитория. Например, при
настроенном runtime entrypoint `telegram-bridge.mjs`:

```sh
telegram_diag_dir=$(mktemp -d /tmp/projectbrief-telegram-diagnostics.XXXXXX)
cp deployment/telegram-bridge.mjs "$telegram_diag_dir/telegram-bridge.mjs"
cd "$telegram_diag_dir"
```

В актуальном Deno Deploy CLI для существующего приложения:

```sh
# Подставьте реальную организацию из настроек Deno Deploy.
# Проверьте entrypoint существующего приложения до публикации.
deno deploy --org YOUR_ORGANIZATION --app projectbrief-telegram-bridge --prod
```

Для **Deno Deploy Classic**, если текущий проект обслуживается `deployctl`:

```sh
deployctl deploy --prod --project=projectbrief-telegram-bridge ./telegram-bridge.mjs
```

Используйте вариант своей платформы и её существующую авторизацию. Значения
секретов не передавайте аргументами CLI. Если публикация привязана к GitHub,
для push этой ветки нужно отдельное разрешение; текущая локальная подготовка
не публиковала её.

Варианты CLI сверены с официальными инструкциями:
[deno deploy](https://docs.deno.com/runtime/reference/cli/deploy/),
[Deploy Classic deployments](https://docs.deno.com/deploy/classic/deployments/).

## 2. Запустить проверку с Mac

Сначала проверьте защиту без секрета:

```sh
curl --silent --show-error --connect-timeout 5 --max-time 12 \
  --output /dev/null --write-out '%{http_code}\n' \
  --request POST --header 'Content-Type: application/json' --data '{}' \
  https://projectbrief-telegram-bridge.web86.deno.net/telegram/diagnostics
```

Ожидается **401**. **404** означает старую версию либо выключенный флаг.

Для авторизованной проверки вставьте следующий блок в обычный Mac Terminal.
Он спросит **существующий `TELEGRAM_BRIDGE_SECRET`**, скроет ввод и не сохранит
его в файле, истории команд или аргументах процесса. Bot token вводить не нужно.
Результат содержит только известные безопасные поля. Скрипт не следует редиректам.

```sh
python3 - <<'PY'
import getpass, json, urllib.error, urllib.request

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

try:
    secret = getpass.getpass('TELEGRAM_BRIDGE_SECRET (hidden): ')
    request = urllib.request.Request(
        'https://projectbrief-telegram-bridge.web86.deno.net/telegram/diagnostics',
        data=b'{}', method='POST', headers={
            'Authorization': 'Bearer ' + secret,
            'Content-Type': 'application/json',
        })
    with urllib.request.build_opener(NoRedirect).open(request, timeout=20) as response:
        raw = response.read(4097)
    if len(raw) > 4096:
        raise ValueError('Unexpected response size')
    result = json.loads(raw)
    allowed = {'timeout', 'dns_error', 'tls_error', 'connection_error', 'network_error', None}
    checks = []
    for row in result['checks']:
        if row['name'] not in ('health', 'webhook') or row['errorCode'] not in allowed:
            raise ValueError('Unexpected diagnostic fields')
        if row['status'] is not None and not isinstance(row['status'], int):
            raise ValueError('Unexpected status')
        if not isinstance(row['durationMs'], (int, float)):
            raise ValueError('Unexpected timing')
        checks.append({key: row[key] for key in ('name', 'status', 'durationMs', 'errorCode')})
    print(json.dumps({'ok': result.get('ok') is True, 'checks': checks}, indent=2))
except urllib.error.HTTPError as error:
    print('Diagnostics endpoint HTTP ' + str(error.code))
    raise SystemExit(1)
except Exception:
    print('Mac request failed or unexpected diagnostics response; no response details printed')
    raise SystemExit(1)
PY
```

Пришлите JSON `ok`/`checks`. Не присылайте сам секрет, токен бота или снимки экрана
со значениями environment variables. Логи `curl -v`, трассировка заголовков и
печать `.env` для этого не нужны.

Сочетание 200/403 доказывает доступность HTTPS и неавторизованного POST из Deno.
Оно ещё не проверяет обработку валидного `/start`, БД и задержку отправки
подтверждения. После результата продолжим именно эти проверки и входящий мост.
При timeout/сетевой ошибке/неожиданных HTTP-статусах webhook переключать нельзя.

## 3. Сразу отключить временный endpoint

После получения результата удалите `TELEGRAM_DIAGNOSTICS` из Deno environment
либо установите `TELEGRAM_DIAGNOSTICS=0` и примените environment к текущей версии.
Повторный POST должен возвращать **404**, в том числе с правильным Bearer.
Отсутствующий флаг по умолчанию полностью закрывает маршрут до запуска проб.
Позже временный код можно удалить отдельным изменением.

Для полного отката выберите сохранённую предыдущую production-версию Deno и
удалите диагностический флаг. Существующие `TELEGRAM_BRIDGE_SECRET` и
`TELEGRAM_BOT_TOKEN` не меняйте. Telegram webhook и ожидающие updates на этом
этапе остаются в прежнем состоянии: `setWebhook`, `deleteWebhook` и
`drop_pending_updates` не используются.
