# Project Brief v0.9.10 — Jino update and rollback

Baseline v0.9.9. Deployment is a separately authorized operation. Local release QA
does not authorize push, deployment or a production database change.

## Additive migration

`2026_11_01_111241_add_shared_agent_credentials.php` adds the default-false
`integration_tokens.agent_shared`, current generation-bound `agent_credential_links`
and nullable `agent_operations.claimed_agent_uuid`. The operation actor FK becomes
nullable to retain rejected/unknown claimed identities without inventing an agent.
Existing tokens, hashes, scopes, grants, memberships, run snapshots and reports are
not rewritten. New index names explicitly fit MariaDB's identifier limit.

Individual credentials keep their requests and identity. New shared credentials
require the explicit linked-agent header. Revocation archives credentials in place
and hides them from normal lists; it does not erase audit or result history.

## Separately authorized deployment

1. Verify the exact ZIP hash/manifest. Preserve a matching DB, private storage,
   runtime, `.env` and original APP_KEY backup; test recovery on isolated staging.
2. Enter maintenance mode with `php artisan down`, pause the owned webhook cron briefly and let active
   requests finish. Replace matching private/public runtime files, preserving
   secrets, APP_KEY, storage and permissions from DEPLOY-JINO.md.
3. Run `php artisan migrate --force --no-interaction`, then `php artisan optimize:clear`
   and the existing production cache commands. MariaDB DDL can implicitly commit:
   inspect any interrupted update, columns, FKs and indexes before resuming.
4. Check MFA/session/SSO, ordinary integrations and legacy individual agents.
   On synthetic staging data, check an unbound shared key, two explicit agent
   bindings, private chat/files, first and repeat instructions, frozen snapshots,
   human publication, detach/revoke and the webhook receiver/HMAC/retry.
5. Check task editing before agreement and rejection of a stale description after
   agreement. Estimates/status/private notes retain their separate workflow.
6. Resume the existing bounded `integrations:webhooks` cron; run `php artisan up`
   only after smoke checks and separate deployment approval. No LLM, daemon, Node,
   tunnel or provider credential is installed on Jino by this patch.

Owner checklist: AGENT-LIVE-SMOKE-RU.md in the repository / agent-api directory.
It is a manual guide; production smoke is not run automatically.

## Rollback

Prefer a forward fix and retain additive schema/history; down() refuses destructive
rollback. A runtime-only downgrade to v0.9.9 disables shared keys (the old runtime
cannot authenticate their agent identity). Their agent-only scopes cannot become
ordinary integration privileges. Existing individual keys retain their behavior.
Stop or cancel ambiguous claimed work before changing the external executor;
never automatically take over a claimed run. Keep the original APP_KEY.

A backup restore is a separate coordinated recovery decision, preserving current
state and evaluating later data loss before restoring matching DB/storage/runtime.
Actual production recovery, Jino/FPM load, live SMTP/Telegram and physical-device
or Safari/WebKit behavior are not established by local checks.
