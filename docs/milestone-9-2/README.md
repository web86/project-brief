# Milestone 9.2 — Administrators & SSO UX + Passkey Reliability

База: production ZIP **v0.9.1** и ветка `codex/milestone-8-5-administrators-sso` (`8a9c495`). Отдельная ветка: `codex/milestone-9-2-admin-sso-passkey`. Релиз **v0.9.2**, без push, merge, deployment или изменений production DB.

Страница администраторов сохраняет сетку и поиск. «Добавить» и «Редактировать» открывают один AdministratorDialog на существующем BaseDialog: имя/email, статус, создание проектов, сворачиваемые назначения/индивидуальные права, приглашения и информационное состояние MFA. Email общей учётной записи остаётся под контролем её владельца. Ошибка сохраняет форму и переводит фокус к сообщению в прокручиваемом теле; successful Save закрывает окно. Поддерживаются крестик, Escape, Cancel и backdrop, native focus trap и прокрутка тела с видимыми действиями.

Статус inactive блокирует workspace, сохраняя назначения для осознанной последующей активации владельцем. «Удалить» с подтверждением физически удаляет WorkspaceMember и его ProjectMember текущего workspace, отзывает pending invitations и использует действующий security audit. Нет корзины или soft-delete membership. User, его MFA/Passkey, другие workspace, проекты/комментарии и связанные бизнес-данные сохраняются. Owner membership защищён сервером.

Приглашение может быть отправлено по email, сохранено без отправки или выдано как одноразово показываемая ссылка. Действует прежний 64-hex token hash и TTL 7 дней; выдача новой ссылки/переотправка ротирует токен и инвалидирует старую. Повторное создание по email не создаёт дубликат. Новые invitation fields сохраняют выбранное право создания проектов и индивидуальные ProjectPermissions до принятия. Nullable defaults сохраняют grants старых приглашений. **Ручная ссылка не подтверждает mailbox:** email_verified_at не выставляется без email delivery; существующий верифицированный аккаунт не теряет verification. Ссылка хранится только в памяти открытого диалога, секрет отсутствует в GET/list/localStorage/logs.

SSO отображает адаптивную сетку 2/1 карточки, короткие статусы и отдельные действия Configure/Delete. Один SsoSourceDialog используется для добавления/редактирования. API-key и проекты сворачиваются; создание `clients:sso` ключа использует существующий endpoint и одноразовый secret dialog, сохраняя несохранённые поля источника. Перепривязка ключа к другому source не добавляется: existing API делает source binding immutable. Автоматические provenance-проекты — read-only, ручные выбираются явно. Имя можно менять без отзыва клиентских сессий; Origin/SSO/grants используют прежнюю модель отзыва.

Удаление SSO-источника необратимо через API/UI: выключение SSO, отзыв всех связанных ключей/handoffs, удаление manual grants и исключение source из доступных. Исторический `integration_sources.id` сохраняется с archived_at: существующие restrict FK, project requests и immutable provenance нельзя разрушать ради удаления карточки. Новые ключи и изменение retired source запрещены. Это не корзина и не альтернативная модель SSO.

Одна additive migration добавляет четыре nullable поля: `integration_sources.archived_at`, `account_security_challenges.context_hash`, `developer_invitations.can_create_projects`, `developer_invitations.project_permissions`. Индекс session_hash/purpose ограничивает выборку при ротации и работе с proof. Новых таблиц, ролей, сервисов или UI-зависимостей нет. Существующие строки и transport/SSO contracts сохраняются.

- [Диагностика и исправление Passkey](PASSKEY.md).
- [Проверки и границы](TESTS.md).
- [Обновление/rollback Jino](PRODUCTION.md).
- [Production ZIP](../../release/project-brief-v0.9.2-8bf88e9.zip) — **39295991 bytes**, source commit `8bf88e9`.
- [SHA-256](SHA256SUMS.txt) и [результаты релиза](RELEASE-ARTIFACT.json). Предыдущий v0.9.1 и первый локальный candidate ZIP сохранены; final ZIP — `project-brief-v0.9.2-8bf88e9.zip`.
