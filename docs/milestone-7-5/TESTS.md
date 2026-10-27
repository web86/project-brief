# Milestone 7.5 — проверка

## Выполнено до production-сборки

- `php artisan test --compact --filter='WorkspaceBrandingTest|ProjectPaginationTest'`: 11 tests, 110 assertions, PASS. Роли/чужой и сменившийся workspace, section merge, валидация, приватность, raster/SVG normalization и подгонка малого favicon без искажения пропорций, durable file cleanup, reset, экспортные флаги, письма и системный fallback, Unicode, пагинация/фильтры/сортировка/права.
- `npm test`: 308 tests, PASS. Включая шесть новых проверок контрастности, сохранения in-flight черновиков, ошибок/stale response, URL allowlist, отложенной sidebar-страницы после удаления и сброса с сохранением бизнес-черновиков.
- `node tests/branding-pagination.browser.mjs`: 6 admin-комбинаций 390/768/1440 × light/dark с RU/TR/EN/ZH, плюс 2 client portal/exit сценария, PASS. Реальные Vue/router/stores, API fixture. Проверены все шесть секций, SVG→canvas→multipart, retry после ошибки, dirty leave/reset, 20 карточек, page2, debounce/URL/возврат, короткий drawer600px, touch gesture, Shift+Tab/focus, masks, load more, профиль, client color/logo/favicon и возврат к платформенному виду.
- [BROWSER-RESULTS.json](BROWSER-RESULTS.json), 62 screenshots в screenshots/.
- `BRANDING_EXPORT_QA_DIRECTORY=... php artisan test --filter=WorkspaceBrandingTest` + `node tests/branding-export.browser.mjs`: пять пар PDF/DOCX (RU/EN/TR/ZH + стандартный без оформления), реальные ProjectExportData, PHPWord и PDF worker/pdfmake/fonts. PDF проверены pypdf, DOCX — ZIP/XML; кириллица, Çığ/Müşteri и 客户账户 сохранены; банковский блок один раз, footer без банковского счёта, hyperlink платформы и выключенные флаги проверены. [EXPORT-RESULTS.json](EXPORT-RESULTS.json).
- DOCX отрисованы LibreOffice через render_docx.py, PDF — Poppler; просмотрены страницы. Исправлены таблица шапки и перенос целиком короткого блока реквизитов. PNG-превью — export-previews/, исходные QA-файлы — только игнорируемый artifacts/.
- Pint dirty, Prettier изменённых файлов, `git diff --check`.

Для browser-команд используется Playwright из bundled runtime через `PLAYWRIGHT_MODULE`; он не добавлен зависимостью приложения. В test/dev сервере PDF-шрифты подаются из тех же локальных TTF, что входят в production build.

## Каноническая сборка

Каноническая команда `NODE_EXTRA_CA_CERTS=/etc/ssl/cert.pem ./bin/build-release` прошла полностью: frontend308/308, Laravel801/801 и9700 assertions, production vendor, signed WebAuthn и packaged HTTP. Проверены8837 файлов, внутренние checksums и дополнительный полный CRC ZIP. Размер и SHA-256 записаны в [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json). Builder делает npm ci/test/brand:check/build, source Composer + Laravel tests, отдельный production vendor, dependency/platform checks, pruning/secret scan, checksums, ZIP CRC/extraction, signed WebAuthn и HTTP runtime из чистого архива.

Packaged HTTP дополнен реальными branding GET/PATCH/upload/sanitize/image/reset, банковским export allowlist, foreign-workspace denial и pagination metadata/filter/clamp. Фикстуры живут в disposable SQLite/storage, никогда в upload ZIP.

## Границы доказательств

PHP 8.5.10/SQLite/macOS проверены локально. PHP 8.4, MariaDB, web GD/WebP и фактический SMTP transport/delivery на Jino не запускались. Mail::fake и render проверяют оформление/выбор workspace/ссылки, но не совместимость всех email-клиентов и доставку адресату. Safari и физические touch-устройства не проверены. Браузер UI-матрицы — Chromium; существующая регрессия и packaged security проверяются каноническим builder. Deployment и публикация ветки7.5 не выполнялись.
