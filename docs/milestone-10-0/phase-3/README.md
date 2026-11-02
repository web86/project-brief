# Dev2Done — Milestone 10.0 / Phase 3

База `81bf0fd`, ветка `codex/milestone-10-0-phase-3`. Реализовано Inventory + Accounting + Compatibility Observe + Real Preview. D1–D12 сохранены. Восемь исходных архитектурных документов описывают утверждённые правила; фактические границы реализации теперь описаны Phase 1, Phase 2 и этим каталогом. Их исторические слова «реализация не начата» не являются текущим статусом.

## Поведение

- Контролируемый `LegacyInventory` создаёт одну hidden published compatibility version, только через явный операторский `plans:inventory --apply`. Capacity unlimited типизирована; API/Agents/Webhooks/SSO/branding отражают legacy compatibility, FormWatch остаётся unavailable. Resolver не является разрешением операции. Ни один security/scope/object guard не заменён тарифом.
- Только отсутствующие subscription/current period создаются в Observe, бессрочно, без Trial, Grace или Basic. Уже закреплённые subscriptions, periods, approved extras, overrides и revisions не переписываются. Existing policy/rollout settings сохраняются; добавляется только отсутствующий legacy pointer.
- `plan_inventory_cohorts` фиксирует ID cutoff, UUID, UTC capture и revision. Workspace, созданные во время shadow phase после cutoff, добавляются **только явным** `--extend-cohort`. Перед будущим commercial launch Phase 4 обязана расширить/finalize cohort под registration activation barrier; текущий cutoff не разрешает Trial новым workspace.
- `plan_inventory_items`: pending / processed / needs_review / failed, отдельные client/invitation cursors и high-watermarks. Transaction на один workspace chunk, workspace lock первым, <=200 clients и <=200 invitations. Checkpoint и metadata-only audit атомарны. Два workers сериализуются mutex и не создают дубликаты. Общей транзакции всей платформы нет.
- `ClientCountingIdentities` сохраняет UUID и mapping, использует persisted consumed source-bound SSO evidence с точным workspace/source/project/client/email соответствием; raw external ID не proof. Затем contact anchor как accounting hint и normalized email fallback. Aliases/input fingerprints — HMAC от существующего APP_KEY с отдельными namespace. Plaintext accounting email indexes не создаются.
- Разные proven subjects с общей mailbox не объединяются. Alias становится ambiguous, identities сохраняются отдельно, preview unknown. Изменившееся provenance/email или mapping требует review; автоматического merge User/ProjectClient/SSO/Magic Links и изменения доступа нет. Нет endpoint для ручного «исправить identity»: reviewed remediation — отдельная Phase 4 задача.
- Clients usage — distinct accounting identities в active links эффективно active, не удалённых проектов. Missing/stale/foreign mapping не превращается в ноль. Inactive/trash исключаются, restore/reactivate возвращают тот же UUID в union. Contact directory без участия не занимает slot.
- Human usage — distinct active owner/admin user IDs, без зависимости от project assignment. Owner включён. Occupied добавляет distinct действующие приглашения, связывает известного User и текущий normalized email. Accepted заменяет hold membership; повторные приглашения/проекты не удваивают seat. Истечение/revoke/account email edits пересчитываются из текущих фактов, не старого hash. Project-scoped invitations повторяют существующие manager/assignment правила. Неоднозначное состояние — incomplete.
- Shadow reservation sync с rollback savepoint и безопасной metadata diagnostic при сбое (без тарифного отказа бизнес-операции) подключён к create/issue/resend/accept/revoke и bulk revoke при удалении администратора; inventory восстанавливает старые holds. После account email change существующий stored hash может требовать refresh; current union вычисляется заново и остаётся authoritative. Тарифных отказов при приглашениях/accept нет.
- Agents — distinct active, non-deleted ExternalAgent identities всех legacy kinds; shared key и количество assignments не меняют count.

## Observe и preview

`WorkspaceUsage` даёт ready/incomplete/unavailable, count/null, UTC as_of, provenance, reason codes; human_count и invitation_slots разделены. Canonical capacity по-прежнему только developer_seats; developers.max не editable.

`WorkspaceImpact` разделяет current facts, выбранную published non-compatibility target version/hash/revision, simulated limits, would_allow/would_deny/unknown и неизменённую прежнюю authorization. Target extra seats явно симулируются как 0, прошлые grants не переносятся. Branding присутствует как configuration indicator, точный field-tier mapping требует review. API/SSO/webhook indicators показывают настроенные ресурсы, а не историю их исполнения.

Даже configured `enforce` возвращает `effectiveMode=observe`, `executionGuardsEnabled=false`, `enforcement_activation_unavailable`. Новых hooks в authorization/execution нет. Preview и dry-run не записывают бизнес-данные; обычные session/security/platform audit middleware продолжают свою прежнюю работу.

Четыре поддержанных capacity metrics ready только при достоверном accounting. Storage bytes/files, AI/run quotas — planned/unavailable, FormWatch — unavailable. Existing open suspension cycle возвращает incomplete, count=null: подготовленный explicit-held predicate исключает materialized held resources, но future high-watermark cycle executor ещё не реализован. Phase 3 циклы/holds/epochs не создаёт и не снимает.

Скан одного workspace ограничен 20 000 client/member/invitation/token rows на provider; превышение означает incomplete, не частичный ready count. Platform list: keyset <=50 workspace на запрос, default25. Coverage — явно сохранённые checkpoint states, live readiness/overages — явно текущая страница. Для полного live отчёта оператор последовательно собирает страницы; UI не выдаёт page totals за всю платформу. Нет N+1 по задачам и чтения task/chat/file content.

## Интерфейс и доступ

`/platform/plans/inventory`, `/platform/plans/inventory/{workspaceUuid}`. Existing Dev2Done shell/sidebar, RU/EN, light/dark/system, desktop/mobile; unknown без зелёного success, unavailable metrics отдельно, UTC и drift notice. Сравнение не выбирает ресурсы, не выполняет suspension, ничего не меняет.

Existing PlanAuthority + verified account/MFA/strong/fresh proof, CSRF/session revocation, rate limit, no-store/no-referrer. Обычный platform_admin сохраняет старую диагностику, но не получает новые plan operations. Server shell и API защищены независимо от Vue. В platform output только workspace/version IDs, counts/configuration/reasons; email, task titles, chat, private reports, files/paths и credentials отсутствуют.

[Операторский протокол и обновление](PRODUCTION.md) · [API](API.md) · [Lock review](LOCKS.md) · [QA](TESTS.md) · [Phase 4](NEXT.md).

Финальный production ZIP, exact commit, SHA-256 и QA: [ARTIFACTS.md](ARTIFACTS.md).
