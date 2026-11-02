# Файлы и схема Phase 1

## Файлы реализации

| Каталог / файл                                                          | Назначение                                                                                     |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `api/app/Services/Plans/EntitlementRegistry.php`                        | Allowlist, canonical types, defaults, scopes, paid eligibility, seats                          |
| `api/app/Services/Plans/PlanConfiguration.php`                          | Bounded configuration validation и canonical hashes                                            |
| `api/app/Services/Plans/PlanAuthority.php`                              | Singleton primary capability, fresh DB User check                                              |
| `api/app/Services/Plans/PlanCommands.php`                               | Transaction, workspace-first locking, revisions outcome/replay, mandatory metadata audit       |
| `api/app/Services/Plans/PlanCatalog.php`                                | Drafts, immutable publication, archive, transition rule preparation, code-owned mirror         |
| `api/app/Services/Plans/PlatformPolicyCatalog.php`                      | Draft/published Trial/Grace/fallback policy, без activation                                    |
| `api/app/Services/Plans/SubscriptionAssignments.php`                    | Explicit initial pin и time-bounded overrides, Observe                                         |
| `api/app/Services/Plans/SubscriptionClock.php`                          | Pure UTC period/phase contract                                                                 |
| `api/app/Services/Plans/EntitlementResolver.php`                        | Pinned batch snapshot, integrity, availability и eligibility                                   |
| `api/app/Services/Plans/WorkspaceUsage.php`                             | Explicit incomplete/unavailable accounting contracts                                           |
| `api/app/Models/Plans/`                                                 | Plan, PlanVersion, PlatformPolicyVersion, WorkspaceSubscription, SubscriptionPeriod, PlanEvent |
| `api/database/factories/Plans/`                                         | Полные синтетические draft fixtures, без publication                                           |
| `api/database/seeders/PlansFoundationSeeder.php`                        | Explicit idempotent initialization, только drafts                                              |
| `api/database/migrations/2026_11_02_082151_create_plans_foundation.php` | Additive schema, FK/index recovery, immutable triggers                                         |
| `api/tests/Feature/PlansFoundationTest.php`                             | Контракты и domain integration                                                                 |
| `api/tests/Feature/PlansFoundationMigrationTest.php`                    | Populated v0.9.10 upgrade, DDL interruption/retry                                              |
| `api/tests/Feature/PlansFoundationConcurrencyTest.php`                  | Два независимых PHP workers с общей стартовой barrier                                          |
| `tests/plans-foundation.php84.sh`                                       | Изолированная application copy, readonly исходники, exclusive disposable MariaDB               |

Существующие runtime-файлы не редактировались. Версия приложения остаётся 0.9.10.

## Новые таблицы: 27

| Группа                              | Таблицы                                                                                                                                           |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Authority / settings                | `platform_plan_authority`, `plan_settings`                                                                                                        |
| Registry / catalog                  | `entitlement_definitions`, `plans`, `plan_versions`, `plan_entitlement_values`, `plan_transition_rules`, `platform_policy_versions`               |
| Subscriptions / history             | `workspace_subscriptions`, `subscription_periods`, `owner_trial_claims`, `workspace_entitlement_overrides`, `subscription_changes`, `plan_events` |
| Future verification metadata        | `user_external_identities`, `owner_trial_claim_subjects`                                                                                          |
| Reversible participation / barriers | `workspace_suspension_cycles`, `workspace_entitlement_resource_states`, `workspace_execution_epochs`                                              |
| Client accounting                   | `entitlement_client_identities`, `entitlement_client_aliases`                                                                                     |
| Invitation holds                    | `entitlement_capacity_reservations`                                                                                                               |
| Storage metadata                    | `workspace_file_usage`, `workspace_storage_reservations`                                                                                          |
| Preview / notices                   | `plan_previews`, `plan_preview_items`, `plan_notification_outbox`                                                                                 |

Internal bigint IDs, внешние UUID, bounded names/statuses, UTC DATETIME(6), explicit
короткие имена constraints/indexes. Singleton IDs допускают только 1. Одна draft
версия на plan обеспечена unique generated `draft_plan_id`, номер — unique
(plan_id,number). Исторические ссылки RESTRICT; nullable actor User refs SET NULL.

Composite FK защищают current plan version, current subscription period, period/
subscription/workspace, overrides/changes, suspension cycles, resource states,
client aliases, cleanup usage и preview/change references. Polymorphic resource
ownership и project-client accounting completeness не подменяются одним FK:
их проверка остаётся обязательным будущим adapter contract.

## Additive изменения существующих таблиц

| Таблица                                                                      | Nullable metadata                                                                                               |
| ---------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| `project_clients`                                                            | `entitlement_identity_id` → accounting identity; без backfill                                                   |
| `agent_runs`, `webhook_events`, `webhook_deliveries`, `integration_requests` | `execution_epoch`, `execution_feature_group`; старые значения NULL, без runtime guards                          |
| `project_file_deletions`                                                     | `workspace_id`, `file_usage_id`, `component_ref`, `verified_deletion_receipt_json`; cleanup behaviour сохранено |

MariaDB получает недостающие composite indexes для будущих counters:
projects(workspace_id,deleted_at,status,id),
workspace_members(workspace_id,status,role,user_id),
project_clients(project_id,active,entitlement_identity_id,id),
developer_invitations(workspace_id,expires_at,id).
Совпадающие существующие prefixes не дублируются. Это target-MariaDB оптимизации;
SQLite сохраняет прежние legacy rollback fixtures. Run/delivery indexes сохранены.

## Что намеренно отсутствует

`entitlement_quota_buckets`, `entitlement_quota_operations`, `retention_jobs`,
`retention_job_items` вводятся вместе с реальными adapters согласно DATABASE.md.
Пустые подготовительные таблицы не означают работающий quota/storage ledger,
preview executor, OAuth, notification sender, suspension или execution barriers.
