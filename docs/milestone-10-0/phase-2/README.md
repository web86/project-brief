# Milestone 10.0 — Phase 2: Platform Admin & Plan Management

База: локальный Phase 1 `02cc6e0`, runtime v0.9.10. Ветка реализации:
`codex/milestone-10-0-phase-2`. Сохраняются утверждённые D1–D12 и восемь
архитектурных документов. Production, push и deployment не использовались.

## Реализовано

В существующий `/platform` добавлена ссылка «Тарифы и политики» для primary
PlanAuthority. Остальные platform_admin сохраняют диагностику и не получают
управление тарифами. Защищены и API, и прямые серверные `/platform/plans/...` routes.

- Каталог: поиск по code и четырём localized names, status/visibility filters,
  allowlisted sort, pagination 25–100, arbitrary plans, hidden visibility,
  archive/unarchive, published version, version pagination и action history.
- Registry editor: boolean, integer, explicit unlimited, enum, доступные quota
  definitions; единый developer_seats, derived developers.max read-only. Storage,
  AI/run/check quotas и FormWatch показаны planned/unavailable без чисел/inputs.
- Localized names/descriptions RU/EN/TR/ZH-CN, bounded price metadata без payments.
  Управление платформой RU/EN; TR/ZH-CN используют EN для нового management UI.
- Draft save/revision conflict, immutable publication через ready preview,
  diff функций/лимитов/seats, canonical hash, fresh proof и keep_existing only.
  Новый draft можно создать из конкретной published версии того же plan.
- Global policy drafts, published paid Trial target / free fallback target,
  durations, immutable policy versions. Publication меняет только указатель
  будущей конфигурации `plan_settings.current_policy_version_id`; rollout mode
  остаётся прежним. Pinned subscription periods не переписываются.
- Transition rule editor: published endpoints, manual approval, timing,
  select_and_suspend/reject active overage, read_only/reject storage overage,
  enabled flag. Copy preview фиксирует revisions/fingerprint всех связанных
  rules; confirmation создаёт **выключенные** копии с manual approval. Конфликты
  отклоняют всю transaction. Реальные переходы workspace не выполняются.
- Light/dark/system, responsive layout, native dialog, existing LoadingButton,
  existing step-up flow, beforeunload и route-leave/update guard. Ошибка/conflict
  сохраняет введённый draft. Retry после неизвестного timeout сохраняет ключ
  операции и body/revision; после success intent завершается.

Новая панель не назначает тарифы, не включает enforcement/Trial/downgrade,
не делает backfill и не меняет projects, roles, scopes, shared credentials,
Agent API, Webhooks, SSO, private collaboration или разрешения клиентов.
Новых таблиц и migrations в Phase 2 нет; используются все 27 таблиц Phase 1.

## Локальная настройка primary

Сначала настроить выбранную локальную БД и выполнить migration/explicit draft seed
по [Phase 1](../phase-1/README.md). Не запускать эти команды против production.

Primary — существующий User с `platform_admin=true`, подтверждённым email,
подтверждённой MFA, сохранёнными recovery codes и без активного operator-recovery
ограничения. Обычный workspace owner/admin не подходит автоматически. Если
требуется platform_admin, используется прежняя отдельная operator-команда
`platform:admin USER_ID` с её проверками; новая команда не повышает это право.

```sh
cd api
PHP_INI_SCAN_DIR=:$PWD/dev-php php artisan platform:plan-authority 123
```

После независимой проверки личности/account оператор обязан интерактивно ввести:
`ASSIGN PLAN AUTHORITY 123 REVISION 0`. Цифры заменяются реальными ID/revision,
которые показывает команда. `--no-interaction`, неправильная фраза, неподходящий
пользователь и повторное назначение при существующем primary отклоняются.
Server-console access и независимая operator verification — внешняя operational
граница; сервер не выдаёт за доказанную реальную личность один факт наличия CLI.
Публичного HTTP self-assignment/bootstrap/recovery endpoint нет.

Передача/восстановление с другого проверенного аккаунта:

```sh
PHP_INI_SCAN_DIR=:$PWD/dev-php php artisan platform:plan-authority 456 --transfer --previous-user=123 --expected-revision=1 --reason=operator_recovery
```

Требуется точная интерактивная фраза
`TRANSFER PLAN AUTHORITY 123 TO 456 REVISION 1`. Команда блокирует singleton,
повторно проверяет revision/previous user и security eligibility, атомарно пишет
обязательный `plan_events` audit и меняет primary/revision. Все sessions/proofs
обоих аккаунтов отзываются через существующий AccountProfile; затем требуется
обычный вход с MFA. История, preview и конфигурация не удаляются. Старые previews
с прежней authority revision больше не подтверждаются. Не передавать полномочия
самому текущему primary и не использовать initial_setup как reason transfer.

## Security и транзакции

Existing account-session/security/CSRF/throttle middleware + EnsurePlatformAdmin
сохранены. Новая EnsurePlanAuthority повторяет primary, verified email,
enrolled/strong session и fresh proof **внутри transaction под authority/user
locks**. Shell требует primary/strong MFA; sensitive API дополнительно fresh
proof. Старые diagnostic endpoints сохраняют свои проверки. Тарифные feature
права не заменяют user/object permissions.

HTTP mutations требуют Idempotency-Key; revision-sensitive действия требуют
`If-Match: "revision:N"` (нет header → 428, stale → 409). Все внешние object
references — UUID; version всегда привязан к parent plan. Unknown JSON fields,
unsupported scopes/types/quotas/compatibility class и paid features on free
отклоняются. Compatibility editing через этот HTTP adapter зарезервирован до
migration stage. Никаких handler/SQL/expression inputs.

PlanCommands и mandatory metadata audit входят в ту же transaction. HTTP error
responses тоже вызывают rollback: Laravel может преобразовать exception в
response внутри routing pipeline. Safe error rendering ограничен новыми API
paths; existing security_step_up_required response сохраняется. Ответы no-store,
no-referrer. Logs/history не содержат project content, files, tokens или secrets.

Catalog-only операции используют authority → user → catalog/preview/settings
locks. Workspace операций здесь нет. Будущие workspace writers сохраняют
workspace-first протокол Phase 1; management API не вызывает SubscriptionAssignments.

Preview TTL 15 минут, actor/authority/settings/registry/target revision и hash
проверяются при publication. Plan catalog revision защищает от смены metadata/
pointer после preview. Анализ workspace impact явно unavailable, без fabricated
zero affected. apply_now, schedule_period_end и массовый rollout API отсутствуют.

## Точечные исправления Phase 1

1. Archive проверяет current/draft policy, legacy pointer и enabled-rule
   dependencies. Исторические published references сохраняются; существующие
   subscriptions не изменяются. Отклонённая archive не создаёт частичный event.
2. Policy allowlists сравниваются canonical hash, поэтому порядок JSON keys не
   меняет семантику безопасной конфигурации.
3. nextDraft допускает явную published source version с parent binding; одна
   editable draft и optimistic plan revision по-прежнему проверяются.

## Файлы и ограничения

Новые backend: `SetPlanAuthority`, `EnsurePlanAuthority`,
`PlatformPlansController`, `Plans/PlanInput`, `Plans/PlanManagement`.
Изменены routes/web, scoped exception rendering, shell fresh exception,
PlanCatalog/PlanCommands/PlatformPolicyCatalog. Frontend: PlatformPlansView,
PlanEntitlements, planManagement request helpers/messages, existing router,
PlatformView navigation и step-up path allowlist. Тесты: PlatformPlansTest,
PlatformPlansConcurrencyTest, plan-management.test.js, isolated browser runner.

[API routes](API.md) · [проверки](TESTS.md) · [следующий этап](NEXT.md).
