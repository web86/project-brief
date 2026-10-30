# Milestone 8.5 — Centralized Administrators & SSO UX

Версия приложения **0.9.1**: Milestone 8.5 реализован поверх указанной базы Milestone 9.0 / 0.9.0, без понижения версии и потери platform administration. Ветка `codex/milestone-8-5-administrators-sso` создана от `3fd525f`; после fetch `projects-manager/main` является её предком (37 commits между main и базой). Remote refs и production не изменяются.

Владелец текущего workspace получает раздел `/admin/administrators`: карточки со статусом, количеством проектов, TOTP/Passkey и разрешением создавать проекты; поиск с debounce 300 ms выполняется локально. Управление включает локальное имя сотрудника, назначения и существующие `ProjectPermissions::DEFAULTS`. Email/пароль/средства защиты остаются под управлением владельца аккаунта. Имя хранится в membership и не меняет профиль в других workspace.

Приглашение не требует проекта. При выключенной отправке сохраняется черновик без создания User и без выдачи токена; отправка использует существующие DeveloperInvitations и AccountMail. Повтор email активного сотрудника возвращает существующий membership; повтор незавершённого приглашения предлагает переотправку/отзыв, без скрытой ротации токена. Отправка/повторная отправка сохраняют семидневный TTL и ротацию; прежние ссылки поддерживаются. Имя и проекты приглашения можно изменить до принятия. Неактивного сотрудника можно повторно пригласить в тот же User.

`/api/admin/administrators` — owner-only workspace routes. Любая мутация требует свежий security step-up. Прежние project routes для приглашений и изменения глобальных прав удалены. В команде проекта остались только owner-only список текущей команды/доступных активных администраторов, массовое назначение и удаление одного назначения. Повторное назначение сохраняет существующие права; новое получает safe defaults. Владелец показан отдельно, без действия удаления.

Деактивация сохраняет существующую семантику отзыва: inactive membership, выключенное создание проектов, удаление назначений и отзыв незавершённых приглашений **только текущего workspace**. CurrentWorkspace проверяет active membership на каждом запросе, поэтому существующая сессия больше не открывает этот workspace. Глобальный auth_session_version не меняется: это позволяет сохранить сессии других workspace. Существующий model hook инвалидирует security challenges. User, пароль, TOTP и Passkey не удаляются.

SSO использует существующие integration_sources. Вкладка показывает источники, Origin, состояние, действующий `clients:sso` ключ и число уникальных доступных проектов. Новый источник создаётся выключенным; CTA ключа открывает существующий выпуск с source/scope, секрет показывается однократно. Provenance-проекты доступны только для просмотра, ручные выбираются с поиском. Сохраняются source binding, Origin, TTL, single-use handoff, отзыв и Magic Link fallback. Локальная ссылка на документацию `/client-sso-guide.html` работает без публикации GitHub-ветки.

Одна additive migration добавляет nullable `workspace_members.display_name` и `developer_invitations.sent_at`. Старым приглашениям sent_at заполняется created_at. Аккаунты, membership, permissions, токены и их hashes не переписываются. Новых ролей, сервисов и таблиц нет; зависимости не изменены.

- Проверки: [TESTS.md](TESTS.md).
- Jino deployment/rollback: [PRODUCTION.md](PRODUCTION.md).
- Финальный архив и checksum: [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json) / [SHA256SUMS.txt](SHA256SUMS.txt). Канонический ZIP `release/project-brief-v0.9.1-ef31316.zip`: **39 288 950 bytes**, SHA-256 `481713fea36367ee6ef550dd5189ea51e28e461c3a6a7b4dc828ed9413ae9d9f`.
