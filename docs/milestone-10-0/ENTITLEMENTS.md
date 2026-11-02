# Milestone 10.0 — registry, usage и guards

Статус: проект по **утверждённым D1–D12**, база v0.9.10.
Связи: [архитектура](ARCHITECTURE.md), [БД](DATABASE.md),
[переходы](TRANSITIONS.md), [тест-план](TEST-PLAN.md).

## 1. Типизированный registry

Новые тарифы создаются из code-owned definitions: key, type, supported scopes,
unit/window, safe default, paid_requirement, availability, derived flag,
operation/counter adapter и tests. UI не создаёт неизвестные keys/handlers,
SQL/expressions или новые scope semantics. Names/codes/цены не участвуют в guard.
Mirror definitions в БД read-only; опубликованные значения immutable.

| Тип       | Canonical value                                                                | Правила                                                                        |
| --------- | ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| Boolean   | `{"kind":"boolean","value":true}`                                              | JSON boolean, не string/0/1                                                    |
| Integer   | `{"kind":"integer","value":10}`                                                | >=0, signed-safe bounded integer; 0 запрещает capacity growth                  |
| Unlimited | `{"kind":"unlimited"}`                                                         | Только numeric/quota definitions с allow_unlimited, не -1/null/999999          |
| Enum      | `{"kind":"enum","value":"basic"}`                                              | Кодовый allowlist и ordering                                                   |
| Quota     | `{"kind":"quota","limit":1000,"window":"calendar_month_utc","unit":"request"}` | Синтетический пример, не утверждённая квота; limit>=0, window/unit из registry |

Published version содержит все non-derived, available definitions; missing key
не unlimited. Для старой версии новый available key получает safe default, а
сохранение legacy требует явной новой compatibility version/preview policy.
Planned definition не включает adapter от присутствия строки в тарифе.

Для paid_requirement=paid_or_trial разрешён только effective paid service,
полный Trial или Grace от paid. Hidden compatibility в Observe — отдельное
migration exception, недоступное владельцу через public plan или override.
Обычный free/Basic не может получить paid-only feature через override, имя Studio,
нулевую цену, прямой токен или forged paid=true. Проверка действует при publish,
assignment, override и в resolver/runtime. Availability и safety сильнее тарифа.

## 2. Стартовые значения D1/D7/D8

| Key / policy           | Scope и тип                                              | Basic               | Freelancer          | Studio                                 |
| ---------------------- | -------------------------------------------------------- | ------------------- | ------------------- | -------------------------------------- |
| `projects.active.max`  | workspace integer/unlimited                              | 1                   | 10                  | 100                                    |
| `developer_seats`      | workspace canonical policy                               | 1 included / hard 1 | 1 included / hard 1 | 10 included / hard 50                  |
| `developers.max`       | derived read-only capacity                               | 1                   | 1                   | min(50,10+approved_extra)              |
| `clients.max`          | workspace; project только с проверенным provider         | 3                   | unlimited           | unlimited                              |
| `agents.enabled`       | workspace boolean; paid_or_trial                         | false               | true                | true                                   |
| `agents.active.max`    | workspace integer/unlimited; paid_or_trial               | 0                   | 1                   | unlimited                              |
| `api.agents.enabled`   | workspace boolean; paid_or_trial                         | false               | true                | true                                   |
| `api.external.enabled` | workspace boolean; paid_or_trial                         | false               | false               | true                                   |
| `webhooks.enabled`     | workspace boolean; paid_or_trial                         | false               | false               | true                                   |
| `sso.enabled`          | workspace boolean; paid_or_trial                         | false               | false               | true                                   |
| `formwatch.enabled`    | workspace boolean; paid_or_trial, unavailable            | false               | false               | предусмотрено, без работающего adapter |
| `branding.level`       | workspace enum none<basic<full; basic/full paid_or_trial | none                | basic               | full                                   |

Это editable initial published configuration после безопасной публикации, не
проверки строк Basic/Freelancer/Studio в backend. Семантика fallback использует
закреплённую free version; стартовый fallback — именно 1 project/1 owner/3 clients.
Developer seats не записываются вторым значением в entitlement values/overrides.
Studio extra=0 по умолчанию; +1..40 только с подтверждением primary authority,
не факт оплаты. Hard maximum 50 сохраняется и в Trial; полный Trial не означает
unlimited safety/technical quotas или автоматическое предоставление 40 extra seats.

Core вход, security/recovery, подписка, owner history/export и базовый Magic Link
не являются внешними интеграциями и не выключаются api.external.enabled=false.
Export минимум позволяет владельцу забрать свои доступные данные с текущими
privacy/object filters и operational bounds. Новые commercial export tiers,
ads/support и точный branding field allowlist требуют уточнения; предыдущие
неутверждённые значения не перенесены как продуктовые решения.

Стартовый branding basic/full — согласованный уровень, но точный field mapping
на WorkspaceBranding/save/portal/email/export надо проверить перед enforcement.
Хранимые settings/assets не удаляются; юридические/security данные не скрываются.
FormWatch пока не имеет исполняемого entitlement: availability=false и runtime
false. Включение adapter потребует утверждённых quota values/new version/preview,
а не только переключения глобальной availability для старых версий.
Политика встроенного AI assistant не выводится из запрета внешних интеграций:
отдельно утвердить capability/value до ограничения его существующего core UI.

## 3. Counts и единое effective состояние

### Human developer seats

Distinct user_id в активных workspace_members owner/admin с действующим доступом,
включая owner. Обычный admin без project assignment всё равно занимает workspace
seat; multi-project assignment и is_project_superadmin не второй человек.
ExternalAgent, client и legacy users.role исключены. Тарифная приостановка
non-owner делает participation неэффективной, не переписывает membership status.
Owner остаётся одним developer даже при общем suspension cycle. Если source inventory
обнаружит несколько active owners, enforcement/downgrade требует отдельного
ownership review, без произвольного выбора/понижения владельцев. Compatibility
сохраняет все прежние права, пока неоднозначность не решена.

`effective_seats = min(hard_max, included + approved_extra)` из одного policy.
Нельзя задать независимый developers.max override или поднять technical ceiling
commercial grant. Первоначально 10/12/50 для extra=0/2/40; 51-й запрещён.
Scope этого seat policy всегда workspace; будущий project ceiling — другой key,
planned до provider и migration review, не переключатель того же maximum.

Occupied = union(active human user identities, valid held invite identities).
Invite нормализуется текущим DeveloperInvitations adapter; известный User — user_id,
ещё не зарегистрированный — keyed normalized-email identity. Повторные приглашения
одному email/на несколько проектов удерживают одно workspace seat. Accept под
workspace lock заменяет hold membership; active user delta=0, новый User/email
не дублируется. Edit/resend/expiry/revoke/reactivate и смена account email
пересчитывают union; suspended/expired/revoked holds не считаются и не разрешают
accept. Downgrade не отзывает исходное приглашение и не меняет его настоящий expiry.

### Проекты и архив

Effective active = `deleted_at IS NULL AND status=active` AND нет действующего
user archive / tariff hold. Completed feedback сам по себе не меняет этот predicate.
Inactive/trash/tariff-suspended не расходуют active projects или client seats.
Restore active, inactive→active и tariff release одновременно проверяют project
capacity и union уникальных clients. Создание active project с initial client —
одна атомарная multi-key операция. Inactive creation не расходует active slot,
но работать в нём как в active нельзя. Trash не освобождает storage bytes.

`ProjectActivityGuard` обязателен не только в AgentAccess/ProjectAccessToken:
TaskAccess и остальные human/client/API write paths должны запрещать full workflow
в inactive/suspended project. Запрещены task/comment/status/approval/new-file writes,
agent assignments/runs и project-linked background execution. Owner может читать
историю, экспортировать, уменьшать участие, управлять archive/activation/удалением
по прежним permissions. Наличие read-only истории не делает проект активным.

### Уникальные клиенты D2/D3

Usage = distinct accounting identity UUID среди эффективных active project-client
participations на эффективно active проектах workspace. Одна identity в пяти таких
проектах = одно место; участие только в inactive/suspended/trash = ноль. Project
scope, если поддержан, считает такой же set в одном проекте, не links.

Порядок разрешения `ClientCountingIdentities`:

1. Сохранённый accounting link и доказанный provenance имеют приоритет. Повторный
   запрос не генерирует новую identity. Existing проверяемые stable source-bound
   identifiers (например, валидированное соответствие SSO source/binding) дают
   alias только в своём workspace/issuer, без cross-source доверия по raw ID.
2. workspace_client_id можно переиспользовать как contact anchor, только проверив
   workspace и существующее соответствие; это **не verified human account**.
   Наличие контакта само по себе не доказывает общую authentication identity.
3. При отсутствии достаточного anchor — normalized email по текущему adapter,
   keyed alias и один стабильный UUID для непротиворечивых links той же mailbox.
   Не использовать имя, телефон, cookie/IP, unverified внешний clientId или домен
   email как доказательство. Общая mailbox — явное ограничение fallback.
4. Разные подтверждённые identities с общей mailbox не объединяются. Alias
   collision → ambiguous, отдельная identity и owner review, без undercount и
   автоматического выбора одного чужого record. Без достаточных данных — отдельный
   устойчивый UUID, сохраняемый между refresh/retry/restore, не новый UUID на count.

Существующие users, workspace_clients, project_clients, SSO bindings, Magic Links
и grants не объединяются/перепривязываются ради счётчика. Новый alias не выдаёт
access. Owner email edit не должен автоматически сливать уже различённые clients
или освобождать seats: пересчитать provenance/delta под lock; неоднозначность
учитывать консервативно. Изменения accounting mapping имеют revision/history.

До enforce все current project-client links имеют проверенный accounting mapping;
NULL/незавершённый backfill не считается нулевым usage. Growth/activation fail closed
с reason, owner history/export остаются доступны.

Providers проверяют add/edit/reactivate клиента, email/identity change, provisioning,
project create/restore/activate и массовые действия. Повторный Magic Link для уже
active клиента имеет delta=0, но не обходит participation/project check. В Basic
owner может tariff-suspend лишние участия без удаления клиентских данных; выбор
проекта и максимум 3 identities применяются одной транзакцией.

### Агенты

agents.active.max считает identities active=true, non-deleted, без tariff hold,
не credentials/links/project memberships. Один key у двух агентов не один agent.
Активная регистрация без ключа также занимает agent slot, но не даёт API доступа.
Basic сохраняет history/configuration, разрешает безопасный revoke/cancel, но не
создание/activation/execution новых агентов. Studio unlimited — отсутствие
тарифного предела, не отмена operational caps/rate/file/resource safety.

## 4. Resolver и transaction protocol

Snapshot: verified workspace → UTC clock/due transitions → pinned version/policy
→ canonical seats → compatible overrides → participation/epoch barriers → paid
eligibility → availability/safety. Содержит revision, provenance, effective phase,
validUntil и reason codes. Не возвращать private content/secret settings платформе.

Request memoization допустима до validUntil; после boundary заново проверить БД.
Shared cache — только immutable values/policy/hash, не mutable authorization.
Subscription/epochs/overrides читаются batch, не query на каждую feature.
Плановый бюджет normal resolve: <=5 indexed queries cold, <=4 warm; due executor
и capacity counts измеряются отдельно, без обещания latency до MariaDB замеров.

```text
authenticate + existing role/object checks
begin transaction
  workspace row lock FIRST (existing IntegrationWrites::lockWorkspace)
  current subscription/period + UTC boundaries + suspension/epochs, catch-up
  recheck membership/token/scopes/grants/assignment; invalidate cached permissions
  resource/project rows in deterministic ID order, current reads
  current union usage + valid reservations, intended multi-key delta
  positive deltas must fit; validate activity / feature / epoch BEFORE replay
  apply mutation + capacity/quota/idempotency/event atomically
commit
new notifications/external calls after commit, with their own pre-send guard
```

В MariaDB REPEATABLE READ счётчик не берётся из старого consistent snapshot.
После workspace lock — locking reads нужных IDs/identity columns, затем distinct
union; не предполагать корректность COUNT(*) FOR UPDATE. Большие наборы — keyset
chunks при том же mutex. CurrentWorkspace::resolve(lock=true) и existing caller
transactions требуют lock-order audit: не вставлять middleware в неизвестную
внешнюю transaction. Catalog locks не берутся внутри workspace mutation.

Для webhook send сохранить существующий bounded delivery protocol, но workspace
lock должен охватывать и independent endpoints: затем lease/endpoint/delivery
locks, проверка UTC/epochs/activity непосредственно перед send. Network-under-lock
и связь с глобальным webhook lease отдельно измеряются; не объявлять lock-free
atomic revoke. Уже отправленный пакет отозвать нельзя.

Deadlock/timeout: bounded retry всей DB операции с прежним operation key (до 3),
без повторного внешнего side effect. Delta<=0 допустима с прежними permissions;
это не обход project read-only. Отказ не оставляет contact/project/file/hold,
success idempotency или queued event. UI usage asOf — не reservation permit.

## 5. Матрица feature/access adapters v0.9.10

| Путь                                                                            | Проверка / обязательная точка                                                                                                                    |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| ProjectCreation, ProjectController, ProjectLifecycle/Trash                      | Effective activity, project+client delta, activate/restore/release; safe archive/read/export сохраняются                                         |
| WorkspaceAdministratorController, DeveloperInvitations, ProjectTeamController   | Seats/holds, owner included, non-owner suspension, no automatic role rewrite                                                                     |
| ProjectClientController, ClientIdentities/Provisioning/Requests                 | Stable identity+active union; approve фактического создания, не только pending request                                                           |
| IntegrationController::token, AgentController::token, IntegrationTokens::create | Type=agent → agents.enabled + api.agents.enabled; type=integration → capability по scope; legacy agent issuance и replace также guarded          |
| AgentCredentials::bind / agent save / team/task assignment                      | Current paid feature и capacity при activation; shared generation/scopes сохранены; Basic detach/revoke/cancel разрешены                         |
| IntegrationController::updateToken/revoke                                       | updateToken меняет provisioning flag, не вращает секрет; enable требует нужной external capability; revoke/list безопасны                        |
| AuthenticateIntegration / IntegrationReadAccess / IntegrationWrites             | Agent namespace отдельно; non-agent полный API gate, SSO-only clients:sso gate; current check до read/write/replay                               |
| AgentCollaborationController::store, AgentRuns::request                         | Initial и повторное поручение, multipart attachments, submission replay: agents/API/capacity/quota/epoch; cancel/history отдельно                |
| AgentRunApiController, AgentApiController, AgentReadController                  | Context/messages/files reads, claim, report/message/attachment writes и replay — current entitlement/project/binding/assignment                  |
| AgentResultController, AgentChat, export filters                                | Human history сохраняется; publication — отдельная выбранная копия на active project, original private; никаких blanket bypass                   |
| WebhookEvents, WebhookProcessor, IntegrationController test/retry/rotate        | webhooks.enabled самостоятельно для mixed/agent subscriptions, epoch и project activity; не зависеть от external_agent_id или наличия агента     |
| ClientSso issue/consume/sessionUsable                                           | sso.enabled, source/origin/TTL/grant и project/client activity; SSO-only credential не требует full API; обычный Magic Link не gated как premium |
| WorkspaceBranding и document/portal/email rendering                             | Effective level + утверждённый field allowlist; base assets сохраняются                                                                          |
| AiTaskAssistant и будущие adapters/jobs                                         | Capability после уточнения продукта, durable quota перед вызовом, существующие safety limits; diagnostics не billing                             |

Ротации: в v0.9.10 новый credential создаётся и старый явно revoke/replace; отдельной
unified rotate route нет. Guard стоит в общем create/bind/use и на старом agent
issuance, а не только на новом UI. Webhook signing-secret rotate — другой путь.
Не расширять ни существующие scopes, ни Agent API namespace, ни права токена.

## 6. Quota Ledger D11/D12

Pending provisioning/project requests не одобряются автоматически после expiry.
Их фактический integration approve/replay (включая owner UI/batch caller) требует
api.external.enabled внутри domain service; ручное core создание проекта отдельно
проверяет Basic capacity и не возрождает source/SSO automation.

Registry предусматривает `agents.runs.quota` (initial и повторные run creation),
`ai.requests.quota`, `formwatch.checks.quota`, `storage.bytes.max`, при реальной
необходимости tokens/bytes quotas. Числа и тарификация не утверждены. Count agent
run относится к server-created run UUID, не к model tokens или числу HTTP retries.
До отдельного утверждения чисел новые tariff budgets для runs/AI/storage не
включаются: доступная функция сохраняет существующие технические caps, это не
объявляется unlimited коммерческой квотой. Planned quota не читается как available
limit=0 и не блокирует full Trial. Включение quota adapter требует explicit published
values и reviewed migration. Для FormWatch сам feature adapter отсутствует,
поэтому там исполнение unavailable целиком.
Внешний executor может вызвать модель несколько раз — без trusted receipt нельзя
выдавать это за измеренное AI consumption. Встроенный AiTaskAssistant имеет свой
реальный provider-call boundary и отдельный adapter.

Ledger reserve под workspace+bucket lock, затем внешний call вне transaction;
commit переносит reserved→consumed, безопасный failure до выполнения — release,
unknown timeout — uncertain с удержанием amount. Ни lease expiry, ни повтор key
не повторяют неизвестный chargeable call автоматически. Same key/different hash
→409. Same run/submission retry не списывает второй раз. Consumption не сбрасывается
при plan/version/renew/restore, и новый execution epoch не новый бюджет.

Календарные UTC day/month windows — не subscription period. Scope/window change
требует preview, mapping/history и миграции consumed/reserved/uncertain, без нулевого
нового bucket посреди старого окна. На первом этапе немедленная такая смена запрещена;
применять на согласованной boundary после закрытия прежнего окна/обязательств.
Unlimited тоже учитывает usage; безопасность и operational caps сохраняются.

Storage включает сохранённые private files, в том числе trash/suspended проекты;
release bytes только после подтверждённого физического удаления. FormWatch остаётся
unavailable до реального adapter/trusted event ID/reconciliation tests. Наличие
quota definition не симулирует успешную интеграцию.
