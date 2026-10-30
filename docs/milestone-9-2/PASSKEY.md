# Passkey challenge: причина, исправление и две вкладки

## Что подтверждено

В присланном описании ошибка названа `PR_CHALLENGE_INVALID`. Backend этой базы использует префикс **PK**: `PK_CHALLENGE_INVALID`, `PK_CHALLENGE_MISMATCH`, `PK_CHALLENGE_SESSION` и т. д. Исходные данные production/Jino, request IDs и live logs не исследовались; нельзя утверждать, какой именно branch diagnostics сработал в исходном инциденте. Ниже — подтверждённый локально дефект, объясняющий конфликт подготовленных операций.

В v0.9.1 `AccountSecurity::challenge()` удалял **все** прежние строки с теми же session_hash и purpose. `AccountSecurityController::options()` также записывал один session slot `security.passkey_confirm`. Frontend хранил свои options в памяти вкладки и подготавливал их при входе/focus/по таймеру; body подтверждения не содержал attempt ID. `/platform` и workspace API используют общий purpose `passkey_confirm`, поэтому подготовка во второй вкладке удаляла запись первой и заменяла pointer.

Регрессионный тест на исходной реализации сначала создавал options A, затем options B в той же сессии. До исправления assertion подтвердил, что строка A исчезла, а B осталась (`artifacts/milestone-9-2/passkey-before.json`). При обращении к исчезнувшему ID consume выдаёт PK_CHALLENGE_INVALID; legacy session pointer может направить подпись A в options B и получить PK_CHALLENGE_MISMATCH, потребив при этом B. Это не проблема подписи, RP ID или user verification.

Дополнительный связанный дефект: успешное MFA/Passkey подтверждение ротирует session ID. Просто убрать перезапись недостаточно — оставшиеся challenge всё ещё были бы привязаны к прежнему session_hash.

## Исправление

- Используются **существующие** строки account_security_challenges. Незавершённые WebAuthn attempts не удаляют друг друга. TTL WebAuthn остаётся **2 минуты**, global step-up proof — **5 минут**.
- Options response содержит opaque UUID `attemptId` отдельно от WebAuthn options. Frontend хранит пару только в памяти вкладки и передаёт ID в POST body вместе с credential. Он не помещается в URL/storage/logs. Старый session pointer сохранён только как compatibility fallback для уже открытых старых клиентов.
- Consume проверяет purpose, session_hash, user ID, auth_session_version, TTL и context_hash (user/version/workspace/policy). Креденциал проверяется против options **именно этой попытки**, затем обычной библиотекой с прежними подписью, RP ID, Origin, userHandle и UV. Между login/register/confirm ID не переносится.
- Условный DELETE атомарно потребляет только одну подходящую строку. Ошибка подписи не позволяет повторно использовать уже потреблённый attempt. Чужая сессия/пользователь/контекст не получает proof. Обычные session locks маршрутов сохранены.
- При успешной ротации authenticated session только ещё действующие register/confirm attempts того же user/version переводятся с прежнего hash на новый hash. Login attempts не переносятся. Смена факторов, полномочий и auth_session_version сохраняет прежние механизмы отзыва.
- Подготовка options в одной вкладке и retry warming coalesce повторные запросы. Native get остаётся синхронным с user gesture. Expired/invalid attempt показывает действующую локализованную ошибку, готовит новый challenge и допускает явный Retry либо разрешённый TOTP/recovery fallback. Пароль не становится заменой MFA владельца/platform admin.

Step-up остаётся существующим общим пятиминутным подтверждением личности. Attempt привязан к назначению WebAuthn ceremony; он не является новым разрешением на произвольную business operation. Каждый защищённый endpoint повторно проверяет owner/platform/tenant ACL и свежесть proof.

## Реальное воспроизведение двух вкладок

`tests/parallel-passkey.browser.mjs` использует одну cookie session и два page target Chromium через локальный HTTPS:

1. A (`/admin/settings/integrations`) готовит challenge, виртуальный authenticator создаёт **реальную подписанную UV assertion**. POST временно удерживается network harness.
2. B открывает `/platform`, независимо подготавливает подтверждение; attempt IDs различаются.
3. POST A выпускается: **200**, исходное создание API-ключа выполняется **один раз**, несмотря на double click.
4. B подтверждается со своими options после ротации session: **200**, platform overview открывается. Счётчик одного виртуального устройства переносится между target, чтобы не имитировать клонированный hardware со сброшенным counter.
5. Повтор подписи A: **422 / PK_CHALLENGE_INVALID**. Истечение отдельного attempt: **422 / PK_CHALLENGE_EXPIRED**, явный Retry успешен с новым challenge. Browser cancel сохраняет черновик и не создаёт ключ; TOTP fallback подтверждает операцию один раз.

Все четыре группы browser scenarios прошли, JS errors — 0. Политика rate limits не менялась: limiter одноразовой QA DB сбрасывается **между независимыми сценариями**, не внутри основной пары A/B. Physical Touch ID и live Jino не проверялись.
