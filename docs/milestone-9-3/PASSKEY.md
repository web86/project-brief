# PR_CHALLENGE_INVALID / PK_CHALLENGE_INVALID

## Первопричина и граница доказательства

В обращении указан `PR_CHALLENGE_INVALID`. В исходниках и текущих ответах Project Brief используется **PK_CHALLENGE_INVALID**. Production logs/request IDs не предоставлены и Jino не исследовался: точный код исходного инцидента нельзя подтвердить по локальному тесту.

Подтверждённый дефект старой реализации v0.9.1 уже исправлен в базе v0.9.2: `AccountSecurity::challenge()` удалял все прежние записи с одним session_hash/purpose, а options endpoint сохранял единственный pointer `security.passkey_confirm`. Integrations и `/platform` используют общий `passkey_confirm`, поэтому подготовка в B удаляла challenge A. Передача assertion A могла дать INVALID для удалённого ID или MISMATCH при использовании заменённого pointer. Это подтверждено прежним before-test и текущей историей кода; см. `../milestone-9-2/PASSKEY.md` и сохранённый `artifacts/milestone-9-2/passkey-before.json`.

## Сохранённое исправление

Milestone 9.3 использует исправление v0.9.2 без повторного проектирования MFA. В существующей таблице `account_security_challenges` независимые WebAuthn attempts имеют UUID attemptId, две минуты TTL, purpose, session hash, User/auth_session_version и context hash workspace/security-policy. Options и POST связываются явным attemptId. Условное атомарное удаление потребляет одну попытку; signature, RP ID, origin, userHandle и обязательная UV проверяются штатной библиотекой. Смена authenticated session ID безопасно переносит только оставшиеся действующие attempts того же пользователя/версии. Переключение workspace/политики не допускает использования прежнего context.

Frontend coalesces подготовку и защищает от двойного запуска мутации. Native get вызывается в user gesture; ошибка/TTL допускает явный Retry с новым challenge, отмена сохраняет введённую форму, разрешённый fallback использует TOTP/recovery. Пароль не заменяет MFA владельца/platform admin.

Штатный step-up — общее пятиминутное подтверждение личности, а не grant бизнес-операции. Каждый endpoint отдельно проверяет owner/platform/tenant/project ACL. Между login/register/confirm challenges не переносятся. Legacy session pointer существует для старых клиентов, но независимость вкладок требует актуального frontend, передающего attemptId.

## Повторный тест в v0.9.3

`tests/parallel-passkey.browser.mjs`: одна cookie session, две Chromium page, локальный HTTPS, виртуальный authenticator с настоящими подписанными UV assertions. POST A удерживается, B готовит другой attempt, затем A создаёт API-key ровно один раз при double click; B завершает `/platform` после ротации session. Обе попытки — 200. Replay — 422/PK_CHALLENGE_INVALID; TTL — 422/PK_CHALLENGE_EXPIRED, новый Retry успешен; cancel не создаёт ключ, TOTP fallback завершает одну операцию. Все **4 группы прошли, JS errors 0**.

Laravel `ParallelPasskeyConfirmationTest` проверяет обе очередности завершения, подмену ID/signature, session/User/purpose/workspace, TTL и replay. Новый `WorkspaceAgreementTest` дополнительно подтверждает обе подписанные параллельные попытки у приглашённого администратора: Passkey не отменяет gate соглашения; доступ открывается только после server acceptance. Новый gate не блокирует необходимые MFA/security endpoints.

Evidence: `artifacts/milestone-9-3/parallel` и результаты Laravel/MariaDB. Raw challenges/credentials/secrets в отчёты не сохраняются. Physical Touch ID, Safari/WebKit hardware, live Jino и исходный production-инцидент не проверены.
