# Milestone 10.0 Phase 4A — Dev2Done v0.9.14

Base: local v0.9.13 (`868a08e` release, `fcbbd96` documentation head). Branch `codex/milestone-10-0-phase-4a`. The operator reports Phase 3 installed on Jino, successful backfill and Compatibility/Observe: 7 projects, 3 developer seats, 4 unique clients, 1 agent, 8 mappings, no failed/review records. These are supplied production facts, not measurements performed by this release task.

Implemented: non-enforcing ShadowGuard; bounded workspace-first current allocation capture; owner/primary resource selection; frozen previews with 15-minute expiry and fresh reopen/replay verification; accounting confirmation/separation with evidence/revision/idempotency/audit; pure future batch contracts; webhook transport outside workspace transactions; compact inventory/catalog and default `#5b4bd6` tokens preserving custom workspace colors.

Owner entry: `/admin/settings/resource-preview`. Primary entry: inventory → workspace → Select resources. Ordinary platform administrators cannot access plan preparation. Public demo cannot use the preparation API or write accounting. No apply endpoint, commercial executor, Trial activation, automatic downgrade, resource suspension, blocked epoch or new quotas exists. Even configured `enforce` still observes.

Existing schema suffices: **no new migration, mandatory env key, automatic operator command or DB branding rewrite** in 4A. Metadata persists in Phase 1 previews/items/events and Phase 3 observations/accounting. Existing user/SSO/Magic Link permissions remain authoritative.

Read [coverage](COVERAGE.md), [protocol and locks](PROTOCOL.md), [API](API.md), [QA](QA.md), [production runbook](PRODUCTION.md), [artifacts](ARTIFACTS.md) and [remaining work](NEXT.md). TR/ZH-CN preparation strings use EN, matching plan-management fallback. Production and Static Demo are separate packages; historical releases remain available.
