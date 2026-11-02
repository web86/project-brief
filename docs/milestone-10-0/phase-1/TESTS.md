# Проверки Phase 1

Локально, 02.11.2026. Production не использовался. Все данные MariaDB —
синтетические, контейнер одноразовый, database volume tmpfs. AI-запросов: **0**.
Никакие API/provider env-файлы для этих проверок не требуются и не читались.

## Результаты

| Проверка                                                        | Результат                                                                                |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| Backend целиком, PHP 8.5.10 / SQLite, memory_limit=512M         | 1089 tests, 1087 passed, 15965 assertions; 2 явных skip для MariaDB-only DDL/concurrency |
| Backend целиком, PHP 8.5.10 / MariaDB 11.8.9                    | 1089 passed, 16016 assertions                                                            |
| Phase 1, PHP 8.4.26 / MariaDB 11.8.9                            | 25 passed, 295 assertions; final FK/EXPLAIN/raw writes включены                          |
| Последний focused SQLite после дополнительных FK/SQL assertions | 25 tests, 23 passed, 240 assertions, 2 MariaDB-only skips                                |
| Frontend `npm test`                                             | 434 passed, 0 skipped                                                                    |
| Frontend `npm run build`                                        | Passed; существующее предупреждение о chunks >500 kB                                     |
| PHP syntax / Laravel Pint                                       | Passed                                                                                   |

Общий regression прогон выполнен до последних дополнительных FK для invitation/
outbox и усиления тестов raw child writes/EXPLAIN. После этих дополнений повторяется
узкий Phase 1 suite на PHP 8.4/MariaDB и SQLite. Existing runtime source, dependencies,
routes и frontend не изменялись; это не утверждение о новом production E2E.

## Что реально проверено

- Четвёртый/пятый plan, rename без изменения возможностей, create/revise/publish/
  nextDraft/archive, optimistic revisions, canonical JSON serialization/hash.
- Published parent/policy/value immutability через Eloquent и raw SQL, history
  retention и nullable actor anonymization, mandatory audit rollback.
- Pinned subscription/version/policy, extra seats 0/2/40, ceiling 50, owner count,
  agent exclusion. Complete occupied accounting пока не объявлен готовым.
- Free paid-only override rejection и повторная защита resolver от forged DB row;
  Agent API и full API независимы, explicit unlimited, unknown/missing keys,
  unsupported project scope, invalid Boolean/Quota/Enum, size bounds.
- Draft seed idempotent/inert, draft policy не запускает Trial. Unconfigured
  storage bytes/files независимы и не превращаются в 0/unlimited commercial quota.
- UTC микросекунды, 7×86400 seconds, Trial без Grace, paid Grace и точные `[start,end)`
  boundaries; expiry возвращает unavailable/fallback_required без выдуманного Basic.
- Cold resolver не более пяти запросов без query per entitlement.
- Composite FK не допускают чужую plan version или current period workspace.
- Fresh installation; upgrade, начиная с реальной pre-foundation схемы v0.9.10,
  с owner/admin/project/client, shared key у двух агентов, private run/result,
  независимым webhook и SSO source. Сравниваются hash **всех прежних колонок всех
  legacy таблиц** до/после migration и повторного запуска. После upgrade Agent API
  и private collaboration остаются доступными по прежним credentials/permissions.
- Синтетический crash после committed CREATE plan_versions. Повтор восстанавливает
  недостающие indexes/FK и завершает migration без создания subscriptions/backfill.
- MariaDB index/FK inventory и EXPLAIN для project counter access path.
- Реальные независимые PHP workers со стартовой barrier: publish vs publish с
  разными keys → один applied/один conflict; одинаковый key → один event/outcome;
  revise vs publish на одной revision → один applied/один conflict.

Не тестировались live deployment, production privileges/inventory, browser acceptance
нового UI (его нет), реальный AI/provider, capacity enforcement/storage IO/execution
barrier races. Их будущие contracts перечислены в [PHASE-2.md](PHASE-2.md).

## Воспроизведение

Из корня репозитория:

```sh
PHP_INI_SCAN_DIR=:$PWD/api/dev-php php -d memory_limit=512M api/vendor/bin/phpunit -c api/phpunit.xml --filter PlansFoundation
PHP_INI_SCAN_DIR=:$PWD/api/dev-php php -d memory_limit=512M api/vendor/bin/phpunit -c api/phpunit.xml
npm test
npm run build
```

Для MariaDB требуется **эксклюзивный disposable сервер**, не development/production
база: `projectbrief_agent_ux_full_qa` и `projectbrief_m10_upgrade_qa`.
Тест concurrency делает migrate:fresh первой БД; upgrade test очищает вторую.
Использовать только синтетические credentials. **Не запускать два прогона
параллельно на этих БД**, в том числе host и Docker против одного сервера.

Host MariaDB regression runner: `tests/agent-ux.mariadb.sh`, с APP_ENV=testing,
DB_CONNECTION=mysql, DB_HOST=127.0.0.1 и указанной первой БД. Порт/credentials
передаются через environment; скрипт создаёт application/storage copy в /tmp.

PHP 8.4 runner: `tests/plans-foundation.php84.sh`, внутри PHP 8.4 контейнера с
repo mounted read-only в `/workspace`, MariaDB на 127.0.0.1 в общей network
namespace. Аргументы `--filter PlansFoundation`; тот же exclusive database guard.
Требуются PDO MySQL/SQLite и обычные Laravel extensions. Для **всего** legacy
suite дополнительно нужен GD.

## Исправления и ограничения проверки

1. На первом тесте interrupted DDL обнаружено, что Laravel создаёт indexes/FK
   после CREATE отдельными командами. Исправлен idempotent repair каждого шага.
2. Новый future project-counter index конфликтовал с legacy SQLite rollback
   fixture, удаляющим deleted_at. Performance indexes ограничены target MariaDB;
   legacy rollback regression после исправления проходит.
3. Первый host full suite был остановлен PHP memory_limit=128M на существующем
   MIME fixture. Повтор с обычным для full suite 512M прошёл.
4. Попытка полного PHP 8.4 прогона: 1087 tests, 34 errors из-за отсутствия GD в
   готовом контейнере; 15672 assertions. Это **не green full PHP 8.4 regression**.
   Target Phase 1 проверен отдельно, весь suite — host PHP с GD + MariaDB/SQLite.
5. Один host/Docker прогон был запущен ошибочно параллельно против одной tmpfs DB;
   результаты отброшены, host process остановлен. Валидные результаты выше получены
   в последовательных exclusive MariaDB прогонах.
