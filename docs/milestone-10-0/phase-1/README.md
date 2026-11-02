# Milestone 10.0 — Phase 1

База: `origin/main` / `dbe18b2`, runtime **v0.9.10**. Рабочая ветка:
`codex/milestone-10-0-phase-1`. D1–D12 и восемь утверждённых архитектурных
документов сохраняются; этот каталог описывает реализованный первый этап.

## Граница реализации

Добавлены внутреннее доменное ядро и additive schema. Ни один существующий
controller, route, middleware, provider, command, login flow, scheduler или
`DatabaseSeeder` не вызывает новую систему. Публикация версии сама по себе
не назначает подписку, не запускает Trial, не включает ограничения и не
переписывает текущие workspace. `/platform` и прежняя авторизация сохранены.
Production БД не открывалась; push, deployment и production ZIP не выполнялись.

Registry содержит code-owned discriminated values boolean/integer/unlimited/
enum/quota. Unlimited — только `{"kind":"unlimited"}`. Произвольные ключи,
выражения, scopes и лишние поля отклоняются. Реализован только workspace scope;
project clients provider ещё не готов. Mirror definitions не используется как
исполняемая конфигурация. Новые тарифы используют существующие definitions,
без условий по именам Basic/Freelancer/Studio в runtime services.

`developer_seats_json` — единственная политика мест, `developers.max` вычисляется
из included + approved extra в пределах hard maximum 50. Отдельное editable
значение/override запрещено. Owner входит в observed distinct human members,
агенты исключены. Полный occupied capacity пока **incomplete**: invitation holds,
проверенные client identities и participation adapter подключаются позднее.

Agent API независим от полного API. Free не получает paid-only features через
plan configuration или overrides; resolver повторяет проверку. FormWatch
unavailable. Storage bytes/files — отдельные planned capacity definitions без
значений, не periodic quota; AI/run/check quotas также planned, без чисел.

## Доменный API

Все mutation services требуют существующего User с актуальным platform_admin и
совпадением с singleton primary PlanAuthority. Самоназначения primary нет.
Это внутренние методы, **не HTTP authorization adapter**: будущие endpoints
должны дополнительно использовать существующие MFA/strong/fresh proof, CSRF,
revoked-session checks и authority revision. Catalog authority не даёт доступа
к содержимому проектов.

- `PlanCatalog`: create/revise draft, revision check, publish, nextDraft, archive;
  typed transition rules с published endpoints и revision. Правила только
  сохраняются; executor отсутствует. `block_growth` для active overage запрещён.
- `PlatformPolicyCatalog`: draft save/revise и immutable publication. Trial target
  должен быть published paid, fallback — published free, оба не archived.
  Публикация не активирует `plan_settings.current_policy_version_id`.
- `SubscriptionAssignments::pin`: явное **первоначальное** ручное закрепление
  published версии, Observe mode; повторное назначение отклоняется. Paid period
  требует опубликованной pinned policy и явной длительности. Это не payment.
  Compatibility assignment, renewal, Trial activation и transitions отложены.
- `SubscriptionAssignments::override`: revision, bounded typed value, paid gate,
  конечный срок внутри доступного period segment, запрет overlap под workspace lock.
- `SubscriptionClock`: чистые UTC интервалы `[start,end)`, ровно N×86400 секунд.
  Trial не имеет Grace, paid имеет отдельную Grace; на конце —
  `fallback_required`, без mutation и выдуманной выдачи Basic.
- `EntitlementResolver`: batch snapshot pinned version/policy, canonical content
  hash, seats, overrides, availability и valid_until; до пяти indexed reads.
  Missing subscription/configuration, overdue lifecycle или неподготовленная
  participation возвращают `unavailable` и пустые values. Это не проверка прав
  пользователя. Planned storage не становится ни нулём, ни unlimited.
- `WorkspaceUsage`: только metadata contract; unavailable для storage/quotas,
  incomplete для неподготовленного occupied accounting. Observed human count
  явно отделён от пригодного для enforcement occupied capacity.

Команды выполняются в transaction: workspace lock **первым** для workspace
операций, затем singleton authority/current User/catalog rows. В Phase 1 узкий
каталог сериализован authority lock. Optimistic revisions и уникальный operation
hash обеспечивают conflict/replay. Авторизация повторяется до replay; один ключ
с другим действием/body/workspace → 409. Обязательная запись metadata event
входит в ту же transaction: ошибка аудита откатывает mutation. Audit содержит
IDs/revisions/outcome, без translations, project contents, email или secrets.

Published versions/policies и их values защищены Eloquent и DB triggers;
period facts/events append-only. Допустимое исключение — обнуление nullable
actor references при удалении User (`ON DELETE SET NULL`), с сохранением истории.
Primary authority FK остаётся RESTRICT. Canonical version hash включает
entitlements, seats, translations, price и access class; mutable catalog visibility
не меняет историческое содержимое. Current pointers имеют composite FK на свой
plan либо subscription/workspace.

## Локальное применение

Только в выбранной **локальной** БД, после обычной проверки конфигурации и backup:

```sh
cd api
PHP_INI_SCAN_DIR=:$PWD/dev-php php artisan migrate --no-interaction
PHP_INI_SCAN_DIR=:$PWD/dev-php php artisan db:seed --class=PlansFoundationSeeder --no-interaction
```

Seeder вызывается явно; в automatic DatabaseSeeder не добавлен. Создаёт только
три drafts и draft policy 7/7 дней с draft targets Studio/Basic. Storage/AI/
FormWatch quotas не заданы. Active pointers, subscriptions и Trial claims не
создаются. Повтор завершённого seed — no-op; изменения администратора не
перезаписываются. Незавершённая transaction seed откатывается целиком.

Migration forward-only. MariaDB DDL имеет implicit commits, поэтому migration
проверяет существование таблиц, индексов, FK, nullable columns и triggers по
отдельности. После прерывания повторяется обычный `artisan migrate`; не нужно
вручную считать частично созданную таблицу завершённой миграцией. Published
history не удаляется через rollback. Время новых доменных полей хранится как
UTC `DATETIME(6)`, чтобы не менять timezone существующего соединения и не зависеть
от session timezone преобразований TIMESTAMP. Новые models сохраняют микросекунды.

Schema требует обычных CREATE/ALTER/INDEX/REFERENCES/TRIGGER privileges в целевой
MariaDB. Production privileges/inventory на этом этапе не проверялись.

Список файлов/таблиц: [SCHEMA.md](SCHEMA.md). Проверки: [TESTS.md](TESTS.md).
Следующий этап: [PHASE-2.md](PHASE-2.md).
