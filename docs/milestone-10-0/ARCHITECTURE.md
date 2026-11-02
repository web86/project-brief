# Milestone 10.0 — Dynamic Plans & Entitlements

Статус: **архитектура обновлена по утверждённым решениям D1–D12; реализации нет**.
Дата source audit: **2 ноября 2026**, Europe/Istanbul. Предыдущий проект от
31.10.2026 на v0.9.8 заменён этим документом. D1–D12 не являются открытыми
вопросами; технические предложения ниже ещё требуют implementation review.

Проверенная база — v0.9.10 с hotfix Agent & Webhook Simplification:
исходники `7ec07e61b5755c415def95e01cd24c38ad9e5e75`, runtime ZIP из `bd09dd5`,
публикация main `0a7ef4560ef8ddc21e20223321bd5ff5f55335dd`.
Сравнён диапазон v0.9.8 `8e2795b` → v0.9.10 `7ec07e6`:
107 изменённых файлов в api/src/tests. Публикационный main содержит тот же код,
исключая исторические QA-картинки. Это source audit, без доступа к production DB,
секретам, клиентскому содержимому и без запуска приложения или AI-провайдеров.

## Документы

| Документ                           | Назначение                                                |
| ---------------------------------- | --------------------------------------------------------- |
| [DATABASE.md](DATABASE.md)         | Предлагаемая additive schema и инварианты                 |
| [ENTITLEMENTS.md](ENTITLEMENTS.md) | Registry, тарифы, identities, counts, guards и ledger     |
| [TRANSITIONS.md](TRANSITIONS.md)   | Версии, Trial/Grace, downgrade, восстановление, retention |
| [API.md](API.md)                   | Предлагаемые контракты и доступ                           |
| [UI.md](UI.md)                     | Экраны платформы и владельца, состояния и UX              |
| [MIGRATION.md](MIGRATION.md)       | Compatibility/Observe, этапы внедрения, rollback          |
| [TEST-PLAN.md](TEST-PLAN.md)       | Проверки документации и будущей реализации                |

Все новые таблицы, сервисы, маршруты и команды — **проект**, а не существующий API.
Цены, численные AI/FormWatch/storage quotas и сроки retention не утверждены.
Работа ограничена восемью Markdown-файлами; production implementation, commit,
push, deployment и новый релизный ZIP в это задание не входят.

## 1. Аудит актуальной базы

| Область и исходники от корня репозитория                                                                   | Фактическое состояние v0.9.10                                                                                                                                  | Требование к 10.0                                                                                          |
| ---------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| `CurrentWorkspace.php`, `WorkspaceMember.php`, `ProjectPermissions.php`                                    | Активные owner/admin, project assignments, owner-only создание, `DEFAULTS`; legacy users.role не разрешение                                                    | Human seats по user_id; entitlement дополняет текущую authorization                                        |
| `EnsurePlatformAdmin.php`, `SetPlatformAdmin.php`, `User.php`                                              | Boolean platform_admin, MFA/strong/fresh session; CLI может назначить нескольких администраторов                                                               | Отдельная singleton authority главного администратора тарифов; не повышать всех platform_admin             |
| `OwnerProvisioning.php`, `DeveloperInvitations.php`, `AccountProfile.php`                                  | Регистрация/присоединение существующих людей, email verification, один email — один User; возможен приём старого workspace первым владельцем                   | Trial только подходящему новому владельцу нового workspace; импортированный legacy не превращается в Trial |
| `ProjectAccess.php`, `TaskAccess.php`, `ProjectAccessToken.php`, `ProjectLifecycle.php`                    | Inactive исключает клиентский Magic Link и AgentAccess, но общий human TaskAccess сам не запрещает все записи в inactive; trash возвращает status_before_trash | Единый project activity guard; tariff suspension отдельно от status/inactive/trash                         |
| `ClientIdentities.php`, `ClientSso.php`, migrations provisioning/SSO                                       | Nullable workspace_client_id; workspace_clients — контакты с email_hash; SSO binding ограничен source/workspace, не глобальный client account                  | Стабильные accounting UUID с проверяемым provenance и fallback; не менять auth identities                  |
| `AgentCredentials.php`, `IntegrationTokens.php`, shared-credential migration                               | Один shared key у нескольких identities; явный X-ProjectBrief-Agent-ID; link UUID — generation; individual keys сохранены                                      | Agent API отдельно от полного API; guard всех путей ключей, без изменения scopes/hash/bindings             |
| `AgentPermissions.php`, `AgentAccess.php`, `AuthenticateIntegration.php`                                   | Scopes — потолок операций, сохранённые registration permissions — legacy metadata; workspace/grant/membership/assignment/object checks обязательны             | Не возвращать старый permission ceiling v0.9.8; тариф не выдаёт отсутствующие права                        |
| `AgentCollaborationController.php`, `AgentRuns.php`, `AgentRunApiController.php`, `AgentApiController.php` | Приватный чат, вложения, initial/deferRun и повторные поручения, queued/running runs, frozen context, explicit claim                                           | Guard до submission replay, context read, start и каждого result/file write; устаревшие runs не оживают    |
| `AgentResultController.php`, `AgentChat.php`, comment/file/export filters                                  | Human создаёт отдельную выбранную team/client copy; private original остаётся team-only                                                                        | Чтение истории владельцем сохраняется; нет автоматической публикации при downgrade/restore                 |
| `IntegrationWrites.php`                                                                                    | Workspace lock, token/scope/assignment recheck перед idempotency replay; shared dedupe включает identity + binding generation                                  | Добавить текущие entitlement/project/epoch checks **до** чтения success replay                             |
| `IntegrationController.php`, `AgentController.php`, `WebhookEvents.php`                                    | Независимые webhooks external_agent_id=NULL, все 7 AgentAccess::EVENTS, mixed subscriptions; agent save не управляет webhookUrl                                | Webhooks имеют собственный paid gate; endpoint не предоставляет Agent API доступ                           |
| `WebhookProcessor.php`                                                                                     | Для personal receiver workspace lock предшествует lease/endpoint, для независимого — пока нет; HMAC/TLS/SSRF/retries                                           | Review lock order и pre-send guard для **всех** получателей, не только personal                            |
| `ClientSso.php`, `ProjectAccessToken.php`                                                                  | issue/consume/sessionUsable, source/origin/grants/TTL; обычный Magic Link отдельный                                                                            | SSO-only ключ не требует full API; Basic не блокирует основной SPA/API/Magic Link                          |
| `AiTaskAssistant.php`, `AiTaskAssistantRequest.php`, `ApiUsage.php`                                        | Встроенный AI assistant вызывает provider; minute/day Cache/RateLimiter; внешние агенты исполняются вне сервера; telemetry best-effort                         | Разделить AI-запрос, agent run и реальный внешний model call; durable quota не строить на diagnostics      |

В проверенных app/migrations тарифов, Trial ledger, entitlement overlays и
FormWatch adapter пока нет. Push subscriptions относятся к уведомлениям браузера.
Зафиксированные результаты QA v0.9.10 не являются тестами будущего Milestone 10.0.

## 2. Утверждённые продуктовые решения

| Решение | Норма проекта                                                                                                                                                                                                                              |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| D1      | Human developer — активный owner/admin с действующими workspace правами; owner всегда считается. Basic/Freelancer 1, Studio 10 included / hard maximum 50; agents исключены                                                                |
| D2      | Уникальный клиент workspace с устойчивым accounting UUID: проверенные существующие соответствия, затем обоснованный normalized-email fallback, иначе отдельная identity; auth не объединяется                                              |
| D3      | Только эффективно активные проекты и их активно участвующие клиенты расходуют slots; inactive/архив и tariff-suspended исключены; reactivate проверяет оба лимита                                                                          |
| D4      | Published версии immutable; existing subscriptions pinned; publish отдельно от preview/rollout, частичный outcome/resume/audit; копирование правил с проверкой                                                                             |
| D5      | Первоначальный Trial 7 суток полного Studio, без следующего Grace; оплаченный/подтверждённый вручную paid service period → Grace 7 суток прежнего доступа → Basic. UTC, без зависимости от cron; настройки платформы и закреплённые версии |
| D6      | Existing workspace → hidden compatibility + Observe, без Basic/Trial автоматически                                                                                                                                                         |
| D7      | Seats = min(hard maximum, included + approved extra); единая seat policy; holds приглашений и пересчёт состава; extra только с подтверждением платформы                                                                                    |
| D8      | Paid-only внешние API/agents/webhooks/SSO/integrations; полный Trial/Grace — допустимые контексты. Freelancer: agent API да, full API/webhooks/SSO по умолчанию нет. Studio agents без тарифного предела                                   |
| D9      | На Basic все прежде активные проекты и non-owner developers приостанавливаются обратимым overlay; owner сохраняет вход/историю/export/subscription, выбирает один проект с не более 3 clients; premium execution закрыт                    |
| D10     | Раздельные retention classes, owner deletion с step-up; automatic destructive purge выключен до подтверждённых staging tests                                                                                                               |
| D11     | Quota Ledger для agents/runs/AI/FormWatch/storage; число agent runs не равно числу model requests; реальный FormWatch пока unavailable                                                                                                     |
| D12     | Только поддержанные workspace/project scopes; изменение scope/window требует preview и migration policy с переносом usage                                                                                                                  |

## 3. Компоненты и общие инварианты

Расширять существующие Laravel services/controllers и Vue/Pinia; без нового auth
stack, role engine, billing SDK, expression DSL, постоянного worker или Redis как
обязательной зависимости. Сохранять MFA, agreements, tenant isolation и privacy.

| Предлагаемый компонент                            | Ответственность                                                                                                      |
| ------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `PlanAuthority`                                   | Узкий capability check; сейчас только один primary platform administrator, future delegation без ролей/UI            |
| `EntitlementRegistry` / `PlanCatalog`             | Типы, supported scopes, paid requirement, availability, версии и validation                                          |
| `PlanImpact` / `SubscriptionChanges`              | Metadata-only preview; единый executor назначения, seats, renewal, rollout, восстановления                           |
| `SubscriptionClock` / `EntitlementResolver`       | UTC boundaries и pinned policy; предсказуемый snapshot, validUntil, effective Basic при пропущенном cron             |
| `WorkspaceUsage` / `ClientCountingIdentities`     | Те же authoritative counters в preview и mutation; accounting не authentication                                      |
| `ProjectActivityGuard` / `WorkspaceParticipation` | Единое effective active состояние для людей/клиентов/проектов; overlay не переписывает роли/status                   |
| `ExecutionGate`                                   | Current feature, project/resource release, execution epoch и pre-replay/pre-send проверки                            |
| `QuotaLedger`                                     | Reserve/commit/release/uncertain и durable dedupe; отдельный adapter на реальное потребление                         |
| Bounded commands / notification outbox            | Backfill, due transitions, cancel stale work, notices/reconcile; lease/checkpoint; cron не является источником права |

Permission = existing authorization AND effective participation AND entitlement
AND safety limits. Workspace определяется проверенным объектом/token, не чужим
session selection. Platform authority не даёт доступа к приватным проектам.

Единый `developer_seats` policy document хранит included/hard maximum/extra policy;
`developers.max` — **read-only derived** effective capacity, не второй plan input.
Нет editable дубля hard maximum в entitlement values или override.

Trial/Grace/fallback и retention описывает immutable platform policy version.
Активный период закрепляет её вместе с конкретными entitlement/fallback версиями;
изменение глобального pointer не меняет уже начавшийся Trial/Grace неожиданно.

Tariff loss устанавливает suspension cycle и execution barriers за bounded O(1)
транзакцию. Ресурсы логически приостановлены сразу, даже до batch materialization
100 проектов; исходные statuses/memberships/credentials не переписываются.
Старые queued/running runs и deliveries становятся terminal для исполнения;
history/context/files сохраняются. После paid restore owner отдельно подтверждает
выбранные ресурсы и интеграции; новая эпоха не означает replay старой работы.

## 4. Главный администратор платформы

Существующий platform_admin остаётся prerequisite, но не достаточным правом.
Singleton `platform_plan_authority` указывает одного primary user. Все catalog,
subscription, lifecycle/retention settings, grants и approvals требуют match,
свежую MFA/strong session и audit. Другие platform_admin сохраняют только прежнюю
диагностику; owner/project superadmin/agents не управляют платформенной политикой.

Bootstrap — отдельная будущая интерактивная operator-only CLI процедура при пустом
singleton: независимая проверка существующего аккаунта, verified email, MFA и
recovery readiness, точная фраза подтверждения, атомарное назначение и audit.
Первый зарегистрированный user и первый platform_admin **не** выбираются автоматически.
Recovery/transfer требует отдельной operator verification, смены authority revision,
отзыва security proofs/sessions и повторного входа; не открывать web recovery bypass.
Незавершённый ручной rollout при утрате authority требует нового review. System
expiry по ранее закреплённой политике работает даже при недоступности primary.

## 5. Перечень изменений относительно прежней архитектуры

| ID  | Изменение                                                                                                             |
| --- | --------------------------------------------------------------------------------------------------------------------- |
| C1  | База v0.9.8 заменена аудитом v0.9.10, включая shared credentials, chat/runs, scope-only access и independent webhooks |
| C2  | Предложения D1–D12 заменены утверждёнными нормами; неутверждённые quotas/цены отделены                                |
| C3  | Дублирование developers.max/hard_max устранено: один developer_seats policy и derived result                          |
| C4  | Email-only counting заменён стабильными accounting identities; inactive clients/projects больше не расходуют slots    |
| C5  | Generic restricted после 14 дней заменён двумя сценариями Trial 7 → Basic и paid → Grace 7 → Basic                    |
| C6  | Добавлены verified-email eligibility, owner-level Trial claim, pinned platform policy и защита повторного Trial       |
| C7  | Появилась обратимая tariff suspension отдельно от status/inactive и roles/memberships                                 |
| C8  | Добавлены Basic activation с выбором <=3 клиентов и explicit paid restoration без auto-run                            |
| C9  | Agent API отделён от full API; paid-only registry validation действует и на overrides; SSO-only path также отдельный  |
| C10 | Execution epoch/barriers, pre-replay/pre-send checks и chronological catch-up закрывают delayed-cron/re-enable обходы |
| C11 | Retention разделён по классам, добавлен owner deletion workflow и staging gate для automatic purge                    |
| C12 | Plan authority выделена из общего platform_admin; bootstrap/recovery и future delegation boundary                     |
| C13 | Registry/ledger расширены agents.active.max, agent runs, AI consumption и FormWatch с честным availability            |
| C14 | UI/API/migration/test plan включают lifecycle settings, suspension, restore, retention и все новые guard paths        |

## 6. Выявленные и разрешённые противоречия

| Прежняя формулировка                                         | Исправление                                                                                       |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- |
| Все D — ещё не утверждены                                    | D1–D12 утверждены владельцем; implementation review не пересогласует их молча                     |
| Inactive clients продолжают считаться                        | D3 исключает клиентов inactive/suspended проектов; общий activity guard закрывает обход           |
| Email — единственная identity                                | UUID accounting identity + проверяемые aliases; не merge реальных аккаунтов/прав                  |
| developers.max и seat_policy.hard_max одновременно editable  | Второе хранимое значение удалено, developers.max derived                                          |
| Grace 14 / restricted / не деактивировать людей              | Grace 7 только после paid; Basic и обратимая приостановка non-owner participation                 |
| api.enabled обязателен всем агентам                          | api.agents.enabled отделён от api.external.enabled, Freelancer поддержан без full API             |
| Agent webhook требует API-флаг и конкретного агента          | Независимый webhook требует webhooks.enabled; подписка на агентские события сама не даёт API-прав |
| Любой platform_admin управляет тарифами                      | Только singleton primary authority + existing security gates                                      |
| Возврат функций достаточно проверяет токен                   | Нужны release confirmation, текущие grants и новая execution epoch, без накопленного replay       |
| Новому workspace сразу Basic; retention неопределённый общий | Новому eligible owner Trial с UTC boundary; retention classes отдельно, active free защищён       |

## 7. Оставшиеся технические риски и решения до реализации

| Риск                                                                                                          | Требуемое доказательство / решение                                                                                                                                    |
| ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1: Lock inversion в human, independent webhook и Agent API transactions                                      | Общий workspace-first protocol, current reads и MariaDB concurrency/lock-order tests                                                                                  |
| R2: Одновременные 100-project downgrade/renew/restore без cron                                                | O(1) barriers/cycle и catch-up всех elapsed boundaries; никаких промежуточных разрешений                                                                              |
| R3: Неоднозначные mailbox/SSO/contact identities или несколько owner memberships                              | Provenance/completeness gate, bounded backfill и owner review без auth merge; ambiguous ownership не переводить в enforce автоматически                               |
| R4: Смена email/ownership или повторная регистрация обходит Trial                                             | Durable claim/user linkage, ограниченно хранимый keyed email subject, verified-email и anti-abuse tests; процедуру ownership transfer включить в review               |
| R5: Внешний агент уже начал LLM до boundary                                                                   | Прекратить новые claims/data/result writes; уже ушедший HTTP/model call физически не отозвать; внешний executor соблюдает cancellation                                |
| R6: Pending events/runs оживают после восстановления                                                          | Epoch/cutoff metadata, cancel terminal outcomes, stale replay rejection и explicit new submission                                                                     |
| R7: Client identity scan/preview слишком дорог                                                                | Индексы, batched counts, EXPLAIN и измерение на synthetic MariaDB; не использовать telemetry как permit                                                               |
| R8: Registry capability без проверенного adapter                                                              | availability=false сильнее plan value; FormWatch не объявлять работающим                                                                                              |
| R9: Численные AI/run/storage quotas, цены, retention сроки не утверждены                                      | Не выдумывать значения; quota adapter/enforcement конкретного нового бюджета включать только после отдельного решения                                                 |
| R10: Граница branding basic/full, AI assistant, owner data-export coverage, export/ads/support не уточнена D8 | Сохранить текущие core/security/export права; определить точный field/operation allowlist до коммерческого ограничения, не наследовать старые предложения как решения |
| R11: Root bootstrap/recovery и authority turnover                                                             | Operator runbook, отсутствие самоэскалации, fail-closed management; expiry не зависит от доступности root                                                             |
| R12: Destructive purge, files/backups и runtime rollback                                                      | Отдельная staging репетиция, durable cleanup journal, backup-retention disclosure; automatic purge off до gate, rollback только к v0.9.10 с reconcile                 |

Цены/квоты и указанные operation boundaries — открытые технические/продуктовые
уточнения, а не разрешение отложить утверждённые D1–D12. Тесты, необходимые до
enforcement, перечислены в TEST-PLAN. Никакой текущий guard ещё не реализован.

## 8. Проверенные внешние технические предпосылки

Блокировки проектируются внутри DB transaction, как рекомендует
[Laravel 13 Query Builder](https://laravel.com/framework/docs/13.x/queries#pessimistic-locking).
MariaDB DDL может делать implicit commit; additive upgrade/retry нельзя считать
одной откатываемой transaction —
[официальная документация MariaDB](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).
Проверено 02.11.2026; это обоснование проекта, не доказательство будущей реализации.
