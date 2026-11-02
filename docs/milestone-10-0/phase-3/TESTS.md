# Phase 3 verification

Локальная проверка 03.11.2026, Europe/Istanbul. Source base `81bf0fd`; никакого production/Jino inventory. Все fixtures синтетические, MariaDB11.8.9/tmpfs с network=none, PHP8.4.26 + GD/WebP. Existing full demo containers и пользовательские данные не сбрасывались. API/AI/provider calls в QA отсутствуют, кроме локальных synthetic HTTP fixtures.

## Результаты source QA

| Проверка                                                                                     | Результат                                                                                                       |
| -------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| Полный Laravel PHP8.4.26 / MariaDB11.8.9                                                     | 1123 passed, 16483 assertions; 0 skips/failures/errors                                                          |
| Последующий focused inventory + PlatformPlans после запрета public compatibility publication | 24 passed, 214 assertions                                                                                       |
| Frontend                                                                                     | 449 passed, 0 skipped                                                                                           |
| Production frontend build / brand:check                                                      | Passed; прежнее предупреждение о chunks >500kB                                                                  |
| Browser plans + Inventory/Impact                                                             | 6 RU/EN×theme scenarios; desktop/mobile375/390, errors=[]                                                       |
| Static build / браузер                                                                       | Passed, 75 UI scenarios; 0 API requests/writes/external connections/errors; 314 developer / 26 client contracts |

Логи: ignored `artifacts/milestone-10-0-phase-3/logs/`; screenshots и machine browser reports рядом. Реальные Jino/production inventory, физический TouchID, настоящий Safari/WebKit и коммерческие execution/retention gates не проверялись. Packaging результаты фиксируются отдельно после проверки ZIP.

## Покрытие

- Dry-run hash всех business/accounting/catalog tables до/после идентичен; команда без явного режима отклоняется. Apply one compatibility subscription/open-ended period; повтор no-op. Manual pinned free subscription/current period/revision и grants не переписываются. Trial claims отсутствуют.
- Distinct owner/admin, admin без assignment, active/inactive agents, shared credential у двух agent identities; client email fallback через несколько проектов, normalized case/space, inactive/trash/restore/reactivation. Missing/changed/foreign provenance — incomplete/needs_review, не false zero.
- Persisted consumed source-bound proofs с разными identities и общей mailbox → отдельные accounting UUID, ambiguous email alias, unknown comparison. Retry сохраняет UUID, authentication bindings/clients не merge.
- Pending invitation union, same email/multiple invitations, accept→membership без двойного count, account email edit, expiry/revoke, invalid project-scoped manager/project, ownership review. Shadow reservation persistence failure не ломает issuance; current facts показывают count и refresh diagnostic.
- Required inventory audit failure откатывает chunk. 205 unique clients: первый checkpoint200, следующий5, retry без дублей; explicit cohort extension. Strict CLI keyset bounds и API field/page limits.
- Published target реальные values/excess, unsupported/planned metric unknown, configured enforce explicitly inactive. Preview business hash неизменен. Primary-only API/shell, strong MFA/fresh proof и сохранение diagnostics других platform_admin.
- Populated Phase2 schema → Phase3 migration ×2: fingerprints всех существующих tables неизменны; нет automatic backfill. Forward-only migration, восстановление отдельного MariaDB index после interrupted DDL.
- MariaDB independent workers/barrier: два backfill workers, два invitations одному человеку, inventory vs project/client change, retry/refresh после drift. На synthetic >1000 projects проверен фактический covering `pf_projects_usage_idx` через EXPLAIN, а также index/FK inventory/recovery.
- Browser: existing catalog/draft/preview/publish/policy/rules flows + Inventory/Impact, RU/EN×light/dark/system, desktop1440/mobile390/375, direct reload/history, no private fixture titles/emails, no page overflow/JS errors. Скриншоты inspected; unavailable metrics отдельно, mobile rows/cards читаемы.
- Полные existing security/MFA/PlanAuthority/onboarding/Agent collaboration/shared keys/independent webhooks/SSO/client privacy/profile/branding/agreement/export suites и production/static builds проверяются перед packaging.

## Воспроизведение

```sh
PHP_INI_SCAN_DIR=:$PWD/api/dev-php php -d memory_limit=512M api/vendor/bin/phpunit -c api/phpunit.xml --filter PlansInventory
npm test
npm run build
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/platform-plans.browser.mjs
node deployment/build-static-demo.mjs --preview
```

Полный PHP8.4/MariaDB runner: `tests/plans-foundation.php84.sh`, repository read-only `/workspace`, image `projectbrief-phase2-php84-gd:local`, exclusive databases `projectbrief_agent_ux_full_qa` и `projectbrief_m10_upgrade_qa`, только synthetic environment. Не запускать несколько DB suites одновременно. SQLite-only skips не заменяют MariaDB race gate.

При QA обнаружены и исправлены: сохранение reused email mapping; stale provenance fingerprint; malformed/ineligible project invitations; deterministic unique emails вместо случайных Faker collisions в exact-count fixtures; schema-scoped demo audit (раньше foreign schemas могли попасть в orphan audit); cleanup upgrade fixture; fail-soft reservation shadow savepoint; flat dotted i18n keys и mobile table layout. SQLite read-only demo regression устранена: membership resolution использует SELECT FOR UPDATE, а не write-only dummy UPDATE helper. Добавлен отдельный regression для конфликтующих contact/email aliases: новая link получает отдельную identity, без выбора одного из противоречащих hints. Demo dataset/UUID/content/privacy contract не менялся.

Финальные результаты, exact source commit, production manifest/SHA и ограничения проверки закрепляются в ARTIFACTS.md после полного QA. Никакие future allocation/expiry/epoch/storage/purge tests не объявляются пройденными этим этапом.
