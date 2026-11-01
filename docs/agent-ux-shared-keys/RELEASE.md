# Project Brief v0.9.10 — Agent & Webhook Simplification

Hotfix on `codex/release-0-9-10-team-reviews`, based on `3bb7f3f`.
The previous Team/Reviews release functionality remains included.

## Cause and fix

Creating an agent with a selected key failed for a platform administrator who
owns the workspace: `AgentCredentials::bind()` concatenated the credential UUID
into an audit action of at least 63 characters. `platform_audit.action` permits 60. Strict MariaDB returned SQLSTATE 22001 / error 1406 and rolled back the
transaction; ordinary owners and SQLite did not reveal the issue. The hotfix
uses a short action plus structured agent/credential IDs. No error masking,
FK removal, authorization change or schema expansion is involved. Saving the
same key also preserves its link generation across database ID representations.

## Changes

- API keys retain one type filter; a shared key can serve multiple agents.
- Agent UUID is selectable card text only. The modal and outgoing saves omit
  webhook URL; registration edits leave existing receivers and secrets intact.
- New webhook endpoints always have `external_agent_id = NULL`. Integration /
  Agents selects event subscriptions; it creates no entity or stored type.
  All seven agent events are offered without an agent-name selector.
- One receiver handles multiple agents with the correct `agentId` payload.
  Mixed ordinary subscriptions remain selected when editing.
- Existing HMAC, TLS/SSRF, queue/retry, audit, project filters and access checks
  remain. Existing personal deliveries are covered without migration. A webhook
  grants no API rights. Scopes and authorization are unchanged by this hotfix.

## Verification

SQLite and MariaDB: **1064 tests / 15729 assertions** each. PHP 8.4 on MariaDB:
**132 tests / 1825 assertions**. Frontend: **434 tests**; production Vite and Pint
pass. The source browser workflow on isolated MariaDB passes under a platform
owner, including creation with/without a key, attach/replace/detach/rebind, one
key for two agents, mixed subscriptions and immediate revocation.

The real public HTTPS receiver verifies raw-body HMAC, then returns 503 and 204
on retry with the same body and delivery ID. Duplicate processing is suppressed.
Two external deterministic Node executions, private follow-up/results and
project-access revocation pass. A QA-only public DNS answer/system-CA fixture
supports the disposable receiver while preserving original SSRF and TLS checks.
No production transport override is included.

The extracted production ZIP passes the platform-owner workflow on isolated
MariaDB and **96** captures: RU / EN / TR / zh-CN, light/dark, 1440/390px, six
surfaces. No JavaScript page errors or horizontal document overflow were found.
Representative mobile/desktop captures were visually inspected. API rights are
not granted by webhook delivery. Existing personal receivers remain tested.

Artifact: **`project-brief-v0.9.10-bd09dd5.zip`**, **39,448,453 bytes**. Source commit:
`bd09dd5cb2ec4b2ad8b4e25fc0c24840cde3bc09`.

SHA-256: `52755bcdad23268092b570efd28f6720bb68a9b27380a7759f55073f0c70baaf`.
CRC and all **8942** manifest records pass. The test env and provider key are absent.
Detailed evidence: [QA.json](QA.json), [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json).

This hotfix made **0 AI/provider requests**. Tests used synthetic data and isolated
databases. The owned MariaDB/tmpfs container and temporary HTTPS tunnel have been
removed. Screenshots remain outside Git and the runtime; the eight original
Milestone 10.0 documents retain matching hashes and are excluded from commits/ZIP.
No hotfix push, deployment or production smoke was performed. Physical devices,
Safari/WebKit and production SMTP were not tested by this hotfix.

See [API.md](API.md), [PRODUCTION.md](PRODUCTION.md) and the packaged Russian owner
guide for contracts and separately authorized installation checks.
