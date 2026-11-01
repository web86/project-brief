# Project Brief v0.9.6 — релиз и проверки

Подготовлен локально 31.10.2026 (Europe/Istanbul). Ветка `codex/milestone-9-6-agent-api`, база — проверенный Milestone 9.5, commit `ebd8503`. Runtime собран из `6b4b49372af0f30abb8e483b525f0775d76fe815`; последующий commit фиксирует только отчёт и QA evidence. Push, deployment и изменения production DB не выполнялись.

## Итоговый артефакт

- ZIP: [project-brief-v0.9.6-6b4b493.zip](../../release/project-brief-v0.9.6-6b4b493.zip).
- Размер: **39,380,715 байт**.
- Сборка: 31.10.2026, 01:31:14 Europe/Istanbul; UTC `2026-10-30T22:31:14.643Z`.
- [SHA-256 sidecar](../../release/project-brief-v0.9.6-6b4b493.zip.sha256), [машинные метаданные](RELEASE-ARTIFACT.json).

```text
7ec9088747b47839547961d2f9886bd45640f14ba173aed094bbf2d162c05715
```

Архив создан только штатным `./bin/build-release`. Независимо проверены CRC каждого ZIP entry, все **8918** записей `checksums.txt` и совпадение включённого независимого агента с исходником. Canonical builder проверил production vendor, исключение локальных секретов/ключей/БД/QA/исходных карт и извлечённый runtime. API-документация и Node.js пример находятся в `agent-api/` вне web root. Tests, dev vendor и node_modules в ZIP отсутствуют.

Предыдущие локальные кандидаты v0.9.6 с другими commit suffix сохранены как QA-артефакты; итоговым является только архив выше.

## Что реализовано

Workspace-идентичность внешнего исполнителя без фиктивного пользователя/email, отдельные хэшированные credentials, пять ограниченных scopes, owner-only управление с существующим fresh confirmation, явные project grants и назначения через существующую команду. Суперадминистратор проектов может управлять назначениями только в доступных проектах, без секретов или глобальных настроек.

Agent API выдаёт минимальный контекст назначенной задачи. Отчёты, комментарии и файлы сохраняются с авторством агента; ссылки результатов и история остаются после удаления регистрации. Состояние исполнения отделено от статуса задачи и клиентского согласования. Начальный webhook содержит pending. Срок credentials с часовым поясом нормализуется в UTC; истёкший токен отклоняется.

Используется существующая подписанная outbox с SSRF-защитой, bounded retries, delivery ID и повторной проверкой доступа. При отзыве задания pending delivery бывшему агенту отменяется; события unassigned/cancelled могут получать отдельно разрешённые owner webhooks. Семантика подробно описана в [AGENT-WEBHOOKS.md](AGENT-WEBHOOKS.md).

Новый AI-провайдер, MCP-сервер, тарифы и биллинг не подключены. Shell/код из ответа агента на сервере не выполняется.

## Проверки

| Проверка                                        | Подтверждённый результат                                       |
| ----------------------------------------------- | -------------------------------------------------------------- |
| Полный backend, PHP 8.5.10 / SQLite             | 1013/1013, 15007 assertions                                    |
| Полный backend, PHP 8.5.10 / MariaDB 11.8.9     | 1013/1013, 15007 assertions                                    |
| Целевой PHP 8.4.26 / MariaDB 11.8.9             | 69/69 focused API/security/compatibility, 995 assertions       |
| Frontend suite                                  | 409/409                                                        |
| Pint, PHP lint, Prettier, production Vite build | Успешно                                                        |
| OpenAPI                                         | 6 endpoints, явные scopes, bearer auth, ошибки и idempotency   |
| GUI matrix                                      | RU/EN/TR/ZH-CN × 1440/390 × light/dark/system, 96 снимков      |
| Извлечённый итоговый ZIP                        | Реальный compiled UI + HTTP Agent API E2E на отдельной MariaDB |

Полный MariaDB suite запускается через `tests/agent96.mariadb.sh` с собственной копией приложения/storage, эксклюзивной одноразовой БД `projectbrief_m96_full_final_qa` и обязательными APP_ENV=testing, DB_CONNECTION=mysql, DB_HOST=127.0.0.1. Пароль передаётся из окружения QA. PHP 8.4 runner — `tests/agent96.php84.sh` в отдельном контейнере с read-only исходниками. Тестовый memory_limit=512M относится к тестовому процессу, не является замером Jino.

MariaDB выявила непереносимые старые test fixtures: слишком длинный task title, длинные имена prefixed индексов, rollback исторической схемы при наличии более поздних FK и метрики, переживающие transaction через отдельное соединение. Исправлены только соответствующие fixtures/изоляция тестов. Legacy migration test проверяет новый nullable agent FK отдельно и сохраняет все прежние значения. Производственные ограничения task title и существующие бизнес-правила не расширялись.

## Независимый агент и реальные HTTP-запросы

Итоговый ZIP извлечён в отдельную директорию. Владелец через настоящий UI создал агента/токен, добавил его в Team и назначил задачу. Node.js пример запущен отдельным процессом **из файла внутри ZIP**, используя только документированный REST API, без репозиторных imports, AI-ключей или платного API.

Подтверждено: чтение разрешённого описания, агентский комментарий/отчёт и авторство в UI, сохранение статуса задачи/клиентского согласования; шесть параллельных POST с одним idempotency key создали один отчёт; multipart upload и разрешённый человеку download; 403 для generic integration API, 404 для неназначенной/чужой проектной задачи; немедленный отказ после снятия назначения; 409 для старого assignment generation после повторного назначения; реальная ротация (старый bearer — 401, новый — 200); отключение агента и отказ независимому executor с 401.

Локальный TLS receiver проверил HMAC по raw bytes и timestamp, получил 503, затем 204 с теми же body/delivery ID. После отзыва доступа следующая доставка отменена. Для маршрутизации на локальный receiver и доверия тестовому CA используются только QA bindings/middleware в одноразовой копии; production SSRF/TLS-код не ослаблялся. Backend suite отдельно проверяет private/reserved/rebinding destinations и retry bounds.

Canonical builder дополнительно прошёл существующие packaged REST/scopes/idempotency, SSO, invitations, platform isolation, files, quiet cron, migrations/maintenance и signed WebAuthn regression gates. Сводные результаты без секретов — [QA.json](QA.json). Скриншоты остаются вне Git в `/tmp/project-brief-m96-qa/`; визуально проверены desktop/mobile, light/dark, длинные строки, карточки и modal. Исправлены слипавшиеся метаданные и растянутый checkbox; horizontal overflow, html lang/theme и Escape проверяются автоматически.

## Обновление и пределы проверки

Обновление и согласованный rollback Jino: [PRODUCTION.md](PRODUCTION.md), общий hosting preflight — [DEPLOY-JINO.md](../../DEPLOY-JINO.md). Additive migration намеренно не выполняет down. Нельзя запускать старый runtime на новой схеме без совместимого forward fix либо согласованного восстановления пары runtime + DB + storage с прежним APP_KEY.

Jino/FPM, фактическая hosting load/capacity, TLS/secure cookies/private storage конфигурация на Jino и восстановление настоящего production backup не проверялись. Новый worker/daemon/частый polling не добавлен; используется прежний ограниченный integrations:webhooks cron. Нагрузочные результаты локального MariaDB не объявляются production measurements. Доставка внешнему коммерческому AI-провайдеру не запускалась.

390 px — эмуляция Chromium, не физическое мобильное устройство; Safari/WebKit и аппаратные Passkey здесь не проверялись. Signed WebAuthn использует тестовые ключи/fixtures. Существующая внешняя SMTP/Telegram/push доставка не объявляется проверенной по локальным mocks/runtime тестам.
