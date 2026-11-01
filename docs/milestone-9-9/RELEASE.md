# Milestone 9.9 — Agent Collaboration, Unified API Credentials & Task Editor UX

Implemented the supplied collaboration brief on v0.9.8 as **v0.9.9**, before
Milestone 10 plans/subscriptions, as requested. Branch:
`codex/milestone-9-9-agent-collaboration`. Release source commit:
`67779f851ac94970dc8bf054c56f0208b0d9ce1a`.

New project grants automatically add agents to existing project teams. Unchanged
settings do not restore intentionally removed members. One API keys UI retains
separate integration/agent identities, namespaces, permission ceilings and fresh
MFA. Old credentials keep their scopes and supported operations.

Agent/project/run instructions, frozen contexts, private dialogue and repeatable
runs preserve prior versions. Claim/idempotency checks prevent duplicate execution;
revocation cancels access and pending deliveries. Client publication remains a
separate reviewed human copy. The task editor reuses agreement components, adds
server HTML allowlisting and rich PDF/DOCX, and starts AI improvement directly
while preserving explicit Apply and Save.

## Validation

- Frontend: **429/429**; full Laravel SQLite: **1043/1043, 15,433 assertions**.
- MariaDB 11.8.9 full suite: **1043/1043, 15,433 assertions**.
- Target PHP 8.4.26/MariaDB: **99/99, 1,421 assertions**.
- PHP lint: 28 files; Pint, focused Prettier, locale parity, brand check and Vite build passed.
- Canonical builder passed packaged WebAuthn/MFA, API/security/runtime, migrations,
  comments/files, SSO, tenant boundaries, idempotency and maintenance checks.
- Final extracted ZIP passed registration → automatic membership → unified key →
  first private report → follow-up → second report/history → human publication,
  concurrency and immediate membership/token revocation on isolated MariaDB.
- Visual matrix: **240 captures**, RU/EN/TR/ZH-CN × light/dark/system × 1440/390;
  no page errors, horizontal overflow or overlap of agent work and client comments.
  The final archive's 8,920 public/PHP runtime files are byte-identical to the
  visual-tested archive; Composer differs only in its root commit reference.
  The corrected executor was tested separately in the final ZIP through `/tmp`.
- Real Groq E2E: **2 successful generation HTTP requests** to
  `openai/gpt-oss-120b`, **1,422 input / 473 output tokens**. Synthetic content and
  a separate disposable DB only. One earlier TLS handshake failed before HTTP;
  loading the trusted system CA bundle fixed it without disabling verification.
  No further provider calls were made. Deterministic tests remain primary.

Found and fixed: mobile grid overlap, missing explicit dialogue scope for frozen
history reads, symlinked executor entry detection, packaging allowlist omissions
and missing explicit deployment commands. Updated old UI test expectations for
the new full-report dialog. No known failing automated checks remain.

## Artifact

`project-brief-v0.9.9-67779f8.zip` — **39,429,896 bytes**.

SHA-256: `27400208a74b7b980a00537ed532fe4807e85d947e00a07b1110fb9f22561d74`

CRC and all **8,936** internal checksums passed. The example
matches the released source. The dedicated Groq key is absent from every ZIP
entry, source changes and QA logs; the test-env was never copied into the
repository or archive. Screenshots remain outside Git.

Exact machine-readable evidence: [QA.json](QA.json),
[RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json).
Contract: [API.md](API.md), [OpenAPI](../milestone-9-6/openapi.json).
Additive migration, existing-token compatibility and Jino deployment/rollback:
[PRODUCTION.md](PRODUCTION.md). Independent executor:
[examples/collaboration.mjs](examples/collaboration.mjs).

The existing Milestone 10.0 documents were restored byte-for-byte and are not
part of the release source commit or ZIP. Push, deployment and production DB
changes were not performed. Jino/FPM load, live SMTP/Telegram, physical devices,
Safari/WebKit and actual production backup recovery remain unverified.
