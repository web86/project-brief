# Phase 3 — verified Dev2Done release

Локальная подготовка: 03.11.2026, Europe/Istanbul. Ветка `codex/milestone-10-0-phase-3`. Финальный production ZIP собран обычным `./bin/build-release`; push/merge/deployment и production/Jino inventory не выполнялись.

## Production v0.9.13

- Архив: [dev2done-v0.9.13-868a08e.zip](../../../release/dev2done-v0.9.13-868a08e.zip).
- Размер: **39 733 506 bytes**; **8981 файлов**, включая checksums.txt; canonical builder сообщает 8980 до добавления checksum manifest.
- SHA-256: `bba6528c823d361972aac082a2eace57e49ce94a9e432429ae821987c7a859f8`.
- [SHA sidecar](../../../release/dev2done-v0.9.13-868a08e.zip.sha256).
- Exact source commit: `868a08e78db69eb99a5b3473a5ddb42a7c2860bc`.
- Build UTC: `2026-11-02T22:55:12.615Z` (03.11.2026, 01:55 Europe/Istanbul).
- [Manifest, exact release.json bytes from ZIP](../../../release/dev2done-v0.9.13-868a08e.release.json): Dev2Done, version0.9.13, PHP target8.4, existing domain brief.web86.site.
- `checksums.txt` внутри ZIP сверён с файлами после реального извлечения. Secret/fixture/demo scan canonical builder passed. Empty `database/factories/` сохранён для autoload, factory classes/Faker/test fixtures отсутствуют. Нет реального `.env`/APP_KEY, credentials, demo dataset/snapshots/runtime, Docker или QA screenshots/logs.

Финальные implementation commits: `0a048a3` backend, `1d890f2` UI/docs/release, `5a67ff5` SELECT-only read compatibility и isolated alias conflicts, `868a08e` nested Inventory sidebar state. Полный PHP8.4/MariaDB прогон выполнен на `5a67ff5`; `git diff 5a67ff5 868a08e -- api` пустой. Последний commit изменяет только sidebar и browser assertion. Документационный commit после packaging не меняет код ZIP.

## QA

| Проверка                                   | Результат                                                                                                                                                                         |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Полный backend PHP8.4.26/MariaDB11.8.9     | **1124 passed, 16487 assertions**, 0 skipped/errors/failures; independent races и EXPLAIN включены                                                                                |
| Canonical builder backend PHP8.5.10/SQLite | 1124 tests, 1120 passed, 16379 assertions, 4 явных MariaDB-only skips                                                                                                             |
| Frontend в final canonical build           | **449 passed**, 0 skipped; production build и brand:check passed                                                                                                                  |
| Извлечённый final ZIP, штатный builder     | Signed UV WebAuthn, full HTTP/runtime/API/SSO/privacy/files/cron/upgrade, plan + Phase3 inventory/impact, UX contracts passed с production vendor                                 |
| Final ZIP, PHP8.4.26                       | Signed UV WebAuthn, PlanAuthority/setup, explicit compatibility inventory, distinct clients, would-deny preview/no enforcement, UX/client privacy passed; no-dev vendor без Faker |
| Final ZIP compiled browser, PHP8.4.26      | **6 RU/EN × light/dark/system**, desktop/mobile375/390, nested Inventory active state, reload/security/old plan flows; errors=[]                                                  |
| Static Demo final build                    | Passed; data manifest побайтово идентичен Static Demo v2                                                                                                                          |
| Static Demo browser                        | **75 scenarios**, 0 API requests, writes, external connections и JS/console errors                                                                                                |

Полный HTTP/runtime suite final ZIP на PHP8.4.26 также **passed**: upgrade/install/key preservation, CSRF/MFA, API/scopes/tenant isolation, SSO-only bearer и session/replay, independent webhooks/quiet cron, client invitations/provisioning/requests, private comment/file access, deletion/trash/restore, profile/security и maintenance.

Логи: ignored `artifacts/milestone-10-0-phase-3/logs/`. Production screenshots: `archive-browser/`; browser/static machine report: `static-release/browser-results.json`. Извлечённый проверенный ZIP: `/tmp/dev2done-phase3-production-868a08e`. Реальный production/Jino, аппаратный TouchID и Safari/WebKit не проверялись. Прежнее предупреждение Vite о chunks >500kB остаётся; новых dependencies нет.

## Update и operator boundary

**Migrations требуются:** additive `2026_11_02_220732_add_plan_inventory_accounting`, три таблицы. **Отдельный явный operator backfill требуется** для compatibility/mappings. Dry-run без business writes, apply только ручной console action; scheduler/installer не запускают его. Новых env keys нет, APP_KEY/session/storage сохраняются. См. [Jino update/rollback и команды](PRODUCTION.md).

Никакого commercial enforcement, automatic Trial/Grace/Basic/downgrade/suspension, выключения функций, изменения реальных прав или удаления файлов. Configured enforce остаётся диагностическим unsupported activation. См. [NEXT.md](NEXT.md) для Phase4 gates.

## Сохранённые rollback / demo artifacts

- Production v0.9.12 `dev2done-v0.9.12-e0ea457.zip`: SHA-256 `306b9d71be4d168fef8adea8bd99ee2affd9a4db8403d830ca7e97a7645304b2` — не изменён.
- Static Demo v2 `dev2done-static-demo-v2-80aa892.zip`: SHA-256 `73efee471eb7d7d60efcf689e3ad267008b38a1f7aeb07138c46dc270934640b` — не изменён.
- Final source static preview build commit868a08e, schema1, без нового static ZIP. JSON data manifest SHA-256 `8f42d433b5f9f467877ee36d89a24b02a538afa2da8ac28cb83698cc4b9ae42c` совпадает с существующим v2 побайтово; developer314/client26 contracts, 10 projects/150 tasks, client15 tasks. Core experience первый с шестью задачами. Demo dataset/UUID/privacy contract сохранены.
