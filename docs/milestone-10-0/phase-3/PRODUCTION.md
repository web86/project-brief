# Dev2Done v0.9.13 — Phase 3 update / Jino runbook

Этот ZIP содержит Inventory, Accounting, Compatibility Observe и read-only Impact. Требуется **additive migration** `2026_11_02_220732_add_plan_inventory_accounting`: три новые таблицы cohort/checkpoints/client observations. Если Phase 1 ещё не устанавливалась, штатный migrate применит также её pending schema. **Отдельный operator backfill нужен для compatibility и client mappings; migrate его не запускает.**

Новых обязательных env keys нет. Сохранить текущие APP_KEY, `.env`, SESSION_COOKIE, storage/uploads и private runtime path `private/project-brief-app`. HMAC accounting использует APP_KEY, его ротация не является шагом обновления: существующие fingerprints потребовали бы отдельной процедуры review/key migration. Dev2Done логотип/палитра/пользовательские branding настройки и Static Demo dataset не меняются. Production/Static Demo ZIP устанавливаются независимо.

## Перед обновлением

Проверить SHA-256 sidecar, `release.json` (version, exact commit, build UTC, PHP target8.4) и checksums.txt. Сохранить согласованную резервную копию БД/private storage и предыдущие public/private code/vendor. Не копировать QA secrets, demo data или `.env.example` поверх работающей конфигурации. Для MariaDB нужны прежние Phase1 CREATE/ALTER/INDEX/REFERENCES/TRIGGER privileges и новые table/index/FK privileges; Jino-specific privileges проверяет оператор до maintenance.

## Обновление установленного сайта

1. Включить maintenance в текущем private runtime: `php artisan down`; приостановить штатные cron/processors по существующему DEPLOY-JINO runbook.
2. Заменить `public/` и `private/project-brief-app/` согласованной версией из ZIP. Private runtime должен оставаться вне web root. Перенести сохранённые `.env`, APP_KEY, storage/uploads и прежние permissions.
3. Из нового private runtime с PHP8.4 выполнить:

```sh
php artisan optimize:clear
php artisan migrate --force --no-interaction
php artisan optimize
```

MariaDB DDL имеет implicit commits. При прерывании повторить migrate: migration восстанавливает отсутствующие indexes/FK после committed CREATE. Не выполнять migrate:fresh, migrate:rollback, first-install, reset/demo seed или автоматический PlansFoundationSeeder.

4. `php artisan up`, вернуть прежние processors. Проверить login/MFA/passkeys, profile/branding/agreement, owner/project/client, Agent API/shared keys/private history, independent webhooks, SSO, PDF/DOCX. `/demo` и `/api/demo/status` должны быть 404 в production.
5. Primary PlanAuthority видит `/platform/plans/inventory`; остальные platform_admin имеют прежнюю диагностику, без plan management. Сохранённый configured enforce **не исполняет тарифные запреты**: отчёт явно покажет activation unavailable.

## Отдельный операторский inventory

После backup и проверки выбранной DB/configuration сначала только dry-run:

```sh
php artisan plans:inventory --dry-run --after=0 --limit=100 --seconds=30
```

Строго без business writes: даже hidden version/cohort не создаются. JSON показывает processed/skipped/needs_review/failed/pending/uninitialized/new_mappings, cutoff_id, next_after и has_more. Для полной инвентаризации повторять страницы с показанным `next_after`. В dry-run processed означает ready observations текущей страницы, не выполненный apply.

**Только отдельный явный операторский apply** создаёт compatibility metadata и mappings:

```sh
php artisan plans:inventory --apply --after=0 --limit=100 --seconds=30
```

Команда не включена в scheduler/installation/release runtime tests как production action. Не требуется менять rollout_mode, primary authority или existing subscription assignments. Повтор безопасен: complete records skipped, pending/failed workspace продолжают свои cursors. Если chunk остался pending, next_after остаётся **до** этого workspace: повторить ту же страницу. При failed исправить operator/database condition и повторить тот же cursor. Граница seconds проверяется между chunks; текущая DB transaction может завершиться позже, это не hard query timeout.

Обновить mappings/holds после новых links, email/source changes или review:

```sh
php artisan plans:inventory --apply --refresh --after=0 --limit=100 --seconds=30
```

`--refresh` не переписывает pinned subscription/period/grants. Изменившиеся provenance и alias collisions не сливаются автоматически: остаются needs_review. Несколько active owners не демотируются. Исторические accounting UUID не удаляются. Ready в checkpoint относится к обработанному cutoff; live usage повторно проверяет актуальные links и может показать incomplete.

Workspace, созданные после первого cohort capture во время shadow phase:

```sh
php artisan plans:inventory --apply --extend-cohort --after=0 --limit=100 --seconds=30
```

Это отдельное операторское расширение legacy cutoff, не запуск Trial. Перед будущей commercial activation нужна согласованная финализация cohort с блокировкой eligibility race; **Phase 3 не активирует коммерцию**.

Все workspace остаются с прежней authorization/scopes. Нет автоматических Trial/Grace/Basic, suspension/downgrade, feature disable, payments, новых quotas или удаления файлов. `needs_review` требует анализа причины, не исправления users/roles/SSO вручную ради счётчика. Platform отчёты не содержат private content/emails/credentials.

## Откат

Maintenance + пауза processors; вернуть предыдущие Dev2Done v0.9.12 public/private code/vendor как одну версию, сохранить актуальные `.env`/APP_KEY/storage и **всю additive schema/accounting history**. Очистить/пересобрать cache предыдущего runtime, проверить доступ, поднять сайт. Не выполнять destructive rollback и не восстанавливать старый DB backup поверх новых пользовательских данных. В Phase3 commercial barriers не создаются; будущий Phase4 rollback потребует своего execution/catch-up runbook.

Этот документ — инструкция для будущего оператора. При подготовке релиза production/Jino DB не открывались, inventory там не запускался, deployment/push/merge не выполнялись.
