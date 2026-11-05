# Dev2Done v0.9.25 на Jino

Патч рассчитан на v0.9.24 и PHP 8.4. Новых миграций и переменных окружения нет. Сверьте внешний SHA-256 ZIP, внутренний `checksums.txt` и коммит в `release.json`.

Сохраните резервную копию public и private каталогов, базы, `.env`, APP_KEY и пользовательского storage. Перед заменой файлов приостановите фоновые задания, включите maintenance и оставьте `REGISTRATION_ENABLED=false` и `TRIAL_ACTIVATION_ENABLED=false`. Уже выданные Studio Trial действуют до границы закреплённого периода.

Замените обе части комплекта по [DEPLOY-JINO.md](DEPLOY-JINO.md). Сохраните окружение, storage и путь к private runtime. Из приватного каталога выполните `php artisan optimize:clear` и `php artisan migrate:status` под PHP 8.4. В базе v0.9.24 уже должны быть выполнены `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs`, `2026_11_03_170505_add_restore_cycle_to_entitlement_capacity_reservations` и `2026_11_03_215440_create_canary_registration_invites`. Seed и backfill для этого обновления не нужны.

Возобновите фоновые задания и выключите maintenance. Проверьте health и действующий Studio Trial в режиме просмотра. На изолированной базе войдите под Primary PlanAuthority, откройте вкладку «Подписка» подготовленного workspace без периода и нажмите «Назначить тариф». Проверьте Freelancer со сроком, Basic без срока и Studio со сроком. Убедитесь, что владелец с незавершённым онбордингом видит причину блокировки.

Первое назначение завершает онбординг и закрывает существующую ожидающую заявку Trial со статусом `denied` и причиной `operator_initial_manual_assignment`. Идентификаторы владельца и подтверждённых субъектов сохраняются. Заявка не получает пробный период и не учитывается в лимите активаций canary. Окончание ручного периода и grace переводит workspace на закреплённый бесплатный тариф.

При откате на v0.9.24 действуют ограничения из [ROLLBACK.md](ROLLBACK.md). Если создан открытый цикл `initial_manual_assignment`, для безопасного возврата требуется согласованное восстановление файлов и предрелизной базы под maintenance либо исправление поверх v0.9.25. Runtime v0.9.24 не умеет разрешать этот цикл.
