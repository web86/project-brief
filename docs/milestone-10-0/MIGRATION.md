# Milestone 10.0 — внедрение, compatibility и rollback

Статус: **будущий план реализации после review обновлённой архитектуры**.
Сейчас изменяется только документация: migrations/seeds/commands/runtime/gates
не создаются и не запускаются, commit/push/deployment не разрешены этим заданием.
D1–D12 уже утверждены; не требуется заново согласовывать их вместо реализации.

## 1. База и предварительные решения

Использовать **v0.9.10 hotfix**, runtime bd09dd5 / source 7ec07e6, main publication
0a7ef45. Не откатываться к v0.9.8: потеряются shared-key semantics, scope-only
permissions, private chat/follow-ups и independent webhooks. Source diff/audit
зафиксирован в [ARCHITECTURE.md](ARCHITECTURE.md); проверить свежий checkout перед
будущей реализацией. Версия следующего runtime пока не назначена.

Implementation review до enforcement: lock order, client provenance/ambiguity,
root bootstrap/recovery, exact branding field allowlist, AI assistant capability,
численные quota budgets и retention/backup terms. Последние значения не утверждены;
не заполнять их вымышленными ценами/лимитами. FormWatch adapter отсутствует.

## 2. Этапы реализации и gates

| Этап                        | Работа                                                                                                          | Условие завершения                                                                                   |
| --------------------------- | --------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| A. Domain contracts         | Typed registry/paid constraint, canonical seats, UTC clock, counters/identities, preview; без blocking hooks    | Unit/type/query tests, D traceability, reviewed operation classification                             |
| B. Additive schema          | Authority/catalog/policy/periods/claims/cycles/resource states/epochs/events/previews/reservations              | Fresh + populated v0.9.10 upgrade на MariaDB, interrupted DDL/retry                                  |
| C. Primary bootstrap        | Interactive operator-only primary assignment, MFA/verified identity/recovery readiness; audit                   | Ни один existing platform_admin не повышен автоматически; root recovery rehearsal                    |
| D. Legacy inventory         | Hidden compatibility, Observe subscriptions, client accounting backfill/ambiguity, invite holds                 | Все existing workspace охвачены, прежние rights/data/hash/settings неизменны                         |
| E. Admin/owner API/UI       | Published plans/policies, Trial/Grace/fallback settings, rules copy, requests, suspension/restore, retention UI | Owner/root isolation, revisions/idempotency, RU/EN primary, responsive themes                        |
| F. Shadow adapters          | Все issuance/use/replay/agent/background/SSO/project write paths вызывают same decisions в Observe              | Coverage matrix полная; no auth/privacy regression, no real external calls из тестов                 |
| G. Lifecycle/restore canary | Trial eligibility, precise expiry, O(1) downgrade, Basic activation, execution barrier/rearm, notices           | Tests без cron, 100 projects, MariaDB races; legacy остаётся Observe                                 |
| H. Commercial activation    | Explicit reviewed enable для новых eligible registrations и выбранных existing workspace                        | Approved published policies/версии, предупреждения, rollback rehearsal                               |
| I. Quota/retention adapters | Реальные consumption points и durable ledger; проверенный owner deletion                                        | Отдельные budgets/retention решения, uncertain/reconciliation/files/backup tests; FormWatch отдельно |

Automatic destructive purge остаётся off до отдельного staging gate даже после
создания policy UI/schema. Payments/delegation roles/worker/rules DSL не входят.
Canonical release builder запускается только для будущей реализации после её QA,
а не для текущих восьми архитектурных файлов.

## 3. Seed, published policies и existing workspace D6

Идемпотентно создать draft Basic/Freelancer/Studio из ENTITLEMENTS и draft global
policy 7/7/Studio-version/Basic-version. Никаких slug checks для runtime.
Опубликовать primary action с preview, не считать изменяемый seed подтверждением.
Повтор seed не переписывает versions, pointers, dates или manual grants.

Hidden compatibility version: unlimited human/project/client/agent tariff capacity,
прежние API/webhook/SSO/branding/export возможности, без нового срока; safety limits
сохраняются. FormWatch unavailable, это не новый доступ. compatibility не public,
owner не может самостоятельно перейти на неё. Existing workspace не получает
Basic, Trial claim или countdown автоматически. Observe ничего не отключает.

Перед commercial launch зафиксировать cohort/cutoff inventory: workspace,
существовавшие до этого включения (в том числе созданные в shadow phase), получают
legacy treatment. Новые eligible owner registrations после включения получают
Trial по pinned policy; неподходящие — обычную free policy, без повторного Trial.
Первый owner, принявший импортированный workspace через OwnerProvisioning, не
делает его новым. Проверить все Workspace::create paths, invitations, factories,
LegacyWorkspaceBackfill и initial installer, не только registration controller.

Backfill: keyset по workspace ID; workspace lock → insert-if-missing subscription
→ stable client identity mapping/held invitations → event/checkpoint. Повтор не
сбрасывает approved grants/period/revision. Несколько active owners — отдельный compatibility/review case, без auto-demotion.
Strong-anchor/email collisions идут
в review, не merge User/SSO/account records. Отсутствие subscription в enforce —
ошибка состояния, не silent Basic или unlimited. Для compatibility Observe lookup
failure сохраняет старую authorized операцию с безопасной диагностикой.

До/после сверить IDs/ownership, human statuses/roles/assignments, project status и
status_before_trash, project-client links/active flags, accounting mapping, shared
link UUID generations, token hashes/scopes/expiry/revoke, all 7 event subscriptions,
endpoint secret versions/filters, private runs/messages/files/report authorship,
branding/storage references. Сверка только на disposable синтетической копии.

## 4. Observe, enforce и emergency policy

До activation модуль off или Observe: только would-deny/UTC projections/usage
metadata, без блокировки и без обещания/расходования нового коммерческого Trial.
Global rollout_mode — ceiling для новых assignments/enforcement; workspace может
оставаться Observe при global enforce. Legacy не мигрирует автоматически.

Включение — primary preview/confirmation с target set, expected revisions,
уведомлением владельцев. Нужна полная coverage всех mutation/read/premium paths,
не один middleware и Vue route guard. Canary измеряет discrepancies, lock duration,
query budgets и expiry/restore поведение; срок не заменяет эти exit criteria.

**Уже записанные suspension cycles/epochs нельзя отключить простым Observe/off**:
иначе jobs/runs/старые ключи оживут. Emergency control сначала ограничивает новые
внешние исполнения; снятие committed barrier — только explicit reviewed restore.
Core owner login/history/export/recovery не перекрывать платным kill switch.
При откате к старому runtime, который не знает барьеров, нужны дополнительные
операционные ограничения из раздела rollback; это не безопасный toggle.

## 5. Guard integration в существующие транзакции

Workspace-first mutex для всех capacity/lifecycle/credential/replay paths. Сейчас
CurrentWorkspace::resolve(lock=true) может взять membership lock первым;
независимый WebhookProcessor не берёт workspace перед lease/endpoint. Эти места
нельзя объявить исправленными документацией: требуется отдельный lock-order review
и MariaDB tests до внедрения новых guards. Существующие retries/leases не удалять.

Global policy/catalog locks не удерживаются при workspace mutation. Новые external
calls — после DB commit с recheck; существующий webhook network-under-lock protocol
требует bounded time и pre-send UTC/epoch guard. Actual in-flight external call
невозможно отозвать задним числом. Unknown provider timeout не освобождает quota.

Due executor должен catch-up прошедшие boundaries до любой новой выдачи доступа,
сохраняя gap и cancellation даже при позднем manual renewal. O(1) cycle marker
логически приостанавливает всё сразу; bounded command позднее материализует
cancelled rows/notices, без требования к своевременности cron. Команда имеет
lease/checkpoint/items+seconds, quiet success, actionable metadata failure.

Human core read/export, cancel/revoke/decline/reduce и billing/recovery остаются
доступны в рамках текущей authorization. Suspended non-owner не получает content;
SSO-origin sessions отличаются от обычных Magic Links. Новые guards не должны
случайно перекрывать основной SPA/API, security endpoints или feedback/privacy.

## 6. Реальная репетиция upgrade и восстановления

Начать с **установленной v0.9.10** на isolated MariaDB/private storage с human,
clients, shared/individual keys, mixed/independent/personal webhook fixtures,
queued/running/completed runs, follow-up chats/files и pending invitations.
Fresh install проверить отдельно; uninstall/fresh не доказывает upgrade.

Прерывать DDL/backfill/cancel batches, повторять и сверять data invariants.
MariaDB DDL не считать одной откатываемой transaction: наличие объектов/индексов
и checkpoint проверяются перед возобновлением, данные backfill отдельно.
EXPLAIN/SHOW INDEX и оперативная стоимость проверяются на synthetic dataset,
Jino-specific maintenance/cron/load thresholds подтверждаются позднее, не выдумываются.

Сценарий: 100 active проектов + несколько developers + >3 unique clients →
Trial expiry без cron → owner Basic/history/export → select one project/<=3 clients
→ paid request/approval → selective restore → explicit integration rearm → new
instruction, без replay старого run/event. Сверить все private histories/files и
исходные statuses/roles/generations до/после, revoke не должен отменяться restore.

Future implementation gates: PHP 8.4, focused/full Laravel+frontend/build, MariaDB
concurrency, browser language/theme/mobile, canonical ./bin/build-release,
CRC/manifest/secret scan/extracted runtime. Результаты прежнего v0.9.10 релиза не
выдавать за пройденные 10.0 tests. Не копировать QA screenshots/DB/env в release.

## 7. Rollback и сохранность

1. Остановить новые rollout/chargeable calls, отложить automatic purge; оставить
   core security/read/export. Не снимать committed suspension/epoch barriers.
2. Предпочесть forward fix. Bounded materialization всех stale queued/running
   runs/deliveries и uncertain-ledger reconciliation — обязательный gate перед
   runtime downgrade, иначе старый код их возобновит.
3. Репетировать rollback только к **v0.9.10**, сохраняя additive schema/APP_KEY и
   данные. Старый runtime не знает tariff guards: внешние ingress/cron/executors
   должны быть operationally paused до явного решения; коммерческий enforcement
   нельзя обещать в таком runtime. Возможное временное расширение core прав
   требует отдельного risk review, не является штатным восстановлением тарифа.
4. При возврате 10.0 — UTC catch-up, counts/holds/mapping/epoch reconcile и preview,
   без quota reset или автоматического rearm. Basic/paid gap не исчезает.
5. Не выполнять destructive migrate:rollback и не удалять historical tables при
   появлении реальных events/claims/periods. Forward-only down protection по
   существующей практике shared credentials.
6. Full backup restore отдельно авторизуется: согласованный DB/private storage/
   runtime/APP_KEY, учёт более поздних deletes/tombstones и потери новых данных.
   Retention backup copies не тождественен удалению active files.

## 8. Границы текущего задания

Подготовлены восемь обновлённых документов и source-audit/consistency evidence.
Не изменены production PHP/Vue/config/migrations/tests и исходные права/данные.
Не запускались migrations, seed, будущие команды, providers, release builder или
production проверки. Нет нового commit, push, deploy. Конкретные doc checks и
оставшиеся future gates — [TEST-PLAN.md](TEST-PLAN.md).
