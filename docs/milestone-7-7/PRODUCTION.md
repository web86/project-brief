# Project Brief 0.7.7 — обновление и откат

Переход с 0.7.6. Новых миграций, настроек, cron, credentials и production-зависимостей нет. PDF остаётся browser Worker/pdfmake, DOCX — PHPWord. LibreOffice, документы и изображения QA в production ZIP не включены. Push, merge и deployment не выполнялись; перед production требуется отдельное разрешение.

## Обновление

1. Сверьте имя, размер и внешний SHA-256 с RELEASE-ARTIFACT.json; проверьте CRC ZIP и внутренний checksums.txt. Репетируйте на закрытом staging Jino с фактическими web PHP 8.4 и MariaDB. Сохраните backup БД, public/private/vendor, `.env`, APP_KEY, storage/uploads/branding/sessions, cron и точного deployed `.htaccess` вне public; обеспечьте независимый доступ для отката.
2. Остановите записи/scheduler, дождитесь запросов, включите `php artisan down` из private app.
3. Замените public/private/vendor согласованным комплектом ZIP по DEPLOY-JINO.md. Сохраните `.env`, APP_KEY, весь storage и maintenance marker. Не накладывайте пустой storage пакета на рабочие данные. Не выполняйте first-install, seed, migrate:fresh, db:wipe, key:generate или security recovery. Origin/RP ID, MFA, сессии и политику step-up не меняйте.
4. Проверьте прежние расширения PHP: GD/PNG/JPEG/WebP, DOM/libxml, fileinfo, mbstring, zip. PDF не требует PHP PDF-renderer; сервер выдаёт только JSON. Пределы экспорта/загрузок/доступа сохранены. DOCX использует прежние китайские шрифты. Не устанавливайте LibreOffice на production.
5. Выполните в maintenance:

   ```sh
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   ```

   С актуальной 0.7.6 pending migrations не ожидается. `.env` примером не заменяйте. HTTP_BOOTSTRAP_LIMIT_ENABLED и native ErrorDocument оставьте в прежнем согласованном состоянии; до Jino proxy/NAT/AllowOverride preflight новые host-dependent меры не включайте.

   Если установленная база старше 0.7.6, сначала выполните предусмотренную предыдущим релизом процедуру обновления. В `migrate:status` должны быть завершены прежние миграции, включая `2026_10_26_200540_add_project_trash_lifecycle`; Milestone 7.7 не заменяет и не отменяет их. Для актуальной 0.7.6 это проверка уже применённой схемы.

6. Проверьте smoke ниже. После разрешения на запуск выполните `php artisan up` и возобновите прежний scheduler/cleanup/feedback/webhooks.

## Smoke на staging

- Owner/developer/client: прежние разрешённые проекты; чужой workspace и закрытые поля недоступны.
- Сохранённые настройки: пользовательский заголовок, RU/EN/TR/ZH, all/approved/done, новые страницы разделов, все существующие флаги полей.
- PDF и DOCX: небольшой и длинный проект; шапка/сайт/дата, кириллица/Çığ/中文, первая/средняя/последняя страницы и длинная задача, полный IBAN, footer без наложений.
- Цены: все null → «Не рассчитана»; все явные 0 → 0 с валютой; частично → известная сумма и покрытие. Часы и итоги только для отобранных задач. Workflow approved и подтверждение клиента показаны раздельно.
- Включённые/отключённые логотип, компания, адрес, контакты, реквизиты, ProjectBrief. При отключённых реквизитах их нет в JSON; нет утечки чужого workspace.
- PNG/SVG с alpha, SVG с геометрией и со смешанным текстом; старый закешированный frontend получает PNG, обновлённый — SVG при поддерживаемом содержимом. Обновите страницу/обычным способом дождитесь принятия новой PWA-версии; не сбрасывайте сессии/факторы.
- 500 задач: PDF формируется в браузере, PHP не генерирует PDF; штатные лимиты запросов сохраняются. Не выполняйте нагрузочный цикл на production.
- Прежние навигация, брендинг, файлы, уведомления, trash/restore и MFA/passkeys работают.

## Откат на 0.7.6

Остановите записи/scheduler, включите maintenance, верните точный согласованный комплект public/private/vendor **0.7.6**. Сохраните `.env`, APP_KEY, весь storage, branding, sessions, deployed `.htaccess` и maintenance marker. Пересоберите caches старым кодом, выполните smoke, затем возобновите HTTP/scheduler.

`migrate:rollback` не нужен: схема в 7.7 не менялась. Исходные задачи, цены, формальное согласование и загруженные изображения не изменяются экспортом. Старый шаблон и прежние неоднозначные подписи итогов вернутся ожидаемо. Дополнительные ключи данных игнорируются старым renderer; старый API возвращает PNG и новый renderer его поддерживает.

При проблеме `.htaccess` восстановите точный backup атомарно через независимый доступ. Не удаляйте файл и не подменяйте универсальным. Локальная проверка пакета не доказывает состояние production-хостинга.
