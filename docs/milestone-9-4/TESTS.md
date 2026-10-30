# v0.9.4 — проверки

## Реализованные регрессии

- `ClientTaskStatusTest`: вся матрица шести исходных × шести целевых статусов, оба значения clientApproved. Разрешены только review→approved/done. Проверены editVersion, запрещённые/смешанные поля, создание задачи, чужой проект, отозванный клиент, admin endpoint, обычное согласование, audit и локализация notification. Владелец и назначенный разработчик сохраняют прежнюю матрицу всех переходов.
- `GlobalWorkspaceBrandingTest`: выбранный, единственный, неоднозначный, чужой и устаревший workspace; отсутствие membership; A→B→A; глобальность personal preferences; приватные реквизиты не публикуются.
- Frontend: store write guard, одна запись без оптимистичного изменения, 409→загрузка состояния, четыре языка, locked states; passive gesture intent/distance/timeout/selection, controls/horizontal areas, touchcancel/navigation, cleanup.
- Focused native PHP после session lock: **41 тест / 843 assertions**, включая Passkey parallel confirmation и security fallback. PHP 8.4.26 + MariaDB 11.8.9: **71 тест / 1607 assertions**. Один GD upload case не включён в этот ограниченный контейнер; он остаётся в полном native Laravel suite.
- Полный frontend до финальной сборки: **403/403 PASS**. Финальная canonical сборка: **403/403 frontend; 1001/1001 Laravel, 14 729 assertions**. Pint и форматирование изменённых файлов — PASS. Старые ожидания запрета поля status обновлены на version validation и server transition denial. В точных тестах freshFor=300 зафиксированы часы, чтобы пересечение секунды не давало случайное 299; TTL приложения не изменён.

## Браузер

`tests/milestone94.browser.mjs` запускает копию API, локальный Vite, SMTP sink и одноразовую SQLite. Для MariaDB требуется явный M94_QA_MYSQL=1; имя БД жёстко ограничено projectbrief_m94_qa. Никаких production credentials или данных. Оба fixtures проверяют testing и допустимую disposable DB.

Проверяются профиль/security/notifications через SPA, F5, direct URL, browser history, A→B→A, задержка bootstrap и нейтральный `/platform`; system theme реагирует на изменение media preference. При проверке логотипа ожидается полная загрузка изображения, а не только появление img в DOM.

Клиент: cancel без PATCH, 503 без изменения review, подтверждение/двойной клик с одной записью, approved, locked in_progress/done; конкурирующий admin update даёт 409 и фактический in_progress. Отдельно MariaDB + четыре PHP HTTP workers выполняют десять конкурентных пар из разных клиентских сессий: в каждой один 200, один 409, одна клиентская status history и согласованное состояние БД.

CDP dispatchTouchEvent создаёт доверенные события Chromium с isTrusted=true: admin/client/task drawer open/close, vertical scroll, browser touchcancel, ввод в поле, горизонтальная прокрутка; hamburger/backdrop/Escape сохраняются. Unit tests дополняют это проверкой mouse/desktop, multi-touch, выделения, таймаута и удаления обработчиков.

Визуальная матрица: 4 языка × 1440/390 px × light/dark × 6 экранов (profile, security, notifications, review, confirmation, done) = **96 локальных снимков**, проверка horizontal overflow. Ручная проверка отступов, переносов, sidebar/footer, длинных названий и размеров компактного диалога. Скриншоты не коммитятся; только небольшие JSON-результаты.

**Ограничение:** WebKit локально зависает на newPage после запуска и создания context; проверены headless и headed, каждый ограничен 25 секундами. В headed stderr — native layout recursion. Ни приложение, ни iOS Safari этим запуском не проверены. Физические iOS Safari/Android Chrome, системный Back и Jino/FPM требуют отдельного smoke на устройствах/хостинге. Они не подменяются PASS виртуального Chromium.

## Production ZIP

Канонический `./bin/build-release` включает npm ci/tests/build, brand check, Composer dev tests, no-dev optimized vendor, platform requirements, allowlist/secret scan, CRC/internal SHA-256, извлечение ZIP, signed WebAuthn protocol tests и HTTP runtime. Дополнительно compiled UI проверяет реальный virtual-UV step-up, administrators и agreements v0.9.3, персональный бренд и новый client completion/cancel. Новые assertions проверяют версию 0.9.4 и инструкцию migrate --force, без требования несуществующей новой миграции.

PHP 8.4/MariaDB проверка самого извлечённого ZIP и обновления с v0.9.3 фиксируется отдельно в итоговом release report. Push, deployment, production DB и отправка реальной почты не выполняются.

## Итог извлечённого архива

`./bin/build-release` — **PASS**, source commit `7cb36ab`. Независимо проверены CRC и **8894 внутренних checksum**, 8895 файлов (включая manifest checksums), 10140 ZIP entries. Архив **39320704 bytes**; точный SHA-256 — в SHA256SUMS.txt. Allowlist/secret scan — PASS; QA screenshots отсутствуют в ZIP и новых коммитах.

Извлечённые compiled assets с no-dev vendor прошли UV step-up/одну team mutation, публикацию/публичный документ/приглашение/API gate/принятие соглашения, бренд персональных страниц и client completion/cancel. Клиент входит в отдельной сессии через фактический localhost origin с проверкой canonical 302, затем серверных client role и review; HTTPS proxy применяется к виртуальному production origin/RP ID WebAuthn. Этот тест не обращается к реальному сайту.

Сам ZIP на **PHP 8.4.26 + MariaDB 11.8.9**: lint всех app/routes/config/migrations, восемь client task routes, client transition policy, session serialization — PASS. Сначала установлена схема из сохранённого production ZIP v0.9.3, затем запущен v0.9.4 migrate --force: **Nothing to migrate**, workspace UUID и бренд сохранены. Проверка production vendor без PHPUnit — PASS.

`parallel-passkey.browser.mjs` повторно выполнен на коде v0.9.4: **4/4 группы, JS errors 0**. Одна cookie session, две вкладки Integrations и /platform: независимые подписанные UV attempts завершаются успешно после session rotation; одна запись ключа при double click; replay/TTL отклоняются, Retry работает, cancel сохраняет draft, TOTP fallback разрешён политикой. Сохранённое исправление challenge isolation и границы первопричины PR/PK_CHALLENGE_INVALID описаны в [отчёте v0.9.3](../milestone-9-3/PASSKEY.md).

Итоговые небольшие JSON-доказательства: `artifacts/milestone-9-4/`. Контейнер и сеть одноразовой MariaDB QA удалены после проверок. Production и remote Git не изменялись.

## UX редактора соглашений

Дополнительные регрессии: безопасные href в draft/published/public/required document и сохранение acceptance; запрет javascript/data/vbscript, credentials, control characters, mail headers и неизвестных attrs. WorkspaceAgreementTest: **12/12, 227 assertions**. Полный frontend: **403/403**. Нативный Chromium проверяет выделение, H1/H2/абзац, bold/italic, оба списка с сохранением нумерации после смены языка, insert/edit/unlink, Undo/Redo URL и текста ссылки, ошибку URL и закрытие Escape. После сохранения/reload ссылки проверены в публичном документе и у приглашённого администратора. **32 локальных снимка** редактора и link dialog: четыре языка, 1440/390, light/dark; horizontal overflow отсутствует, отступы и checkbox alignment проверены. Скриншоты находятся только во временной папке, JSON — artifacts/milestone-9-4/agreement-editor/results.json.
