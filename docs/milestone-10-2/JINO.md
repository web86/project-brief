# Dev2Done v0.9.24 на Jino

Обновление рассчитано на v0.9.23 и PHP 8.4. Новых миграций и переменных окружения нет. Сверьте внешний SHA-256, распакуйте ZIP, проверьте `checksums.txt` и исходный коммит в `release.json`.

Перед заменой файлов сохраните текущую public/private пару, БД, `.env`, APP_KEY и пользовательское storage. Приостановите существующие фоновые задания и включите maintenance. Оставьте публичную регистрацию закрытой (`REGISTRATION_ENABLED=false`) и поставьте новые активации на паузу (`TRIAL_ACTIVATION_ENABLED=false`). Уже выданные Trial продолжают действовать по закреплённому периоду.

Замените обе части комплекта по [DEPLOY-JINO.md](DEPLOY-JINO.md), сохраняя окружение, storage и путь к private runtime. Из приватного каталога выполните `php artisan optimize:clear` и `php artisan migrate:status` с PHP 8.4. Для исходной v0.9.23 уже должны быть выполнены `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs`, `2026_11_03_170505_add_restore_cycle_to_entitlement_capacity_reservations` и `2026_11_03_215440_create_canary_registration_invites`. Seed и backfill для этого обновления не нужны.

Возобновите фоновые задания и выключите maintenance. Проверьте health, прямой вход в `/platform?section=workspaces` и отображение действующего Studio Trial на `/admin/settings/subscription`. На отдельном тестовом workspace проверьте три шага назначения и повтор после 429. Для production-проверки существующего Studio Trial достаточно просмотра. Порядок возобновления новых активаций описан в [CANARY.md](CANARY.md).

Для отката под maintenance восстановите пару файлов v0.9.23, сохраняя БД, окружение и storage. Уже подтверждённые назначения сохранятся в истории подписок. Для отката дальше v0.9.23 действуют ограничения из [ROLLBACK.md](ROLLBACK.md).
