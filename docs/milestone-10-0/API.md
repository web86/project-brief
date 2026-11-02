# Milestone 10.0 — проект API

Базовая редакция восьми документов опубликована в `main` коммитом `c95a54e`;
production-код v0.9.10 не изменён. Эта редакция содержит финальные уточнения
от 02.11.2026; реализация ещё не начата.

Статус: **предлагаемые контракты, маршруты не реализованы**.
База v0.9.10. Существующие `/api/admin`, `/api/platform`, `/api/v1/agent` и
обычные client routes не переименовываются; новый auth/scopes stack не создаётся.

## 1. Security и concurrency

Plan management: existing EnsurePlatformAdmin + strong/enrolled MFA + fresh proof
и дополнительный PlanAuthority primary check. Не каждый platform_admin — primary.
Прямые `/platform/plans/...` shell routes защищаются сервером, не только Vue.
Остальная диагностика platform сохраняет текущую политику и не открывает content.
Bootstrap/recovery primary — operator-only future CLI, **нет публичного HTTP
назначения, регистрации root или bearer plan-management scope**.

Owner API: active current workspace owner, agreements/security gates, fresh proof
на sensitive mutation. Suspended non-owner не получает owner capabilities.
CSRF, existing throttles, Cache-Control:no-store и Referrer-Policy:no-referrer.
Сначала authentication/object authorization с существующими 401/403/404 masking,
потом entitlement; чужие subscription metadata не раскрываются через denial.

Unsafe commands требуют Idempotency-Key (allowlisted ASCII 1–128) и canonical hash,
namespace actor/action/object. Edits/approval дополнительно If-Match:"revision:N":
нет header →428, stale→409. Same key/hash возвращает прежний outcome после текущей
authorization; другой input→409. Audit/root event и mutation атомарны. Batch
approval закрепляет authority revision; side-effect retries не дублируют mail/run.

External UUID, typed allowlist, bounded JSON/page size 25..100, safe sort mapping.
Не принимать paid=true, bypass, role, raw SQL/handler, чужой workspace_id или
подменяемый subscriptionId в domain payload. Owner видит свой usage; non-owner и
bearer получают только подходящий reason/code без чужих точных workspace counts.

### Базовая регистрация и social login (предлагаемые routes)

| Метод / путь                                     | Минимальный контракт                                                                                |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------- |
| POST `/api/registration`                         | Pending owner onboarding; no workspace/project access, Trial dates=NULL                             |
| GET `/api/registration/status`                   | Только собственный pending state/следующий шаг; нет workspace contents                              |
| POST `/api/registration/email/send` / `/confirm` | Throttled single-use confirmation нужного email; replay не начинает новый Trial                     |
| GET `/auth/{provider}/start` / `/callback`       | Allowlist google/yandex, server-bound state/PKCE, validated account proof; OIDC nonce где применимо |

Эти routes пока не существуют. Pending session разрешает только необходимые
registration/verification/provider callback/logout/recovery/CSRF действия. Даже
правильный password не открывает кабинет/проекты до readiness; middleware guards
охватывают deep links, /admin/me, lists, downloads, sync и APIs, не только меню.

При callback owner определяется persisted provider+issuer+subject binding, не
email query. Link existing User требует уже аутентифицированного existing account
и fresh proof; start purpose=link хранится на сервере, callback не принимает
arbitrary user/workspace ID. Email collision не auto-login/auto-merge. Нельзя
подменить отсутствующий email фиктивным адресом: требуется отдельная проверка.
После trusted account proof и доказанного email successful verification атомарно
создаёт один Trial startsAt=t_verified / endsAt=t_verified+7×24h и pinned version;
повтор любого способа входа возвращает тот же период. Provider login сам не proof
реальной личности, не заменяет MFA и не создаёт platform_admin/membership права.

Google/Яндекс — basic authentication: доступность не зависит от paid features,
api.external.enabled или corporate sso.enabled. Provider secrets только в server
config; ClientSso/integration_sources и client Magic Links не используются как
социальный login bridge. Validation contract — TRANSITIONS, migration legacy
verification readiness — MIGRATION. Не вводить универсальный identity/billing engine.

## 2. Registry, каталог и версии — только primary

| Метод / путь                                                  | Контракт                                                                                                 |
| ------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| GET `/api/platform/entitlement-definitions`                   | Types/scopes/defaults/paid requirement/availability/derived и localized labels, без executable internals |
| GET/POST `/api/platform/plans`                                | Пагинация catalog / новая draft v1, code и version content                                               |
| GET/PATCH `/api/platform/plans/{plan}`                        | Metadata / visibility, sortOrder, revision, reason                                                       |
| POST `/api/platform/plans/{plan}/draft`                       | Copy выбранной published версии; existing draft→409                                                      |
| GET/PUT `/api/platform/plans/{plan}/versions/{version}`       | Read immutable или полный typed draft replacement; parent binding                                        |
| POST `/api/platform/plans/{plan}/versions/{version}/previews` | Target policy/selection; 202 preview UUID                                                                |
| POST `/api/platform/plans/{plan}/versions/{version}/publish`  | Ready preview/hash/revision, immutable publication; keep_existing default                                |
| POST `/api/platform/plans/{plan}/versions/{version}/rollouts` | Отдельный approved frozen batch, без скрытого apply-all                                                  |
| GET `/api/platform/plan-previews/{preview}` / `/items`        | Progress/TTL/asOf/metadata-only summary и outcomes                                                       |
| GET `/api/platform/plan-rollouts/{batch}`                     | applied/skipped/needs_review/failed/cancelled counts                                                     |
| POST `/api/platform/plan-rollouts/{batch}/resume` / `/cancel` | Lease/revision/authority recheck, оставшиеся items                                                       |
| POST `/api/platform/plans/{plan}/archive` / `/unarchive`      | Preview/confirmation; replacement текущих global pointers, сохранение pinned history                     |
| GET `/api/platform/plans/{plan}/subscribers` / `/history`     | Workspace metadata/counts/version/revision, без project/client/task content                              |
| GET/POST/PATCH `/api/platform/plan-transition-rules[/{rule}]` | Typed source/target/initiator/approval/timing/overage, revision/audit                                    |
| POST `/api/platform/plan-transition-rules/copy-previews`      | fromVersion/toVersion, candidate remap/diff/conflicts, ничего не включает                                |
| POST `/api/platform/plan-transition-rules/copies`             | Ready preview/expected rules revision, атомарно подтвердить валидные rules                               |

Draft fragment; полный PUT содержит все available non-derived definitions:

```json
{
  "accessClass": "paid",
  "developerSeats": { "included": 10, "hardMax": 50, "allowExtra": true },
  "entitlements": [
    {
      "key": "projects.active.max",
      "scope": "workspace",
      "value": { "kind": "integer", "value": 100 }
    },
    { "key": "agents.active.max", "scope": "workspace", "value": { "kind": "unlimited" } },
    {
      "key": "api.agents.enabled",
      "scope": "workspace",
      "value": { "kind": "boolean", "value": true }
    },
    {
      "key": "api.external.enabled",
      "scope": "workspace",
      "value": { "kind": "boolean", "value": true }
    }
  ]
}
```

API maps developerSeats → canonical developer_seats_json. developers.max input
отвергается как derived, не синхронизируется вторым editable полем. Translations
ru/en/tr/zh-CN, price metadata и остальные values обязательны в полном документе.
Неутверждённая paid цена null не означает free/paid proof. Published PUT→409.

## 3. Platform lifecycle settings и workspace changes

| Метод / путь                                                                           | Контракт                                                                                                                       |
| -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| GET `/api/platform/plan-settings`                                                      | Current published policy/pointers/revision и effective rollout ceiling                                                         |
| POST `/api/platform/plan-settings/drafts`                                              | Новая policy из published, Trial/Grace durations, конкретные trial/fallback version IDs, eligibility/retention/security policy |
| PUT `/api/platform/plan-settings/drafts/{policy}`                                      | Typed draft replacement; pinned current periods не меняются                                                                    |
| POST `/api/platform/plan-settings/drafts/{policy}/previews` / `/publish`               | Impact на новые/явно выбранные существующие periods; publish отдельно от migration                                             |
| POST `/api/platform/plan-previews`                                                     | Typed catalog/settings/override/scope-migration preview, bound UUID/revision/policy                                            |
| GET `/api/platform/workspaces/{workspace}/subscription`                                | Pinned version/policy, UTC dates, phase, seats/grants/usage/cycle/epoch metadata                                               |
| POST `/api/platform/workspaces/{workspace}/subscription/previews`                      | assign/transition/renew/seats/enforcement/terminate, target/dates/overrides/impact                                             |
| POST `/api/platform/workspaces/{workspace}/subscription/changes`                       | Ready preview/reason, explicit selected workspace, applied/scheduled outcome                                                   |
| POST `/api/platform/subscription-changes/{change}/approve` / `/reject` / `/cancel`     | Current actor/root revision/preview recheck; paid activation только approved                                                   |
| GET `/api/platform/workspaces/{workspace}/subscription/history`                        | Append-only periods/changes/events без private contents                                                                        |
| POST `/api/platform/workspaces/{workspace}/subscription/overrides` / `/{override}/end` | Typed compatible key/value/interval, reason/preview; не seats/paid eligibility/scope change                                    |

Initial policy input: trialDays=7, graceDays=7, trialVersion=конкретная Studio,
fallbackVersion=конкретная Basic. Backfill legacy использует separate hidden version,
не этот default fallback. Исторический период меняется только отдельным migration
change, не PATCH singleton pointer. Security terminate не masquerades как обычное
paid expiry и не создаёт Grace автоматически.

Paid downgrade preview/changes дополнительно связывают activeSelection с target
version и текущим usage: projectIds, developerMemberIds, clientIdentityIds, agentIds,
а также решения о pending приглашениях. UUID используются где существуют;
WorkspaceMember ID — существующий bounded decimal-string ID, проверенный в workspace.
Owner включён обязательно. Каждая группа после выбора в целевой capacity; остальные
held. Missing/unsafe selection →422/409 с review reasons. Постоянный block_growth
для active-resource overage не допускается. Storage overage подтверждается как
отдельный read-only режим по обоим ключам, без destructive side effects.

## 4. Owner: подписка, Basic activation и восстановление

| Метод / путь                                                   | Доступ / результат                                                                                            |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| GET `/api/admin/subscription`                                  | Owner: current phase/version/policy, Trial/Grace/Basic dates, usage, reasons, next change, suspension summary |
| GET `/api/admin/subscription/transitions`                      | Только enabled public targets; hidden compatibility не показывать                                             |
| POST `/api/admin/subscription/previews`                        | Change request target/requested extra seats; ничего не выдаёт                                                 |
| POST/GET `/api/admin/subscription/change-requests`             | Ready preview + revision + key / текущие заявки workspace                                                     |
| POST `/api/admin/subscription/change-requests/{change}/cancel` | Своя ещё не применённая заявка/schedule, fresh proof                                                          |
| GET `/api/admin/subscription/suspended-resources`              | Owner-authorized project/team/config metadata и reasons; paginated                                            |
| POST `/api/admin/subscription/project-activation-previews`     | projectId, activeClientIdentityIds?; project+distinct client delta/overage                                    |
| POST `/api/admin/subscription/project-activations`             | Ready preview/fresh proof/revisions; atomic project release + extra client holds                              |
| POST `/api/admin/subscription/restore-previews`                | Explicit selected resource UUIDs by supported kind; capacity/current auth/epoch impact                        |
| POST `/api/admin/subscription/restorations`                    | Подтверждённые resources; partial outcome; integration rearm явно отдельно от project/team restore            |
| GET `/api/admin/entitlements`                                  | Минимальные UI decisions по разрешениям текущего участника, revision/validUntil/reasons                       |

Owner project preview может показывать собственные client names/email и accounting
UUID только по прежнему manage_clients/owner access. Platform preview получает
лишь counts/UUID, не emails. Выбор клиентов не переносит Magic Links/SSO identity.
UI entitlement route доступен также для объяснения приостановки без выдачи content
suspended non-owner; security/profile/other workspace selection остаются отдельно.

Restoration payload не содержит enableAll/replayPending. Explicit selection
обязательна, включая интеграции. При token expiry/revoke, changed link generation,
manual inactive/delete или permission loss элемент не восстанавливается. Новый
run требует нового submissionId; чужой/старый preview не разрешает новый запуск.

Existing PDF/DOCX export и разрешённый owner file download остаются core
операциями для held ресурсов после Trial/downgrade/project suspension с прежними
privacy/object filters. Новый full-workspace streaming/export endpoint/adapter
в Milestone 10.0 не создаётся. Если выявятся отдельные обязательные требования
переносимости персональных данных, сформировать отдельное описание/scope для
согласования, без неявного универсального экспортёра.

### Storage decisions на существующих upload paths

Owner subscription/usage response содержит used/reserved/pendingCleanup bytes и
files, оба typed limits или unconfigured, asOf и storageReadOnly. Non-owner/bearer
получает только разрешённый code/key, без workspace-wide counts. Регистрация
нового storage API/framework не нужна: существующие multipart/branding/copy paths
вызывают единый capacity adapter и unchanged authorization/size/mime checks.

Оба reserve атомарны до persistent write, measured input/derivative bound берётся
сервером. New delta over storage.bytes.max либо storage.files.max →409
entitlement_limit_exceeded с соответствующим key; при неизвестном accounting
state growth fail closed, safe read/download/delete остаются доступны. Ответ
не объявляет row delete освобождением storage. При existing successful replay
нет новых copies/usage, но auth/feature/activity/epoch проверяются как прежде.
Target storage-read-only не отключает existing PDF/DOCX/export/download routes.

## 5. Retention и owner deletion

| Метод / путь                                                   | Контракт                                                                                             |
| -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| GET `/api/platform/retention-policies`                         | Primary: classes/settings/availability/staging gate; metadata-only                                   |
| POST `/api/platform/retention-previews`                        | Primary: applicability/counts/warnings, без private paths/content; часть policy publication workflow |
| GET `/api/admin/subscription/retention`                        | Owner: применимые сроки/предупреждения/действия, active-free protection и backup limitations         |
| POST `/api/admin/workspace-deletion/previews`                  | Owner/fresh proof: scope=current workspace, counts/consequences, expiring confirmation hash          |
| POST `/api/admin/workspace-deletion/requests`                  | Ready preview + typed confirmation + step-up receipt/revision/key; 202 durable job                   |
| GET `/api/admin/workspace-deletion/requests/{request}`         | Только свой workspace, progress/failures/backup-retention, без file contents                         |
| POST `/api/admin/workspace-deletion/requests/{request}/cancel` | До начала purge отменить; после старта только remaining stop, не rollback promise                    |

Automatic purge enable при unavailable staging gate→422. Нет generic DELETE по
произвольному workspace из owner payload. Actual deletion повторяет current
policy/active-resource/legal-hold checks. Не удалять global User/другие workspace.
Во время удаления сохраняется контролируемый доступ владельца к status/security,
а не бесконечная рабочая session на уже закрытых проектах.

## 6. Existing API adapters и replay

Маршрут классифицируется по server-owned operation metadata, не по одному префиксу
/api или переданному type. `/api/admin` SPA, CSRF/security/export/Magic Link не
блокируются paid external API guard. Agent credential остаётся только Agent API;
обычный integration credential с заголовком agent ID им не становится.

- Agent API/use/issuance/bind: agents.enabled + api.agents.enabled и прежние scopes,
  current identity/link generation, grants/memberships/assignment/object checks.
- Non-agent external project/provision/request/read/write: api.external.enabled.
- SSO handoff: sso.enabled + clients:sso/source/origin/TTL/grants; не требовать
  full API для отдельной SSO операции. Mixed-scope credential не расширяет права
  других маршрутов; issuance проверяет capabilities всех запрошенных scopes.
- New shared/individual credentials — существующие create paths; rotation через
  новый key и явный revoke/legacy replace. Unified updateToken лишь provisioning
  flag. Не выдумывать отсутствующий token rotate endpoint.
- Webhook management/test/retry/rotate/enqueue/send: webhooks.enabled и текущие
  filters/epoch. Independent agent-event subscription не требует agent key и
  не выдаёт никаких Agent API permissions. Все 7 AgentAccess::EVENTS сохранены.
- Выполненный execution replay: auth/entitlement/activity/epoch **до** old outcome.
  Старый run/start/result после gap не возвращает misleading running success;
  409 execution_epoch_stale, без второго исполнения/списания. Обычный разрешённый
  resource replay не создаёт/не активирует старый ресурс и не сбрасывает budget.

## 7. Typed ошибки

| HTTP        | Code                                                                                                   | Семантика                                                                |
| ----------- | ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| 401/403/404 | Existing auth codes                                                                                    | Auth/role/MFA/object masking до entitlement                              |
| 403         | entitlement_feature_disabled / paid_context_required                                                   | Нет feature/paid eligibility; generic member/bearer response             |
| 403         | project_suspended / participant_suspended                                                              | Этот уже авторизованный объект read-only/недоступен для операции         |
| 409         | entitlement_limit_exceeded / quota_exhausted                                                           | Positive delta/consumption не помещается; resetAt только известного окна |
| 409         | revision_conflict / preview_stale / change_needs_review / idempotency_conflict / execution_epoch_stale | Refresh/review/new submission; не auto-confirm/replay                    |
| 422         | plan_validation_failed / transition_not_allowed / capability_unavailable                               | Type/scope/derived/paid restriction или unavailable adapter/purge gate   |
| 428         | revision_required                                                                                      | If-Match обязателен                                                      |
| 429         | Existing rate-limit codes                                                                              | Safety limit отдельно от тарифной quota                                  |
| 503         | entitlement_state_unavailable / entitlement_lock_timeout                                               | Premium/growth fail closed; core access при рабочей auth/DB не теряется  |

Owner capacity error дополнительно содержит key, scope, current, typed limit,
requestedDelta, subscriptionRevision; ограниченным actors точные workspace counts
не раскрываются. Observe сообщает would-deny metadata, но не меняет текущий auth
outcome и не заявляет, что enforcement уже работает.
