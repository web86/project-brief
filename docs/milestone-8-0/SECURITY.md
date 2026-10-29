# Milestone 8.0 — security verification

Дата: 2026-10-29. Область: новая provisioning/login функциональность, её tenant/auth/idempotency границы и зависимости релиза. Все данные и SMTP/webhook endpoints в проверках синтетические; production не изменялся.

## Проверенные границы

- `pb_*` остаётся серверным bearer credential, хранится как hash, проходит текущие IP/workspace limits. Scope `projects:provision` — явное разрешение создавать контакт и проект. Обычный `projects:write` не даёт этого разрешения. Выключенный scope/switch не позволяет replay; отзыв/expiry проверяются повторно под write lock.
- Workspace выбирается из проверенного токена. Входные `workspaceId`, membership/role/active и неизвестные nested поля отклоняются. Настройки токенов и история доступны только owner текущего workspace с прежними session/CSRF/fresh-confirmation проверками.
- Email — ключ контакта только внутри workspace. Контакт не является аутентифицированным пользователем. Новое project membership не наследует сессию, токен или доступ из совпавшего email другого проекта/workspace. Existing metadata/access не переписываются.
- Уникальный внешний binding + нормализованный body hash + текущая идемпотентность защищают от дублей/изменения чужого проекта. Workspace lock и DB uniqueness покрыты реальными конкурентными запросами на SQLite и MariaDB. Удалённый проект оставляет tombstone. Старые idempotency hashes сохраняют алгоритм и совместимость.
- Все бизнес-записи и outbox создаются транзакционно; письмо отправляется после commit. При отказе SMTP проект остаётся, failed-link отзывается, статус доступен в повторном ответе. Неопределённое `sending` не приводит к автоматическому повторному письму. Existing Clients UI выполняет явный resend и обновляет статус.
- Постоянная ссылка не содержит credential. Client auth middleware продолжает проверять project/token/member на каждом защищённом запросе. Другой проект, revoked link и inactive membership не открываются. Новый public login выдаёт письмо только существующему active member, не меняет membership и не отзывает существующую сессию; применяет CSRF, per-IP и hashed mailbox/project limits и generic response.
- API/replay/history/webhook payload и application logs проверены на конкретные синтетические API/login secrets. Webhook содержит только разрешённые business IDs/URL/status; mail link остаётся в письме. Ошибки не логируют exception messages, SQL bindings или request bodies. Реальные HTTP-прогоны отдельно проверяют `sent` и `failed` события без `/access/` и email в webhook.

## Dependency audit и разрешённые исправления

В базовом lockfile 0.7.8 и первой сборке 8.0 `npm audit` сообщил **6 high package findings**, из них **5** оставались с `--omit=dev`. Это число затронутых пакетов с распространённой severity, а не шесть независимых подтверждённых атак на Project Brief.

| Цепочка                                                                 | До                                     | После   |
| ----------------------------------------------------------------------- | -------------------------------------- | ------- |
| `vue-i18n`, `@intlify/core-base/devtools-types/message-compiler/shared` | 11.4.13, пять runtime package findings | 11.4.14 |
| `vite` (build/dev tool)                                                 | 7.3.6, один package finding            | 7.3.7   |

Пользователь отдельно разрешил эти patch-обновления и повторную сборку. `package.json`/`package-lock.json` обновлены; новых зависимостей или методов аутентификации не добавлено. Composer dependencies не менялись.

Upstream подтверждает исправления Vue I18n в 11.4.14 для [mixed list/named escaping](https://github.com/intlify/vue-i18n/security/advisories/GHSA-8rc2-428q-7ww3) и [iframe srcdoc sanitization](https://github.com/intlify/vue-i18n/security/advisories/GHSA-83rm-6c8r-g728). Vite 7.3.7 входит в исправленные версии [custom middleware advisory](https://github.com/vitejs/vite/security/advisories/GHSA-9jrq-w75r-8gcw); остальные Vite advisory IDs сохранены в audit snapshot.

Локальный поиск не обнаружил вывода переводов через `v-html` или включения `escapeParameter`. Единственный `v-html` в `BrandLogo.vue` получает статически импортированный repo SVG, не перевод или пользовательский текст. Это ограниченная source-проверка достижимости, а не доказательство отсутствия любой эксплуатации. Vite dev server и Node dependencies не входят в production ZIP как исполняемый сервер; runtime i18n обновлён в скомпилированных assets.

После обновления повторные `npm audit --json` и `npm audit --omit=dev --json` вернули **0 vulnerabilities**. Сводка и upstream IDs: `dependency-audit.json`. Результат отражает registry audit на указанную дату и не заменяет проверки бизнес-логики выше.

## Ограничения проверки

Доказательства относятся к локальным PHP 8.5/SQLite/MariaDB, отдельной PHP 8.4.26/MariaDB регрессии, Chromium и локальному SMTP-приёмнику. Ни Jino, ни реальный inbox, ни внешний webhook consumer не проверялись. Hosting/proxy/NAT preflight, staging backup/restore, сетевые политики и фактическая доставка проверяются при согласованном deployment. Future client Passkey/MFA/SSO и workspace assurance policies не реализованы; текущая project-specific Magic Link модель сохранена.
