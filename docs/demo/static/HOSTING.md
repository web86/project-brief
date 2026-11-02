# Установка и независимый откат Static Demo

Архив предназначен только для отдельного document root домена `demo.brief.web86.site`. Production PHP ZIP и Static Demo ZIP не зависят друг от друга. В рамках разработки файлы на Jino не загружались.

1. Проверить `.zip.sha256` и `checksums.txt`, распаковать ZIP в новый пустой staging-каталог. В корне должны быть `index.html`, `.htaccess`, `manifest.json`, JS/CSS/images/snapshots; не должно быть PHP, `.env`, vendor, БД или Docker-конфигурации.
2. Подключить отдельный web root домена к этому каталогу. PHP, БД, cron, mail и секреты не нужны. Не копировать production `.env` или APP_KEY. Использовать HTTPS.
3. На Apache/Jino разрешить `.htaccess`, `mod_rewrite` и соответствующие AllowOverride. Правила дают SPA fallback для `/demo`, `/admin/...`, `/project/...`, `/w/...`, но `/api`, `/platform`, `/access`, `/client-sso` и отсутствующие JSON/assets возвращают 404. На другом static HTTP-хостинге настроить эквивалентное поведение.
4. `index.html`, `manifest.json` и release metadata должны перепроверяться при загрузке. Hash-named JS/CSS/snapshots/images кешируются как immutable. Не включать service worker. CSP ограничивает исходящие подключения текущим static origin; формы не имеют сетевого action.
5. Открыть `/demo`, обе роли, профиль/безопасность/соглашение, проекты и прямые ссылки после reload. Проверить desktop/mobile, язык/тему. В DevTools Network не должно быть `/api/...`, `brief.web86.site/api`, внешних AI/SSO/mail/Push запросов и методов кроме GET/HEAD. Файлы с `.test` адресами — фиктивные ссылки; открытие внешних ссылок в demo блокируется.

## Обновление

На машине разработчика восстановить/проверить существующий изолированный Professional Demo, выполнить `npm run demo:export`, затем проверки и `npm run demo:build:static` из чистого коммита. Данные на production не читать. Исходный hash намеренно закреплён: изменение dataset требует отдельного review по SCHEMA.md.

Сохранить предыдущий Static Demo ZIP и его SHA. Загрузить новую полную edition в staging. Предпочтительно переключить document root/symlink атомарно. При обновлении на месте сначала загрузить новые hash-named resources, затем manifest/index; старые hash-named resources оставить до окончания активных вкладок/кеша. Это позволяет старым страницам завершать lazy loading своей edition.

## Откат

Вернуть предыдущий **статический** web root целиком либо его manifest/index вместе со всеми соответствующими hash-named resources. БД, production приложение, APP_KEY и Laravel caches не затрагиваются. Проверить SHA и обе точки входа. Откат production v0.9.11 описан отдельно в `docs/releases/ux-polish/PRODUCTION.md`.

## Ограничения

Нужен HTTP(S) static hosting со SPA fallback; открытие `index.html` через `file://` не поддерживается. Данные фиксированы и не редактируются. Security/SSO/integrations показывают существующий UI с вымышленным состоянием. Экспорт показывает настройки без создания PDF/DOCX. Реальные Safari/физические устройства и настройки Jino не проверялись; Chromium mobile QA использует viewport эмуляцию. Все JSON являются публичными; разделение ролей демонстрирует интерфейс/проекции и не является настоящей авторизацией.

## Обновление до Dev2Done / Static Demo v2

Использовать новый `dev2done-static-demo-v2-<commit>.zip`. Имя домена и SPA routes остаются прежними. Загрузить новые hashed assets/snapshots, затем manifest/index либо атомарно переключить полный web root. Старые hashed resources оставить для уже открытых вкладок. PHP/БД/secrets и изменений production не требуется. Новый manifest использует Dev2Done/D2D; пользовательский Core experience отображается первым с задачами `1.x`. Проверить обе роли, профиль/безопасность/соглашение, новый знак/акцент и reload/deep links. Откат — полный предыдущий статический web root; предыдущий production ZIP независим.

В Dev2Done v2 immutable применяется только к файлам с content hash. Фиксированные `brand-logo-light.svg`/`brand-mark-dark.svg` и иконки должны перепроверяться. При переходе со старого хостинга с уже закешированными постоянными именами может потребоваться обновление browser cache; текущий UI использует новый inline mark, новые hashed snapshots/JS и новую revision URL иконок.
