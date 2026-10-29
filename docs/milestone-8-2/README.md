# Milestone 8.2 — Minimal Client SSO / Project Brief 0.8.3

База: завершённая Project Brief 0.8.2, commit `03932e0`. Ветка `codex/milestone-8-2-client-sso`. [English guide](README.en.md), [API/OpenAPI](openapi.json), [проверки и безопасность](TESTS.md), [Jino и rollback](PRODUCTION.md).

Клиент уже авторизован в кабинете доверенного сайта. Одна кнопка передаёт его в существующий разрешённый проект без повторного email или письма. SSO не создаёт проекты, клиентов, memberships или кабинеты. Для остальных случаев сохраняются приглашения и Magic Link.

## Настройка владельцем

1. Создайте постоянный источник в существующем разделе «Интеграции», выдавая ему отдельный токен с `clients:sso`. Используйте отдельный SSO-only токен на внешнем сайте. Прежние токены не получают этот scope автоматически.
2. В блоке «Доверенный вход клиента (SSO)» выберите источник, укажите точный HTTPS origin кабинета (scheme + host + порт, без пути), явно отметьте доступные проекты и включите SSO. До этого SSO выключен. Совпадение email само по себе не разрешает остальные проекты workspace.
3. Сохраните токен, helper и серверное соответствие неизменяемого user ID → project UUID вне document root. UUID берётся из существующего provisioning/одобрения заявки, а не из браузера. При ротации токена выбирайте тот же постоянный источник.
4. Внешний сервер проверяет свою авторизованную сессию и CSRF, читает из БД стабильный ID и подтверждённый email. `emailVerified: true` — доверенное утверждение сервера, а не поле публичной формы. Смена email должна отменять прежнюю отметку проверки на внешнем сайте.

Первая успешная выдача закрепляет внешний ID и нормализованный email внутри workspace/источника. Другой ID с тем же email и изменённый email прежнего ID дают409; автоматической перепривязки нет. Для исправления идентичности используйте обычный Magic Link и отдельно разберите первопричину; удаление binding вручную не является пользовательским workflow. Сотрудники Project Brief не входят через клиентский SSO.

## Серверный запрос

```http
POST /api/v1/client-sso/handoffs
Authorization: Bearer <server-only pb_* token>
Content-Type: application/json

{"projectId":"<saved UUID>","externalUserId":"site-user:42","email":"verified@example.com","emailVerified":true}
```

201: `{"data":{"code":"<opaque 64 hex>","postUrl":"https://brief.web86.site/client-sso/enter","expiresAt":"<ISO timestamp>"}}`.

Только JSON, максимум2048байт; externalUserId до160символов, email до255. `emailVerified` должен быть JSON boolean true, не строка/число. Ответ — секретный и `no-store`; не журналировать request/response body. Ключ идемпотентности не нужен: повтор выдаёт отдельный код с TTL60секунд, не меняя бизнес-записи. Нельзя использовать сохраняющий ответы idempotency proxy для этого endpoint.

401 — недействующий токен;403 — scope/source/grant/участник/подтверждение доступа не разрешают вход;404 — проект не найден в workspace;409 — конфликт binding;413/415/422 — размер/тип/поля;429 — существующий лимит API. Browser POST отдельно ограничен60/мин/IP. Для недоступного входа показывайте нейтральное сообщение и обычную ссылку проекта, не выдавайте доступ автоматически.

## POST-переход и cookies

Используйте [ProjectBriefClientSso.php](examples/ProjectBriefClientSso.php). Он проверяет TLS, не следует redirect API, отдаёт минимальную nonce-CSP HTML-форму без аналитики, кэша и URL-кода. `Referrer-Policy: origin` отправляет только origin внешнего сайта и сохраняет обязательный Origin POST; `no-referrer` на внешней форме превращает Origin в `null` в Chromium и вход безопасно отклоняется. Не добавляйте код в URL, referrer, local/sessionStorage, логи или аналитику. Не включайте логирование тела в reverse proxy/APM.

Первый POST `/client-sso/enter` проверяет код/Origin, ставит только зашифрованную HttpOnly host-only cookie на `/client-sso` и возвращает минимальный автоматический переход внутри Project Brief. Он не запускает сессию и не перезаписывает отсутствующую при cross-site POST SameSite cookie. Следующий same-origin `/client-sso/complete` получает существующую сессию, атомарно гасит код, регенерирует session ID/CSRF, очищает одноразовую cookie и возвращает303 на канонический URL. Промежуточные страницы не добавляют видимого шага при включённом JS. Без JS есть кнопки «Продолжить» на обеих страницах; сам Vue-интерфейс требует JavaScript.

Другая действующая клиентская или служебная/MFA-сессия сохраняется, SSO отклоняется. Завершите её обычным выходом или откройте отдельный приватный контекст; скрытой смены личности нет. Разрешено переключение проектов той же клиентской идентичности в том же workspace с явными source grants. Произвольный redirect URL не принимается.

Изменение настройки источника отзывает его коды и SSO-сессии; повторное включение их не оживляет. Отзыв/истечение токена, снятие scope, отключение клиента/проекта и удаление grant блокируют последующее использование. Обычные Magic Links не зависят от SSO. Данные binding сохраняются при смене токена.

## Примеры и локальный сценарий

- [Защищённый config](examples/config.example.php), [обычный PHP](examples/plain-php.php).
- [MODX 2/3 snippet](examples/modx-snippet.php): один совместимый код, некэшируемый вызов `[[!ProjectBriefOpen]]` на существующей странице кабинета до вывода. Используются текущий web-context login и защищённые profile extended поля `pb_verified_email`/`pb_verified_at`, заполняемые только существующей проверкой почты. Не разрешайте их редактирование публичными profile handlers.
- [WordPress server handler](examples/wordpress.php): существующий authenticated `admin_post`, nonce и защищённое доказательство email из user meta; не регистрирует nopriv endpoint. Форма на странице кабинета отправляет `action=project_brief_open` и `wp_nonce_field('project_brief_open')` в `admin-post.php`.

Примеры адаптируются к существующему кабинету и его доказательству email; MODX/WordPress login сам по себе не подтверждает владение почтой. На web86.site готового кабинета сейчас нет. Регистрация не создавалась. Воспроизводимый `tests/client-sso.browser.mjs` запускает два локальных HTTPS-сайта, mock уже авторизованного пользователя с фиксированными серверными данными, настоящий PHP/cURL helper, временную БД и SMTP-приёмник. `tests/client-sso-external.php` — строго QA-only, не выкладывать его на сервер. Команды и границы проверки — в TESTS.md.
