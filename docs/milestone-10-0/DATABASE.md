# Milestone 10.0 — структура данных

Статус: **предлагаемая схема по D1–D12, migrations/SQL не созданы**.
База v0.9.10 и traceability: [ARCHITECTURE.md](ARCHITECTURE.md).
MariaDB/InnoDB, PHP 8.4; production inventory здесь не проводился.

## 1. Общие инварианты

- Internal PK unsigned bigint, внешний UUID unique; workspace-bound composite FK,
  где связь не polymorphic. Время UTC с согласованной microsecond precision;
  интервалы `[start,end)`, NULL end только у бессрочного free/compatibility.
- Revision unsigned bigint; не заменять updated_at и не передавать JS небезопасные
  bigint как float. UUID/строки в API. Численные limits bounded signed-safe integers.
- Immutable published versions/policies/period facts/events; FK RESTRICT к history.
  Draft можно заменить под lock/revision. Actor FK nullable SET NULL с сохранением
  metadata; не копировать names/emails в events.
- Typed bounded JSON, allowlisted statuses/actions, никакого SQL/class/expression
  input. Price amount_minor integer/null, проверенная currency/scale; нет float,
  платежей, invoice или вывода «оплачено» из цены.
- Audit action <=60 символов; UUID/agent/credential IDs в структурированных полях,
  не в конкатенированной строке action (учтён MariaDB hotfix v0.9.10).

## 2. Authority, каталог и платформенные политики

| Таблица                    | Основные поля                                                                                                                                                                                                   | Ключи и правила                                                                                                                                               |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `platform_plan_authority`  | id=1, primary_user_id?, revision, assigned_at, operator_event_id?                                                                                                                                               | UQ id; FK user RESTRICT; singleton разрешает ровно одного primary. Null до безопасного bootstrap. Не backfill из любого platform_admin                        |
| `plan_settings`            | id=1, current_policy_version_id?, legacy_version_id?, rollout_mode(off/observe/enforce), registry_revision, revision                                                                                            | Published FK RESTRICT; изменяет только primary authority с preview                                                                                            |
| `platform_policy_versions` | id, uuid, number, state(draft/published), trial_days, grace_days, trial_version_id, fallback_version_id, eligibility_json, security_termination_json, retention_json, content_hash, revision, actors/timestamps | UQ uuid/number; published immutable; trial первоначально 7, grace 7; trial target paid Studio, fallback free Basic; pointer update не меняет активные периоды |
| `entitlement_definitions`  | key, kind, allowed_scopes_json, schema_json, paid_requirement, availability, derived, schema_revision                                                                                                           | PK key; read-only mirror code registry. Ни handler names, ни формулы из UI                                                                                    |
| `plans`                    | id, uuid, code, status(draft/published/archived), visibility(public/hidden), published_version_id?, sort_order, revision, actor IDs                                                                             | UQ code/uuid; code immutable после publish; current pointer только для новых назначений                                                                       |
| `plan_versions`            | id, uuid, plan_id, number, state, access_class(free/paid/compatibility), translations_json, price_json, developer_seats_json, registry_revision, content_hash, revision, actor/time fields                      | UQ(plan_id,number), UQ(id,plan_id), UQ uuid; одна editable draft под plan lock; published immutable                                                           |
| `plan_entitlement_values`  | plan_version_id, entitlement_key, scope_kind, value_json                                                                                                                                                        | PK(version,key); FK version/definition RESTRICT; derived keys не записываются                                                                                 |
| `plan_transition_rules`    | id, uuid, source_version_id, target_version_id, initiator(platform/owner), approval(none/manual/payment), timing(immediate/period_end), overage_policy, enabled, revision, actor/time fields                    | UQ(source_version_id,target_version_id,initiator); source != target; immutable published endpoints, mutable audited rule revision                             |

Composite FK `(published_version_id,id) → plan_versions(id,plan_id)` исключает
чужую версию в plan pointer. Циклический nullable pointer добавляется после обеих
таблиц. Settings/policy FK создаются после versions. Policy publish не допускает
archived/unavailable trial/fallback target; pinned исторические ссылки сохраняются.

`access_class` — eligibility entitlement, не бухгалтерское доказательство оплаты.
Paid period активирует платформа manual approval; amount_minor=NULL допустимо,
UI не называет такой grant оплаченным платежом. Published access_class и price.mode
согласованы: free fallback не может быть paid/compatibility; compatibility только
hidden migration assignment, не публичный обход paid constraint. Обычный free plan/override не может
включить paid-only capability. Compatibility — hidden migration-only exception.

`developer_seats_json` — **единственный хранимый источник seat limits**:

```json
{ "included": 10, "hard_max": 50, "allow_extra": true }
```

Basic/Freelancer первоначально 1/1/false. Validator: 0<=included<=hard_max,
hard_max не выше code safety ceiling; initial Studio ceiling 50. Для compatibility
явные typed unlimited values. `developers.max` — derived read-only output;
отсутствует в plan_entitlement_values и override inputs. Workspace approved extra
хранится отдельно как grant, не как независимый maximum. Дополнительный будущий
project ceiling имел бы другой ключ и отдельный provider.

Translations — ru/en/tr/zh-CN, plain text, name<=160, description<=4000; все names
обязательны. UI платформы обязательно RU/EN. Price metadata отделено от features:
mode free/paid, amount_minor, currency, interval month/year/custom_days; цены не
утверждены. Не хранить per-plan grace_days, конфликтующий с platform policy.

Policy retention_json содержит отдельные classes `active_free`,
`suspended_excess`, `closed_abandoned`, `owner_requested`, applicability,
retention_days?, warning_offsets?, action(keep/review/purge), backup policy reference.
Неутверждённые сроки = not_configured, а не 0 дней. Automatic purge gated code
availability + подтверждённой policy; при первом внедрении всегда disabled.

## 3. Подписки, периоды и защита Trial

| Таблица                           | Основные поля                                                                                                                                                                                                                                                                                                                                             | Инварианты                                                                                                                                                                  |
| --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `workspace_subscriptions`         | id, uuid, workspace_id, current_period_id, approved_extra_seats, enforcement_mode(observe/enforce), revision, next_boundary_at?, materialized_phase, timestamps                                                                                                                                                                                           | UQ workspace_id/uuid, UQ(id,workspace_id); один current aggregate; phase — projection, не право                                                                             |
| `subscription_periods`            | id, uuid, workspace_id, subscription_id, kind(compatibility/free/trial/paid), entitlement_version_id, policy_version_id?, fallback_version_id?, starts_at, service_ends_at?, grace_ends_at?, superseded_at?, approval_kind/manual actor/change, timestamps                                                                                                | UQ(id,workspace_id), UQ uuid; composite subscription/workspace; facts append-only, supersession только audited executor; index(service_ends_at,id), index(grace_ends_at,id) |
| `owner_trial_claims`              | id, uuid, owner_user_id?, workspace_id, period_id, subject_hash?, subject_hash_key_version, registered_at, verified_at?, ends_at, state(pending/active/used/expired/denied), reason_code                                                                                                                                                                  | UQ owner_user_id, UQ subject_hash когда подтверждён; NULL user после account deletion не освобождает surviving subject claim; index(workspace_id), index(state,ends_at,id)  |
| `workspace_entitlement_overrides` | id, uuid, workspace_id, subscription_id, entitlement_key, scope_kind, value_json, starts_at, ends_at?, reason_code, approved_by, revision                                                                                                                                                                                                                 | Composite FK(subscription_id,workspace_id); index(workspace_id,key,starts_at,ends_at); overlap запрещён под workspace lock                                                  |
| `subscription_changes`            | id, uuid, workspace_id, subscription_id, kind(assign/transition/renew/seats/enforcement/restore/terminate), target_version_id?, expected_revision, payload_json, rule_id/revision?, policy_snapshot_json, preview_id?, batch_uuid?, requested_by?, approved_by?, authority_revision?, state, effective_at?, operation_key_hash, request_hash, applied_at? | UQ(workspace_id,operation_key_hash); index(state,effective_at,id), index(batch_uuid,id); один approved/scheduled change под workspace lock                                  |
| `plan_events`                     | id, uuid, aggregate_type/uuid, workspace_id?, actor_kind(primary/owner/system/operator), actor_user_id?, action, result, before/after revision, reason_code?, diff_json, change_id?, operation_key_hash?, request_hash?, result_json?, created_at                                                                                                         | Append-only; UQ uuid/operation_key_hash; indexes aggregate/workspace/time; allowlisted configuration/counts/IDs, без content/secrets                                        |

Current_period_id принадлежит тому же subscription/workspace; composite FK после
создания обеих таблиц. Trial end = registration UTC + pinned duration. До verified
email premium запрещён; поздняя verification не сдвигает end. User-level claim
переживает новые workspace/смену email; подтверждённый keyed normalized-email
subject помогает не переиспользовать Trial после пересоздания аккаунта. HMAC ключ
не APP_KEY без key-version/rotation policy; hash не публикуется. Retention такого
anti-abuse tombstone ограничен отдельной policy, не хранится бесконечно по умолчанию. При rotation subject key проверяются все
неистёкшие key versions; смена ключа не обнуляет использованные Trial. Raw keys
находятся только в server secret configuration, не в plan settings/UI.

Trial period имеет grace_ends_at=NULL; paid period — service_end + закреплённая
Grace duration. Free/compatibility бессрочны без искусственного конца. Получение
нового policy pointer не переписывает текущий period. Renewal создаёт новый period
или approved future change, а не сбрасывает Trial/ledger. Ранее истёкший boundary
применяется до нового manual paid assignment, даже при неработавшем cron.

`0<=approved_extra_seats<=hard_max-included`; unlimited included → extra=0.
Extra grant подтверждает primary, owner input — только requested quantity.
Override не меняет paid eligibility, type/scope/window, safety ceiling или seats.
Operation replay восстанавливается по unique event/change outcome лишь после
повторной authorization. Audit failure откатывает mutation, не пропускается.

## 4. Обратимая приостановка и execution barriers

| Таблица                                 | Основные поля                                                                                                                                                                          | Назначение                                                                                                                         |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `workspace_suspension_cycles`           | id, uuid, workspace_id, source_period_id, source_change_id?, effective_at, reason_code, fallback_version_id, state(open/resolved), resource_high_watermarks_json, revision, created_at | UQ(workspace_id,source_period_id,reason_code); index(workspace_id,state,id). Bounded marker при downgrade, а не 100 status updates |
| `workspace_entitlement_resource_states` | id, workspace_id, cycle_id, resource_kind, resource_id, resource_uuid, state(held/released), baseline_revision?, reviewed_revision?, reason_code, confirmed_by?, changed_at            | UQ(cycle_id,kind,resource_id); composite cycle/workspace; index(workspace_id,kind,state,resource_id)                               |
| `workspace_execution_epochs`            | workspace_id, feature_group, epoch, state(ready/blocked/needs_confirmation), blocked_at?, resume_at?, confirmation_change_id?, revision                                                | PK(workspace_id,feature_group); группы из registry, не пользовательские строки                                                     |

Resource kinds ограничены: project, workspace_member, project_client,
external_agent, integration_token, webhook_endpoint, integration_source.
Это внутренние ссылки, не произвольная ORM/table строка. Для polymorphic ссылки
общий FK к ресурсам невозможен: service обязан проверить ID+UUID+workspace под
lock; composite FK к cycle обязателен. Если implementation review выберет typed
FK дочерние таблицы, семантика и API остаются теми же, без общего ACL engine.

Preexisting определяется зафиксированными под workspace lock high-watermarks IDs
по allowlisted resource kinds, не сравнением с произвольным browser timestamp.
Все такие ресурсные create paths используют тот же mutex; количество markers
фиксированное, вне зависимости от 100/1000 проектов.

Открытый cycle логически удерживает preexisting проекты/non-owner memberships и
premium ресурсы; explicit released exception действует только после текущей
проверки исходного состояния/прав. Owner всегда исключён. Inactive/trash/revoked
не превращаются в active при release. Выбор <=3 клиентов для Basic добавляет
held states только для лишних project-client participations; base active и links
сохраняются. При смене участия/identity пересчитывается distinct union.

Epoch повышается при потере execution feature. В существующие agent_runs,
webhook_events/deliveries и релевантные execution idempotency outcomes требуется
additive metadata epoch/group или ссылка на execution authorization receipt.
Точное место выбирается при implementation review, но сравнение до replay/claim/
send/result обязательно. Новая epoch не сбрасывает idempotency key или quota usage.
Поздний batch помечает старую работу cancelled; logical denial действует сразу.
Реальные внешние in-flight calls нельзя физически отозвать после send.

## 5. Accounting identities и резервирование developer seats

| Изменение                                 | Поля / правила                                                                                                                                                                                                                     |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `entitlement_client_identities`           | id, uuid, workspace_id, provenance_kind, revision, timestamps; UQ uuid, UQ(id,workspace_id); accounting-only, не login principal                                                                                                   |
| `entitlement_client_aliases`              | id, workspace_id, alias_kind, issuer_hash, alias_hash, identity_id?, state(usable/ambiguous), provenance_revision; UQ(workspace_id,alias_kind,issuer_hash,alias_hash); FK(identity_id,workspace_id), nullable только для ambiguity |
| `project_clients.entitlement_identity_id` | Nullable additive FK на accounting identity; project/workspace принадлежность проверяется service; backfill затем completeness gate до enforce                                                                                     |
| `entitlement_capacity_reservations`       | id, workspace_id, key, scope_kind, scope_id(0=workspace), identity_hash, developer_invitation_id, expires_at, state(held/consumed/released/suspended); UQ(invitation,key,scope,scope_id); index(workspace_id,key,state,expires_at) |

Verified anchors и email fallback подробно определены в ENTITLEMENTS. Aliases
хранят keyed hashes, не новый набор email plaintext; один hash не даёт доступа.
Сильные различающиеся identities с общей mailbox не объединяются. Ambiguous
alias не связывает нового клиента с произвольным существующим; отдельная identity
и review. Старые accounting UUID сохраняются в истории при пересчёте link.

Reserved occupied seats = union активных user identities и nonexpired held invite
identities. Два приглашения одному человеку не два seats. Accept заменяет hold
membership в той же transaction; нет второго User. Suspended holds после Basic
не держат активное seat и не позволяют accept без нового capacity/permission check.

## 6. Preview, уведомления, quotas и retention jobs

| Таблица                        | Контракт                                                                                                                                                                                                                             |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `plan_previews`                | uuid, actor/authority revision, kind, target UUID/revision/hash, settings/registry revision, policy_json, ready/stale/failed state, summary, checkpoint/lease, ready/expires_at; bounded targets и TTL                               |
| `plan_preview_items`           | PK(preview_id,workspace_id), subscription revision, usage fingerprint, execution epochs, suspension revision, evaluated_at, result_json, apply_state, change_id?, failure_code; index(preview,apply_state,workspace)                 |
| `plan_notification_outbox`     | workspace, period/cycle UUID, notice kind, due_at, delivery state, dedupe hash, metadata; UQ dedupe. Существующий notification/mail channel, без project content                                                                     |
| `entitlement_quota_buckets`    | workspace,key,scope_kind,scope_id,period_start/end,consumed,reserved,revision; UQ(workspace,key,scope,scope_id,period_start), UQ(id,workspace), nonnegative counts                                                                   |
| `entitlement_quota_operations` | uuid, workspace,bucket,logical_operation_hash,request_hash,amount,state(reserved/committed/released/uncertain),lease,provider_reference_hash?; UQ(workspace,logical_operation_hash), composite bucket/workspace                      |
| `retention_jobs`               | uuid,workspace,kind(owner_request/policy),policy_version,scope_hash,expected_revision,scheduled_at,warning/outbox refs,approval/step-up receipt?,legal_hold,state,checkpoint/lease,backup_retention_state; idempotent operation hash |
| `retention_job_items`          | job,allowlisted resource UUID/kind,state,failure_code,cleanup journal reference; UQ(job,kind,uuid), bounded retry/checkpoint                                                                                                         |

Quota tables вводятся вместе с первым реальным adapter; определения публикуются
как planned до численных квот/point-of-consumption. Retention jobs вводятся вместе
с проверяемым удалением. Existing `project_file_deletions`/cleanup journal
переиспользуются для файлов, но workspace assets требуют полного inventory.
Не публиковать private paths/filenames в platform preview/audit. Retention job и
cycle закрепляют применимую policy version; новый pointer не сокращает прошлый
срок без reviewed migration, повторного предупреждения и applicability recheck.

Обязательные индексы проверить по SHOW INDEX/EXPLAIN на disposable MariaDB:
projects(workspace_id,deleted_at,status,id), workspace_members(workspace_id,status,role,user_id),
project_clients(project_id,active,entitlement_identity_id,id), invitation expiry,
run assignment/state и delivery event/status. Не дублировать действующие индексы.
Current-read protocol и порядок locks — [ENTITLEMENTS.md](ENTITLEMENTS.md).
