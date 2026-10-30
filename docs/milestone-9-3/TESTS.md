# Milestone 9.3 — проверки

Проверки выполняются только на одноразовых QA DB, локальных HTTP/HTTPS-серверах, SMTP sink и извлечённом production пакете. Production DB и Jino не используются.

## Автоматические проверки

- Frontend: **389 / 389** node tests. Новые проверки gate после MFA/перед рекомендациями, безопасного renderer, four-locale статусов и футера без произвольных ссылок/приватных полей. Existing branding fixtures расширены новым разделом footer.
- Laravel: **987 tests / 14008 assertions**, полный suite. Focused tests включают соглашение on/off, четыре языка/UTC date, direct business API gate, повторный вход, идемпотентность, stale revision, обычные правки/explicit reaccept, изоляцию workspace, owner-only settings, структурное XSS validation, сохранение пробелов между форматированными фрагментами, opt-in публичных контактов, signed Passkey совместимость и сохранение acceptance evidence после удаления доступа. Migration up/down с baseline records — отдельный тест; default disabled, grants/accounts не меняются.
- PHP **8.4.26**, MariaDB **11.8.9**, отдельная `projectbrief_m93_qa`, исходники read-only: **119 tests / 1309 assertions**. Проверены соглашения, signed Passkey, администраторам/SSO ACL, приглашения, platform и текущая MFA-политика. Один прежний image-fixture test исключён только из этого focused container run из-за отсутствия GD; он проходит в полном native suite. Migration fixture up/down использует собственную SQLite connection; MariaDB выполняет реальные миграции и business/security tests на отдельной свежей QA схеме.
- Pint `--dirty --format agent`, Prettier для изменённых frontend файлов и `git diff --check`.

## Браузер

- `milestone92.browser.mjs`: **3 группы, 64 снимка, 0 JS errors**. Existing administrator create/edit/invite/email resend/link rotation/copy/accept/status/permissions/delete и SSO create/edit/one-time key secret/grants/confirmed delete. Ошибки сохраняют поля/выделение, получают фокус; Escape/backdrop и прокрутка/действия работают. 4 языка × 1440/390 × light/dark. Evidence: `artifacts/milestone-9-3/management/sqlite`.
- `milestone93.browser.mjs`: соглашения и футер, включая editor heading persistence, keyboard tabs, owner opt-in публичных контактов в штатном branding UI, четыре реальные invitation registrations, отсутствие меню и API bypass, server acceptance, ошибка/повтор, последующие security recommendations, обычные правки/private draft/explicit reaccept, карточки и expanded metadata, logout без принятия, публичный документ без входа и print. **5 групп, 54 снимка, 0 JS errors**. 4 языка × 1440/390 × light/dark; дополнительные карточки/модалка и client footer. Итоговый JSON и снимки: `artifacts/milestone-9-3/agreements/sqlite`.
- `parallel-passkey.browser.mjs`: **4 группы, 0 JS errors**, две вкладки одной HTTPS cookie session, реальные signed UV assertions, ротация сессии, double click/one mutation, replay/TTL/retry/cancel/TOTP fallback. См. PASSKEY.md.
- `client-sso.browser.mjs`: **12 сценариев, 9 снимков**, production frontend между двумя локальными HTTPS-сайтами; Origin/TTL/single-use/bindings/provenance/revocation/Magic Link сохранены.
- `account-security.browser.mjs`: **23 сценария**, TOTP/recovery, optional Passkey enrollment, реальные Chromium UV assertions и клиентские сессии, cancel/retry/fallback/one mutation; Firefox mock-provider serializer/user activation. WebKit и physical Touch ID не выполнялись.

Визуальная проверка оценивает заголовки/подсказки, поля, чекбоксы, компактные карточки, длинные модалки, статусы соглашения, footer/sidebar и длинные названия. После просмотра исправлены тесные группы SSO auto/manual projects, приглашений и интервалы публичного документа. Каждый snapshot scenario проверяет отсутствие horizontal overflow; это дополнение к просмотру реальных изображений, не замена ему.

## Production ZIP

Канонический `./bin/build-release` требует чистый локальный source commit, повторно выполняет frontend/build/Laravel, устанавливает production-only Composer vendor, проверяет исключения/секреты, CRC и внутренние SHA-256 и запускает runtime из извлечённого ZIP. В этом релизе compiled-browser runtime также проверяет WYSIWYG publication, anonymous public document, регистрацию по приглашению, API gate и server acceptance, дополнительно к real signed UV step-up и единственной мутации прав. Canonical-domain browser interception направлен исключительно на localhost; это не проверка production домена.

Точные source commit, ZIP/SHA-256/bytes, внутренние counts и итог извлечённого PHP 8.4/MariaDB probe фиксируются в `RELEASE-ARTIFACT.json` после сборки. Memory limit 512M применяется только к QA suite из временного ini; production PHP config не меняется. Local tests не подтверждают live FPM, SMTP delivery или биометрию физического устройства.

Финальный ZIP `project-brief-v0.9.3-6dd988b.zip`: **39313874 bytes**, независимый CRC и **8893 внутренних SHA-256** прошли; 8894 файлов с checksums.txt, 10139 ZIP entries. Извлечённый production vendor проверен на PHP 8.4.26 / MariaDB 11.8.9: lint, fresh QA schema, восемь administrator routes, оба новых поля и acceptance table/unique index. Compiled UI из ZIP прошёл anonymous document, invitation → gate → acceptance и signed UV/one mutation; отчёт `artifacts/milestone-9-3/package`. Screenshot evidence остаётся на диске, новые изображения не входят в Git или ZIP. QA контейнеры и сеть удалены после проверки.
