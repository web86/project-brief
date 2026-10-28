# Milestone 7.6 — проверки

## До финального архива

- `npm test`: **314/314 PASS**. Universal query/короткий ввод, nav context/bootstrap, owner/developer menu, all-locale breadcrumbs, brand boundaries, atomic save, in-flight edits, stale context, conflict/three-way rebase, retry файла и XHR progress. Точный sensitive allowlist, один replay исходного FormData, второй 423 без цикла.
- `php artisan test --compact --filter=UxRefinementsTest`: **6/6, 77 assertions PASS**. Роли/workspace, EXISTS, pagination22/20+2, identity permissions, CAS/validation/transaction rollback, safe SVG/PNG exports/email, measured raster/alpha/favicon/storage, fresh proof до любого write.
- `node tests/ux-refinements.browser.mjs` (compatibility entry `tests/branding-pagination.browser.mjs`): **8/8 PASS**. Шесть admin комбинаций 390/768/1440 × light/dark, RU/TR/EN/ZH, два client portal/exit. Реальный Vue/router/stores с API fixtures: debounce480, 1–2 символа без text API, page20/page2/URL/filter, global save, SVG canvas/multipart, retry/leave/reset, четыре toast, 480/600px sidebar, keyboard и CDP touch. 74 screenshot, BROWSER-RESULTS.json.
- `M76_BRANDING_QA=1 node tests/account-security.browser.mjs`: **27/27 PASS** на реальном isolated Laravel/HTTPS/SQLite/private storage. Chromium signed UV virtual authenticator: expired proof → favicon sanitize/upload один раз, без retry banner; atomic visual/contact/bank/reload; direct SVG navigation; throttled real XHR bytes, старый logo до success, финальный PNG362399; два workspace с задержанным select response для проверки немедленного reset и личная platform appearance. Включена прежняя TOTP/recovery/password/CSRF/login/team/client регрессия. Firefox — реальный движок serializer/user activation и mock provider с настоящим backend.
- Реальные ProjectExportData/PHPWord DOCX и browser PDF worker/pdfmake с теми же TTF: JPEG-source → финальный PNG, ZIP media=PNG, Unicode extraction, 1 page PDF/DOCX. PDF отрисован Poppler, DOCX LibreOffice/render_docx.py, просмотрены шапка/текст/footer/нумерация. EXPORT-RESULTS.json и export-previews/.
- Existing `node tests/appearance.browser.mjs`: **11/11 PASS**, включая desktop/mobile light/dark, контраст текста, 320/360/390 px, localStorage failure и production splash. В старую фикстуру добавлен существующий feedback API из 7.4; product code для этого не менялся. [APPEARANCE-RESULTS.json](APPEARANCE-RESULTS.json).
- Existing `node tests/branding-loading.browser.mjs`: **8/8 PASS**: delayed entry/reload, platform logo/assets, light/dark/system, narrow drawer, JS failure/retry/timeout, navigation overlay без пересоздания splash. Добавлен output-dir override для сохранения исторических screenshots; [PLATFORM-BRANDING-RESULTS.json](PLATFORM-BRANDING-RESULTS.json).
- Pint `--dirty --format agent`, Prettier изменённых файлов, `git diff --check`.

Browser-команды используют установленный Playwright через `PLAYWRIGHT_MODULE`, новых application dependency нет. Network/server проверки используют disposable SQLite/storage/test identities и не меняют production.

## Каноническая production-сборка

Каноническая команда `NODE_EXTRA_CA_CERTS=/etc/ssl/cert.pem ./bin/build-release` завершилась полностью: **314/314 frontend, 807/807 Laravel, 9 777 assertions**, production vendor, packaged signed WebAuthn и HTTP runtime. Проверены 8 837 runtime-файлов, внутренние checksums и дополнительный полный CRC готового ZIP. Архив собран из чистого `1daca1c1f3d38f078d471625cb0938d37e0a34c1`. Последующие изменения касаются evidence и старых browser fixtures, runtime приложения и ZIP не меняют. [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json): 39 201 686 байт, SHA-256 `a068689374d49abe94f73ecab9acd817235b2ef01526337883dc9e31e55bd42d`.

Builder выполняет npm ci/test/brand:check/build, source Composer + полный Laravel suite, production vendor без dev, dependency/platform checks, pruning/secret scan/allowlist, checksums, чистую ZIP extraction, signed WebAuthn packaged regression и packaged HTTP. Последний дополнен atomic three-section CAS/409/422 rollback, SVG upload/public CSP/PNG fallback/reset/404, сохраняет прежние export/email/trash/integration checks. Production PDF quota не ослаблена: counters сбрасываются только между фазами disposable QA installation.

## Границы доказательств

Локально: macOS, PHP 8.5.10/GD, SQLite, Chromium, Firefox. Jino web PHP 8.4/MariaDB, production DNS/SMTP delivery/email client matrix, физические Touch ID/Android/iOS и реальный WebKit не проверялись. Safari API shapes покрыты unit tests, но это не live Safari. Mobile screenshots/CDP touch не равны физическому устройству. PWA installed icon replacement не реализована. Deployment, push и merge для 7.6 не выполнялись.
