# Milestone 7.6 — проверки

## До финального архива

- `npm test`: **314/314 PASS**. Universal query/короткий ввод, nav context/bootstrap, owner/developer menu, all-locale breadcrumbs, brand boundaries, atomic save, in-flight edits, stale context, conflict/three-way rebase, retry файла и XHR progress. Точный sensitive allowlist, один replay исходного FormData, второй 423 без цикла.
- `php artisan test --compact --filter=UxRefinementsTest`: **6/6, 77 assertions PASS**. Роли/workspace, EXISTS, pagination22/20+2, identity permissions, CAS/validation/transaction rollback, safe SVG/PNG exports/email, measured raster/alpha/favicon/storage, fresh proof до любого write.
- `node tests/ux-refinements.browser.mjs` (compatibility entry `tests/branding-pagination.browser.mjs`): **8/8 PASS**. Шесть admin комбинаций 390/768/1440 × light/dark, RU/TR/EN/ZH, два client portal/exit. Реальный Vue/router/stores с API fixtures: debounce480, 1–2 символа без text API, page20/page2/URL/filter, global save, SVG canvas/multipart, retry/leave/reset, четыре toast, 480/600px sidebar, keyboard и CDP touch. 74 screenshot, BROWSER-RESULTS.json.
- `M76_BRANDING_QA=1 node tests/account-security.browser.mjs`: **27/27 PASS** на реальном isolated Laravel/HTTPS/SQLite/private storage. Chromium signed UV virtual authenticator: expired proof → favicon sanitize/upload один раз, без retry banner; atomic visual/contact/bank/reload; direct SVG navigation; throttled real XHR bytes, старый logo до success, финальный PNG362399; два workspace с задержанным select response для проверки немедленного reset и личная platform appearance. Включена прежняя TOTP/recovery/password/CSRF/login/team/client регрессия. Firefox — реальный движок serializer/user activation и mock provider с настоящим backend.
- Реальные ProjectExportData/PHPWord DOCX и browser PDF worker/pdfmake с теми же TTF: JPEG-source → финальный PNG, ZIP media=PNG, Unicode extraction, 1 page PDF/DOCX. PDF отрисован Poppler, DOCX LibreOffice/render_docx.py, просмотрены шапка/текст/footer/нумерация. EXPORT-RESULTS.json и export-previews/.
- Pint `--dirty --format agent`, Prettier изменённых файлов, `git diff --check`.

Browser-команды используют установленный Playwright через `PLAYWRIGHT_MODULE`, новых application dependency нет. Network/server проверки используют disposable SQLite/storage/test identities и не меняют production.

## Каноническая production-сборка

Команда: `NODE_EXTRA_CA_CERTS=/etc/ssl/cert.pem ./bin/build-release`. Итоговые результаты, размер и SHA-256 добавляются после завершения сборки.

Builder выполняет npm ci/test/brand:check/build, source Composer + полный Laravel suite, production vendor без dev, dependency/platform checks, pruning/secret scan/allowlist, checksums, чистую ZIP extraction, signed WebAuthn packaged regression и packaged HTTP. Последний дополнен atomic three-section CAS/409/422 rollback, SVG upload/public CSP/PNG fallback/reset/404, сохраняет прежние export/email/trash/integration checks. Production PDF quota не ослаблена: counters сбрасываются только между фазами disposable QA installation.

## Границы доказательств

Локально: macOS, PHP 8.5.10/GD, SQLite, Chromium, Firefox. Jino web PHP 8.4/MariaDB, production DNS/SMTP delivery/email client matrix, физические Touch ID/Android/iOS и реальный WebKit не проверялись. Safari API shapes покрыты unit tests, но это не live Safari. Mobile screenshots/CDP touch не равны физическому устройству. PWA installed icon replacement не реализована. Deployment, push и merge для 7.6 не выполнялись.
