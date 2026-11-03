# Phase 4B.1 — verified release artifacts

Branch `codex/milestone-10-0-phase-4b1`. Exact runtime source commit **`2190f0083923300499d0cdc8c5048a9095f97e6f`**. Canonical `bin/build-release` completed from a clean tree; subsequent documentation-only commits do not alter archive code. No push, merge, deployment, Jino access, production migration or commercial activation occurred.

| Artifact                                               | Bytes / ZIP files  | SHA-256                                                            |
| ------------------------------------------------------ | ------------------ | ------------------------------------------------------------------ |
| `release/dev2done-v0.9.15-2190f00.zip`                 | 39,796,455 / 9,016 | `f0b2e616e1382a8fb53058210a3f8ada4644b6bd9e09f727fdc0e13adbeb4b6b` |
| Existing `release/dev2done-static-demo-v3-ba216f6.zip` | 2,928,884 / 262    | `60f5348b2db899168436f7e3ede3d48e2553f29010c4fb2a1840293106db161c` |

Production `release.json`: Dev2Done v0.9.15, source commit above, built UTC **2026-11-03T11:31:05.039Z**, PHP target8.4, example domain brief.web86.site. Adjacent `.release.json` and `.zip.sha256` are provided. ZIP contains 9,015 checksummed files plus `checksums.txt`; the actual archive has 9,016 files. Complete checksums were rechecked after extracted-runtime QA.

Static Demo v3 is reused unchanged and separately from production PHP. Its manifest SHA remains `8f42d433b5f9f467877ee36d89a24b02a538afa2da8ac28cb83698cc4b9ae42c`; all143 dataset/schema files are byte-identical. It has314 developer/26 client contracts,10 projects/150 tasks/client15. Northstar Core experience remains first with six tasks. No necessary static interface change or new Static Demo ZIP was introduced. Previous v0.9.14 production and v3 static ZIP checksums were rechecked unchanged.

## Validation

| Check                              | Result                                                                                                                                                                                                                                                           |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Final canonical PHP8.5.10/SQLite   | 1,179 tests /1,173 passed /16,889 assertions;6 explicit MariaDB-only skips, no failure/error                                                                                                                                                                     |
| Frontend canonical                 | 453 passed; production build and brand:check passed                                                                                                                                                                                                              |
| Canonical actual extracted ZIP     | Signed WebAuthn, complete HTTP/runtime/API/SSO/files/cron/upgrade, plan management, onboarding and privacy/no-demo contracts passed on production vendor                                                                                                         |
| Actual ZIP PHP8.4.26               | The same security, full HTTP/runtime, plans, onboarding and privacy contracts passed with production vendor                                                                                                                                                      |
| Actual ZIP PHP8.4.26/MariaDB11.8.9 | Fresh additive migration, legacy defaults, hard production gates, proof/provisioning/replay, pending claim/no Trial period and no test activation runtime passed in exclusive disposable DB                                                                      |
| Actual compiled ZIP browser PHP8.4 | 15 scenarios /50 screenshots; RU/EN/TR/ZH-CN × light/dark/system,1440/375/390; real email proof/TOTP/recovery/step-up, explicit link cancel/success, mock GitHub PKCE/cancel/retry, history and primary diagnostics; consoleErrors=[]; no live provider requests |
| Source browser                     | Same15 scenarios /50 screenshots passed                                                                                                                                                                                                                          |
| Branding browser                   | 9 groups, including fallback #5b4bd6 and custom-color priority on developer/client/login in all themes                                                                                                                                                           |
| Current Static Demo preview        | 87 screens,0 API requests, writes, external connections or console/JS errors; unchanged143 dataset/schema files                                                                                                                                                  |
| Full final PHP8.4/MariaDB          | **1,179 passed /17,047 assertions**, no skip, failure or error; independent MariaDB races and migration cases included                                                                                                                                           |

Canonical secret/fixture/demo scan and a second post-test extracted-package scan passed. Production vendor contains no Faker, tests, activation fixture, anonymous demo runtime, dev source or local environment secrets. Browser fixture/provider injection exists only in disposable copies outside the ZIP. Actual extracted package: `/tmp/dev2done-phase4b1-production-2190f00`. Ignored local evidence: `artifacts/milestone-10-0-phase-4b1/` (logs, source/browser, archive-browser, branding, static, scan and archive metadata).

Real OAuth consent/SMTP delivery, Jino, hardware TouchID and Safari/WebKit remain untested. Automated providers use mocked endpoints and ephemeral signed fixtures. Existing Vite large-chunk warning remains; dependencies were not changed. Follow [production update/rollback](PRODUCTION.md); Phase4B.2 is not started.

Only the Phase4B.1 disposable MariaDB/container volume and two completed temporary PHP8.4 HTTP servers were removed after QA. The four persistent professional demo containers/data were retained; professional demo and static preview returned HTTP200. [Machine summary](qa-results.json).
