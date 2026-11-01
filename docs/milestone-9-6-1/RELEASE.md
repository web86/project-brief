# Project Brief v0.9.7 — UX patch release

Prepared locally on 2026-10-31 from v0.9.6, branch `codex/milestone-9-6-1-agent-ux`. No push, deployment or production DB changes are authorized or performed.

## Scope

[Changed components and behavior](README.md). No backend runtime or schema changes. API values/scopes, explicit owner grants, memberships, assignment generations, signed webhooks, idempotency, audit and human task review remain unchanged.

## Verification

| Check                                      | Result                                                                                                                                                                                    |
| ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Frontend                                   | 417 passed, including new expiry/latest-report/all-state/locale assertions                                                                                                                |
| Full Laravel / PHP 8.5.10 / MariaDB 11.8.9 | 1015 passed, 15035 assertions                                                                                                                                                             |
| Focused PHP 8.4.26 / MariaDB 11.8.9        | 71 passed, 1023 assertions                                                                                                                                                                |
| Browser matrix                             | RU/EN/TR/ZH-CN × 1440/390 × light/dark/system; 96 captures; no page errors or horizontal overflow                                                                                         |
| Dialog behavior                            | Fixed header/footer within 90vh, internal scroll, searchable grants/chips, no default grants, long multilingual names, retained draft after 422, confirmation on Cancel/X/Escape/backdrop |
| Independent executor                       | Real owner registration/token/team/task UI, external Node process over HTTP, reports and uploads, unchanged task status/approval                                                          |
| Security regression                        | Unassigned/foreign task denial, concurrent idempotency, assignment generation, token rotation, owner disable, real TLS/HMAC webhook retry and cancellation                                |

The canonical builder passed the complete SQLite suite (1015 tests, 15035 assertions), frontend suite (417), production build/vendor validation, packaged signed WebAuthn and the existing HTTP runtime regression for REST, SSO, invitations, platform isolation, files, migrations and maintenance.

Screenshots are kept outside Git in `/tmp/project-brief-m96-qa/`. Desktop/mobile light/dark dialogs, cards and latest report panels were visually inspected. Browser dimensions are Chromium emulation; physical mobile, Safari/WebKit and hardware Passkeys are not verified here.

## Client visibility

**Agent comments/reports, result URLs and attachments are visible to authorized clients of the same project.** Normal email/Telegram/Web Push delivery is not automatically dispatched by agent writes; live notifications can contain a preview when the project notification-settings row is missing and recipient push is enabled. See [RESULT-VISIBILITY.md](RESULT-VISIBILITY.md) for proven behavior and a separate future audience proposal. No visibility migration or retrospective hiding is included.

## Deployment boundary

[Jino update and rollback](PRODUCTION.md): replace the matching private/public runtime while preserving `.env`, APP_KEY, DB and storage. No new migration from v0.9.6. A runtime rollback to verified v0.9.6 preserves current data; no schema rollback is needed. Actual Jino/FPM/TLS, hosting load, production backup restore and external SMTP/Telegram/Web Push delivery remain unverified. No commercial AI provider was connected.

## Production artifact

- ZIP: [project-brief-v0.9.7-2503d2f.zip](../../release/project-brief-v0.9.7-2503d2f.zip).
- Bytes: **39385559**.
- Source commit: `2503d2f8dbaaed17a94d6e16c8a364936e4c6e6a`. Subsequent evidence commits change only tests/release reports.
- Built UTC: `2026-10-31T06:05:39.262Z`.
- SHA-256: `7cfc745a785010d0c002cae3e1e1466a6b559f5c64162ea99b0e20d0363c597c`.
- [Checksum sidecar](../../release/project-brief-v0.9.7-2503d2f.zip.sha256) and [machine-readable artifact metadata](RELEASE-ARTIFACT.json).

Created only by `./bin/build-release`. Independently checked every ZIP entry CRC and all **8919** internal checksum records, plus exact equality of the standalone Node agent to its source. The canonical validator excludes local secrets, private keys, databases, QA files/screenshots, dev vendor, tests, source maps and node_modules. Portable API documentation and the independent executor are outside the public web root in `agent-api/`.

## Final extracted ZIP check

The final archive was extracted into a separate directory. Its compiled UI and production vendor passed the full Agent API E2E on an exclusive disposable MariaDB database. The external Node process used the example file from inside that ZIP. Actual HTTP bearer/scopes, independent reports, concurrent idempotency, multipart upload/download, assignment generation, rotation/disable and signed TLS webhook retry/cancellation passed.

Additional source and packaged UI checks covered all five tabs at both widths, creation/editing of all three kinds with unchanged scopes and no automatic membership, explicit owner grant revocation/regrant, team removal/re-addition, inline task assignment, API error draft retention and confirmed modal deletion. The real 30/minute integration-settings limiter remained enabled; QA phases pause between request bursts. These final test refinements did not change the packaged runtime.

Machine-readable sanitized results: [QA.json](QA.json). All owned browser/backend processes and the disposable MariaDB container were stopped after checks. The extracted archive and screenshots remain local for review; no generated QA images are included in Git.
