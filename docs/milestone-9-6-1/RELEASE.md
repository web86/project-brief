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

The canonical builder also runs the complete SQLite suite, frontend tests/build, production dependency validation and extracted runtime regression. Final archive metadata and those results are recorded below after packaging.

Screenshots are kept outside Git in `/tmp/project-brief-m96-qa/`. Desktop/mobile light/dark dialogs, cards and latest report panels were visually inspected. Browser dimensions are Chromium emulation; physical mobile, Safari/WebKit and hardware Passkeys are not verified here.

## Client visibility

**Agent comments/reports, result URLs and attachments are visible to authorized clients of the same project.** Normal email/Telegram/Web Push delivery is not automatically dispatched by agent writes; live notifications can contain a preview when the project notification-settings row is missing and recipient push is enabled. See [RESULT-VISIBILITY.md](RESULT-VISIBILITY.md) for proven behavior and a separate future audience proposal. No visibility migration or retrospective hiding is included.

## Deployment boundary

[Jino update and rollback](PRODUCTION.md): replace the matching private/public runtime while preserving `.env`, APP_KEY, DB and storage. No new migration from v0.9.6. A runtime rollback to verified v0.9.6 preserves current data; no schema rollback is needed. Actual Jino/FPM/TLS, hosting load, production backup restore and external SMTP/Telegram/Web Push delivery remain unverified. No commercial AI provider was connected.
