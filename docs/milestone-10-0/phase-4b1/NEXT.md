# Phase 4B.2 prerequisites — do not start automatically

Public commercial activation requires a separate reviewed release/action. Neither visible forms, a provider configuration nor an env flag can enable it in Phase 4B.1.

Required before rollout:

- Independently approved, validated published Studio/Basic versions and platform policy, pinned by immutable version/hash; no deployment-time auto-publication.
- Exact verified-time seven-day Trial semantics, idempotent atomic activation and a reviewed decision for dormant Phase 4B.1 pending claims; delayed MFA/agreement must not silently retime an existing Trial.
- Expiry/catch-up execution after cron downtime, with no Trial Grace; automatic Basic fallback only after frozen decision/input revalidation.
- Suspension and execution-epoch barriers across business writes, API/agents, webhook/notification background jobs, exports and resource activation, including in-flight operations and stale worker fencing.
- Basic resource selection/reselection, safe client/developer occupancy and authoritative retention/storage accounting with missing/stale measurements failing safely.
- Reviewed manual paid restore, renewal/downgrade semantics and credentials/privacy boundaries; no checkout is inferred.
- Independently approved durable subject retention, deletion/anonymization and APP_KEY/HMAC rotation plan. No blanket alias deletion that reopens Trial eligibility.
- Real provider consent/callback and SMTP verification on approved staging, without credentials in logs/bundles; callback log redaction and secure proxy/cookie configuration.
- Operational expiry/fallback recovery drills, rollback rehearsal from a restored database, owner/client/API privacy regression and explicit rollout/monitoring criteria.

Compatibility/legacy workspaces must remain outside new-owner Trial classification. This phase does not authorize production policy changes or lifecycle execution.
