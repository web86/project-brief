# Dev2Done v0.9.22 — Canary Trial на Jino

Эта инструкция описывает локально подготовленный patch ZIP. Публикация, обновление Jino и коммерческий запуск требуют отдельных поручений.

## Перед окном обслуживания

- Привязать решение об установке к проверенному commit, SHA-256 и manifest canonical ZIP. Сверить checksums после извлечения. Сверить результаты regression и browser E2E из QA.md для того же runtime.
- Проверить PHP 8.4 FPM и CLI, зависимости, HTTPS origin, расположение private runtime, свободное место для резервной копии и отсутствие общих очередей с тестовым стендом. Сохранить APP_KEY, env, uploads, storage и DB.
- Сделать и проверить согласованный backup базы, private files и соответствующего runtime. Записать, существуют ли открытые cycles, коммерческие периоды или execution epochs. При их наличии действуют правила [карантина и forward fix](ROLLBACK.md).

## Обновление при закрытом внешнем трафике

1. Остановить cron/workers. Закрыть UI, bearer API, Webhooks и SSO на уровне прокси или maintenance площадки, включая прямой origin. Дождаться завершения уже начатых процессов.
2. Развернуть из проверенного ZIP соответствующую пару public/private runtime. Сохранить текущий env и данные. Сохранить canonical HTTPS, secure cookies, APP_KEY и Passkey RP/origins. Оставить `APP_ENV=production`, `APP_DEBUG=false`, `REGISTRATION_ENABLED=false` и `TRIAL_ACTIVATION_ENABLED=false`. Сохранить существующие режимы Workspace Compatibility.
3. Выполнить `php artisan optimize:clear` и проверить `php artisan migrate:status`. При обновлении с v0.9.21, v0.9.20, v0.9.19 или v0.9.18 новых миграций нет. При обновлении с v0.9.17 требуется additive migration `2026_11_03_215440_create_canary_registration_invites`. Для базы v0.9.16 сначала также применяются `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs` и `2026_11_03_170505_add_restore_cycle_to_entitlement_capacity_reservations`. Применить pending migrations с `php artisan migrate --force --no-interaction` только в согласованном окне. Установщик не должен публиковать версии, назначать PlanAuthority, менять тарифы и создавать Trial. Не запускать `migrate:fresh`, `migrate:rollback`, seeders или demo reset в рабочей БД.
4. Выполнить `php artisan optimize`. На проверочном доступе сверить закрытые registration/Trial gates, Compatibility login/MFA, приватные файлы, API и сохранность существующих подписок. Для workspace с уже созданным cycle проверить 403/409 на held и stale execution и доступ владельца к истории.
5. Выполнить `php artisan up`, если включали Laravel maintenance. Открыть трафик после smoke checks и возобновить только проверенный runtime. Плановое подключение `plans:reconcile-subscriptions --apply` — отдельное решение. Команда без `--apply` выводит метаданные ограниченной страницы. `--after` принимает `nextAfter`, `--limit` и `--jobs` ограничивают batch, проходы повторяют до нулевого stale count.

После появления suspension cycle или epoch rollback на v0.9.16 с рабочими данными закрыт. Использовать [forward fix и карантин](ROLLBACK.md). Controlled canary согласуется отдельно по [плану запуска](CANARY.md).

## Smoke check закрытой регистрации

Проверить, что обычный `/register` закрыт, а `GET /api/onboarding/readiness` возвращает `registrationEnabled: false` и `trialActivationEnabled: false`. Проверку с приглашением выполнять в согласованной изолированной среде по [инструкции canary](CANARY.md). SMTP для verification email и `ONBOARDING_SUBJECT_RETENTION_DAYS` от 1 до 3650 обязательны для выдачи ссылки. Версия v0.9.22 не требует backfill, новых параметров или изменения SMTP.

При инциденте сохранить таблицу приглашений и существующие audit, accounts и claims. После появления cycles/epochs применять карантин и forward fix из ROLLBACK.md.

## Smoke check email verification

На изолированном стенде пройти canary invite → Register → экран ожидания письма. После регистрации проверить один адрес и cooldown. Открыть письмо в той же вкладке и в новом гостевом контексте. В гостевом контексте вход и, если настроено, MFA предшествуют подтверждению. При активной сессии нужного владельца ссылка обрабатывается сразу. Проверить повторное открытие использованной ссылки, ошибку по просроченной и переход к следующему шагу до состояния ready for activation.

Для отката v0.9.22 на v0.9.21 под maintenance установить `TRIAL_ACTIVATION_ENABLED=false`, восстановить public/private пару из проверенного v0.9.21 и повторить smoke checks. Сохранить текущую БД, env, APP_KEY и storage. Обе версии используют одну схему и те же lifecycle guards. Для более ранних runtime действуют ограничения из ROLLBACK.md.

В Platform → Registration and Trial сверить опубликованные версии, SMTP, retention, pending verified claims и актуальность операторского допуска. Проверить, что Primary PlanAuthority после fresh MFA видит панель «Тестовый запуск / Canary», а обычный владелец не получает данные из GET `/api/platform/registration-canary/owners`. Аварийный серверный выключатель отображается в диагностике. В обычной работе остановка и возобновление доступны в панели. Полный сценарий приглашения, активации, остановки и возобновления выполняется на изолированном стенде по [процедуре canary](CANARY.md). В Safari проверить один caret и стабильную высоту селектов на списке проектов, платформенных фильтрах и переключателях языка и темы.

## Проверка входа в Platform после истечения MFA

В отдельной проверочной сессии оператора дождаться истечения пятиминутного подтверждения. Открыть напрямую `/platform/`, `/platform/plans/` и `/platform/plans/registration`, включая reload. Проверить диалог MFA, продолжение на исходном URL после TOTP и повтор Passkey после отмены системного окна. После подтверждения приложение один раз повторяет ожидающий запрос. При исчерпании допуска или повторном отказе экран показывает безопасный код и предлагает ручной повтор. Cookies, токены и данные WebAuthn в операторские отчёты не включать.
