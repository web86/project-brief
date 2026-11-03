# Dev2Done v0.9.17 — Jino update checklist Phase 4B.2

Эта инструкция описывает процедуру для будущего проверенного артефакта. Публикация, обновление Jino и коммерческий запуск требуют отдельных поручений.

## Перед окном обслуживания

- Привязать решение об установке к проверенному commit, SHA-256 и manifest canonical ZIP. Сверить checksums после извлечения. Закрыть полный PHP 8.4/MariaDB regression и lifecycle browser E2E на том же runtime.
- Проверить PHP 8.4 FPM и CLI, зависимости, HTTPS origin, расположение private runtime, свободное место для резервной копии и отсутствие общих очередей с тестовым стендом. Сохранить APP_KEY, env, uploads, storage и DB.
- Сделать и проверить согласованный backup базы, private files и соответствующего runtime. Записать, существуют ли открытые cycles, коммерческие периоды или execution epochs. При их наличии действуют правила [карантина и forward fix](ROLLBACK.md).

## Обновление при закрытом внешнем трафике

1. Остановить cron/workers. Закрыть UI, bearer API, Webhooks и SSO на уровне прокси или maintenance площадки, включая прямой origin. Дождаться завершения уже начатых процессов.
2. Развернуть из проверенного ZIP соответствующую пару public/private runtime. Сохранить текущий env и данные. Сохранить canonical HTTPS, secure cookies, APP_KEY и Passkey RP/origins. Оставить `APP_ENV=production`, `APP_DEBUG=false`, `REGISTRATION_ENABLED=false` и `TRIAL_ACTIVATION_ENABLED=false`. Сохранить существующие режимы Workspace Compatibility.
3. Выполнить `php artisan optimize:clear` и проверить `php artisan migrate:status`. Для базы v0.9.16 ожидаются additive migrations `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs` и `2026_11_03_170505_add_restore_cycle_to_entitlement_capacity_reservations`. Применить pending migrations с `php artisan migrate --force --no-interaction` только в согласованном окне. Установщик не должен публиковать версии, назначать PlanAuthority, менять тарифы и создавать Trial. Не запускать `migrate:fresh`, `migrate:rollback`, seeders или demo reset в рабочей БД.
4. Перестроить кеши. На проверочном доступе сверить закрытые registration/Trial gates, Compatibility login/MFA, приватные файлы, API и сохранность существующих подписок. Для workspace с уже созданным cycle проверить 403/409 на held и stale execution и доступ владельца к истории.
5. Открыть трафик и возобновить только проверенный runtime. Плановое подключение `plans:reconcile-subscriptions --apply` — отдельное решение. Команда без `--apply` выводит метаданные ограниченной страницы. `--after` принимает `nextAfter`, `--limit` и `--jobs` ограничивают batch, проходы повторяют до нулевого stale count.

После появления suspension cycle или epoch rollback на v0.9.16 с рабочими данными закрыт. Использовать [forward fix и карантин](ROLLBACK.md). Controlled canary согласуется отдельно по [плану запуска](CANARY.md).
