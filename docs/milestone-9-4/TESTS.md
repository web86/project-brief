# v0.9.4 — проверки

## Реализованные регрессии

- `ClientTaskStatusTest`: вся матрица шести исходных × шести целевых статусов, оба значения clientApproved. Разрешены только review→approved/done. Проверены editVersion, запрещённые/смешанные поля, создание задачи, чужой проект, отозванный клиент, admin endpoint, обычное согласование, audit и локализация notification. Владелец и назначенный разработчик сохраняют прежнюю матрицу всех переходов.
- `GlobalWorkspaceBrandingTest`: выбранный, единственный, неоднозначный, чужой и устаревший workspace; отсутствие membership; A→B→A; глобальность personal preferences; приватные реквизиты не публикуются.
- Frontend: store write guard, одна запись без оптимистичного изменения, 409→загрузка состояния, четыре языка, locked states; passive gesture intent/distance/timeout/selection, controls/horizontal areas, touchcancel/navigation, cleanup.
- Focused native PHP после session lock: **41 тест / 843 assertions**, включая Passkey parallel confirmation и security fallback. PHP 8.4.26 + MariaDB 11.8.9: **71 тест / 1607 assertions**. Один GD upload case не включён в этот ограниченный контейнер; он остаётся в полном native Laravel suite.
- Полный frontend до финальной сборки: **400/400 PASS**. Финальная canonical сборка повторяет frontend и полный Laravel suite; итог записывается в RELEASE-ARTIFACT.json.

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
