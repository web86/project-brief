# Project Brief 0.7.6 — обновление и откат

Обновление установленной 0.7.5. Deployment, push и merge для 7.6 не выполнялись. Локальные проверки не заменяют staging на Jino / PHP 8.4 / MariaDB и отдельное разрешение на production.

## Схема и файлы

Новых миграций в 7.6 нет. При переходе с 0.7.5 используются существующие JSON `workspaces.branding`, private disk и очередь `project_file_deletions`. При переходе с 0.7.4 сначала учитывайте добавленную в 7.5 миграцию `2026_10_27_215858_add_branding_to_workspaces.php`; старые данные сохраняются. Также сохраняются предыдущие миграции, включая `2026_10_26_200540_add_project_trash_lifecycle` и `2026_10_27_203324_create_project_feedback_table`.

Растровые загрузки сохраняются одним нормализованным PNG, без исходного JPEG/WebP/PNG. SVG хранится очищенным `.svg` с PNG-компаньоном для email/DOCX/PDF. Формат путей и основной PNG совместимы с 0.7.5; новые размеры/MIME/счётчики байт — метаданные внутри JSON. Старые изображения не переписываются пакетно. Очистка заменённых файлов остаётся отложенной через прежний scheduler; не удаляйте storage и не обнуляйте очередь.

Публичны только визуальные файлы по случайному токену. SVG доступен как `image/svg+xml` с CSP sandbox и nosniff; никакие реквизиты или исходный XML в HTML не выдаются. Письма используют PNG. Смена browser favicon не меняет автоматически иконку уже установленной PWA; manifest/Apple/maskable остаются платформенными.

## Обновление

1. Сверьте внешний SHA-256 и размер ZIP с RELEASE-ARTIFACT.json, полный CRC и внутренний checksums.txt. Репетируйте на закрытом staging. Сохраните согласованный backup БД, установленного public/private/vendor, `.env`, APP_KEY, всего storage, cron и точного deployed `.htaccess` вне public. Подготовьте независимый SFTP/SSH/panel доступ для отката.
2. Остановите записи/scheduler, дождитесь запросов, из private app выполните `php artisan down`.
3. Замените public/private/vendor согласованным комплектом ZIP по DEPLOY-JINO.md. Сохраните `.env`, APP_KEY, storage/uploads/branding/sessions и maintenance marker; не накладывайте пустой storage пакета поверх данных. Не запускайте first-install, key:generate, seed, migrate:fresh, db:wipe или recovery. Не меняйте origin/RP ID, cookies, срок/шифрование сессий, MFA и политику security step-up.
4. Проверьте именно web PHP 8.4: прежние расширения, GD с поддержкой JPEG/PNG/WebP, DOM/libxml, fileinfo, mbstring, zip; память для декодирования до 4096 px / 16 млн пикселей. Разрешённый web upload/post должен вмещать исходник до 2 MiB, PNG-компаньон SVG до 4 MiB и multipart overhead. CLI ini не доказывает настройки web handler.
5. В maintenance выполните:

   ```sh
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   ```

   На обновлённой 0.7.5 новых pending migrations не ожидается. Не заменяйте `.env` примером. Host-dependent HTTP hardening оставьте в прежнем согласованном состоянии.

6. Выполните smoke ниже. После отдельного разрешения выполните `php artisan up` и возобновите прежний scheduler, cleanup, feedback-mail retry и webhook jobs. Новых cron, credentials, DNS, SMTP sender или worker в 7.6 нет.

## Smoke на staging

- Owner: >20 проектов, поиск имени/клиента/email, trim, 1–2/3+ символа, очистка, sort/status/page20, URL/возврат, trash/restore. Developer: только назначенные проекты; поиск клиента лишь при существующем `manage_clients`, email не раскрывается в списке. Чужой workspace недоступен.
- Глобальный sidebar без списка проектов, «Все проекты» с правильной стрелкой, Profile/Security и owner-only Branding. Проектная группа раскрывается Enter/Space, активная страница раскрывает её. Drawer/Escape/Tab/touch и высота 480–600 px; профиль остаётся доступным.
- Изменить visual/contact/business, нажать одну Save, reload: всё сохранено. Невалидная секция отклоняет всё. Две вкладки с изменением одной секции: 409, черновик остаётся, явное обновление базы/проверка/повтор. Разные секции другой вкладки не перезаписываются. Изменения во время запроса остаются новым черновиком.
- Истечь fresh proof и загрузить favicon: Passkey/TOTP, автоматическое продолжение исходной операции один раз. Cancel/сеть: прежняя картинка и выбранный файл остаются, доступен явный retry/discard. Ни ложного успеха, ни красного retry-баннера после успешного Passkey. Security policy и пяти минутная свежесть сохранены.
- Загрузить SVG с прозрачностью/градиентом, PNG/JPEG/WebP около 2 MiB. Проверить реальные проценты, отдельную обработку, блокировку дубля, итоговые байты и читаемость. SVG через img и прямой URL безопасен, PNG fallback доступен. Заменить/удалить/сбросить и проверить отложенный cleanup.
- Два workspace с разными брендами, owner/developer/client, light/dark, RU/EN/TR/ZH. При начале смены прежний бренд сбрасывается; личный Profile/Security/login и выход — оформление платформы. Статусы success/error/warning/info сохраняют смысл и контраст. Breadcrumb аккаунта не меняет workspace scope данных.
- PDF/DOCX с SVG- и JPEG-источником, Unicode/кириллица/Çığ/中文, суммы/оценки, реквизиты/контакты по флагам, footer/нумерация. Брендированные и системные email через прежний транспорт: From/ссылки/доставка; реальный SMTP не доказан локальными fake/render tests.
- Прежние файлы, уведомления, клиентский доступ, TOTP/Passkeys, корзина, export limits и PWA работают. Домены/собственный отправитель остаются disabled «Скоро».

## Откат на 0.7.5

Остановите записи/scheduler, включите maintenance, верните согласованный предыдущий комплект public/private/vendor **0.7.5**. Сохраните `.env`, APP_KEY, весь storage, branding и sessions, deployed `.htaccess` и maintenance marker. Пересоберите caches прежним кодом, выполните обычный smoke, затем возобновите HTTP/scheduler.

Не выполняйте `migrate:rollback`: в 7.6 нет новой схемы, а down() миграции 7.5 намеренно блокирует удаление реквизитов. PNG-пути и SVG-компаньоны совместимы с 7.5; браузер снова использует PNG и бренд в клиентском портале, возврат к раздельным Save ожидаем. Новые JSON-метаданные игнорируются. Данные и очищенные SVG остаются для повторного обновления. Удалённый до отката asset не нужно пытаться восстанавливать без backup.

Если откат связан с `.htaccess`, атомарно верните **точный сохранённый deployed файл** через независимый доступ. Не удаляйте его и не подменяйте универсальным файлом. APP_KEY, сессии, links и факторы безопасности для отката не сбрасываются.

## Сохранённые границы HTTP hardening

`HTTP_BOOTSTRAP_LIMIT_ENABLED=false` до отдельного Jino proxy/NAT preflight. `<IfDefine PROJECT_BRIEF_NATIVE_ERRORS>` остаётся неактивным до проверки разрешения ErrorDocument. Wildcard trusted proxies, новые HSTS/CSP всей страницы, WAF/демоны и изменения production logs в 7.6 не вводятся. CSP здесь ограничен публичным изображением SVG/PNG.

Перед production отдельно подтвердите document root, Apache modules/AllowOverride, web PHP/body/memory/quotas, HTTPS peer/proxy, права и retention логов, маскирование access links до записи. Состояние Jino по этим пунктам UNKNOWN. Не используйте публичный phpinfo, сканеры, нагрузочные циклы или исчерпание лимитов на production. Подробный прежний preflight остаётся в HTTP hardening документации проекта.
