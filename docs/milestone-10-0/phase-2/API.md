# Phase 2 HTTP contracts

Все management API проходят existing auth/session/CSRF/EnsurePlatformAdmin,
primary PlanAuthority и fresh MFA/UV proof. `plan-access` — только capability
собственного platform admin для навигации; он не раскрывает чужой primary ID.
Rate limit прежний `platform` (30/min per actor). Responses no-store/no-referrer.

| Method / path                                            | Действие                                                       |
| -------------------------------------------------------- | -------------------------------------------------------------- |
| GET `/api/platform/plan-access`                          | `{canManage}`; диагностика других platform_admin сохраняется   |
| GET `/api/platform/entitlement-definitions`              | Code-owned definitions, revision и seat ceiling                |
| GET/POST `/api/platform/plans`                           | Paginated catalog / create draft v1                            |
| GET/PATCH `/api/platform/plans/{plan}`                   | Paginated versions / visibility+sortOrder                      |
| POST `/api/platform/plans/{plan}/draft`                  | Новая draft из current published или `sourceVersionId`         |
| GET/PUT `/api/platform/plans/{plan}/versions/{version}`  | Read / full typed draft replacement                            |
| POST `.../versions/{version}/previews`                   | Ready keep_existing preview, HTTP 202                          |
| POST `.../versions/{version}/publish`                    | Preview/hash/revision-bound publication                        |
| POST `/api/platform/plans/{plan}/archive`, `/unarchive`  | Metadata lifecycle с dependency/revision checks                |
| GET `/api/platform/plans/{plan}/history`                 | Paginated metadata events, без workspace contents              |
| GET `/api/platform/published-plan-versions`              | Paginated eligible non-archived version picker, 100/page       |
| GET `/api/platform/plan-previews/{preview}`              | Собственный plan/policy/rule-copy preview                      |
| GET `/api/platform/plan-settings`                        | Policy versions/current pointer/supported safe policy metadata |
| POST `/api/platform/plan-settings/drafts`                | Создать policy draft, можно clone published configuration      |
| PUT `/api/platform/plan-settings/drafts/{policy}`        | Full typed replacement draft                                   |
| POST `.../drafts/{policy}/previews`, `/publish`          | Policy preview / immutable publication + future pointer        |
| GET/POST `/api/platform/plan-transition-rules`           | Paginated rules / create manual rule                           |
| PATCH `/api/platform/plan-transition-rules/{rule}`       | Revision-bound edit, immutable endpoints identity              |
| POST `/api/platform/plan-transition-rules/copy-previews` | fromVersionId/toVersionId, remap/diff/conflicts                |
| POST `/api/platform/plan-transition-rules/copies`        | Подтвердить matching preview/hash, copies disabled/manual      |

Все unsafe operations требуют Idempotency-Key. PUT/PATCH, archive/unarchive,
new draft и publication/previews требуют `If-Match: "revision:N"`.
Rule copy creation защищена preview hash полного rules fingerprint и authority/
settings revision; существующие rules не переписываются. Смена endpoints rule
создаёт другое правило, а не меняет identity существующего rule UUID.

Create plan body: `code` + full configuration:
accessClass (free/paid), visibility (public/hidden), translations (ru/en/tr/zh-CN,
name/description), price (mode,amountMinor,currency,scale,interval), developerSeats
(included,hardMax,allowExtra), entitlements [{key,scope,value}]. GET version
возвращает тот же `configuration`, UUID, state, number, строковую revision/hash.
Derived/planned/unavailable значения не отправляются как editable entitlements.

Policy body: trialDays,graceDays,trialVersionId,fallbackVersionId,eligibility,
securityTermination,retention. Поддерживаемые policy maps возвращаются в GET
plan-settings; не принимать arbitrary eligibility/retention DSL и purge.

Preview body — только `{policy: "keep_existing"}`. Publication body —
`{policy: "keep_existing", previewId, contentHash}`. Impact state unavailable,
а не нулевое количество workspace. Новая publication не меняет subscriptions.

Safe errors: 401/403 auth/authority, 404 masked parent/object, 409 revision/hash/
preview conflict, отдельный plan_dependencies для archive, 419 CSRF, 422 typed
configuration, 423 existing security_step_up_required, 428 missing If-Match,
429 existing throttle. Unknown rollout/workspace-subscription operations не
зарегистрированы. Ошибки не возвращают raw exception, SQL, private content/paths.
