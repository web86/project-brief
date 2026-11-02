# Phase 3 — read-only HTTP contracts

Все новые routes находятся под existing EnsurePlatformAdmin + EnsurePlanAuthority: primary, MFA/strong/fresh proof, existing session/CSRF/rate limits. Shell `/platform/plans/inventory[/{workspaceUuid}]` защищён сервером как существующие plans screens. API response no-store/no-referrer.

| GET path                                            | Input / result                                                                                                                                                                                                |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/api/platform/workspace-inventory`                 | `after` exclusive decimal ID, `limit` 1..50 default25, optional `versionId` published UUID. data workspace UUID/state/reasons, persisted coverage, current-page live summary/overages, meta nextAfter/hasMore |
| `/api/platform/workspace-inventory/{workspaceUuid}` | Optional `versionId`; usage, current pinned subscription metadata, policy diagnostic, configured feature indicators, target hash/revision/limits and shadow decisions                                         |

UUID binding и allowlisted query fields проверяются сервером. Draft/compatibility target — 404; archived target — 422. Unknown fields/page bounds — 422, чужой/malformed workspace — 404, no authority — 403, expired proof — existing 423. Никаких POST/PATCH/apply или owner subscription routes в Phase 3 нет.

`usage[key]`: state ready/incomplete/unavailable, count integer/null, as_of UTC, reason/reason_codes, provenance. Clients дополнительно observed_count/missing_mappings; developer seats human_count/invitation_slots/reservation_refresh_required. Observed incomplete values не являются capacity permit.

`decisions[]`: key, state would_allow/would_deny/unknown, actual, measurementState, typed limit/null, excess/null, reasonCodes. Numeric excess — минимальное число units сверх target; это не автоматически выбранный active set. При unknown excess=null. Functional loss — configured feature indicator, без выключения. Branding precise tier field policy требует review.

`target`: concrete published version UUID/number/revision/hash/registryRevision, simulated limits, approvedExtraSeatsSimulated=0. `previewOnly=true`, `dataPreserved=true`, authorization existing_permissions_unchanged, driftWarning diagnostic_snapshot_not_a_reservation. Сохранённый plan configuration preview Phase 2 остаётся keep_existing; реальные workspace сравнения — этот отдельный bounded provider, не rollout approval.

Coverage: total, uninitialized subscriptions, compatibilityObserve, checkpointStates. pageSummary: current_page ready/needsReview/incompleteAccounting/potentialOverages. Несколько страниц имеют собственное asOf; единый атомарный platform-wide snapshot не обещается. Список не раскрывает workspace names, people, contact emails, project/task titles, chat/files/private reports или keys.
