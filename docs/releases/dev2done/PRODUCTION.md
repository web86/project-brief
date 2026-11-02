# Dev2Done v0.9.12 — rebranding before Phase 3

Стандартное обновление установленного `brief.web86.site` с v0.9.11 или Milestone 10.0 Phase 2. Phase 3 в этом релизе не реализуется. API, privacy filtering, тарифная архитектура, PlanAuthority, pinned subscriptions, SSO и права не меняются.

## Изменения

Project Brief получает пользовательское имя Dev2Done; компактная подпись — D2D. Новый знак — предоставленный пользователем SVG с неизменёнными контурами/градиентами. Обновлены header/sidebar/login/splash, browser titles, PWA name/icons, branding preview, интерфейс платформы/тарифов, локализации, email/Telegram тексты и подписи PDF/DOCX. Основной акцент — violet/indigo `#5b4bd6`, согласованные dark/light tokens. Пользовательские workspace-бренды и содержимое существующих документов не переписываются.

## Обновление

Новых migrations и обязательных `.env` параметров нет. Изменены только примеры значений существующих `APP_NAME` и `MAIL_FROM_NAME` для новых установок. Старые стандартные значения `ProjectBrief` / `Project Brief` отображаются как Dev2Done через совместимый presentation mapping. Произвольные собственные имена сохраняются. **Для обновления не нужно менять `.env`**; сохраните также `SESSION_COOKIE` и APP_KEY. RP ID/домен Passkeys не меняются, повторная регистрация факторов не требуется.

1. Проверить SHA-256 ZIP, `release.json` и `checksums.txt`. Сохранить предыдущие public/private code/vendor, `.env`, APP_KEY, uploads/storage и согласованную резервную копию БД.
2. Включить maintenance (`php artisan down`), приостановить существующие cron/processors по штатному runbook.
3. Заменить `public/` и `private/project-brief-app/` как одну версию. Имя продукта новое, **внутренний private runtime path остаётся `project-brief-app`**. Private каталог не размещать в web root. Сохранить текущие `.env`, APP_KEY, SESSION_COOKIE и storage, не заменять их примером из ZIP.
4. Из private runtime выполнить `php artisan optimize:clear`, затем `php artisan optimize`. Не выполнять `first-install`, fresh/reset/backfill, PlansFoundationSeeder, инициализацию тарифов или переназначение PlanAuthority. Для данного ребрендинга `migrate` не требуется, если Phase 2 уже установлен.
5. Выполнить `php artisan up`, вернуть штатные processors. Проверить вход/MFA/Passkeys, профиль/безопасность/соглашение, platform/plans, проект/клиент, email/export branding и light/dark/mobile. `/demo`, `/demo/developer`, `/demo/client`, `/api/demo/status` должны вернуть 404. Проверить favicon/PWA после обновления browser cache.

Новые install-примеры используют Dev2Done. Не меняйте APP_NAME на существующем сервере ради названия без учёта SESSION_COOKIE: отображение уже исправлено без изменения технического cookie identity.

## Откат

Включить maintenance, приостановить processors. Вернуть предыдущие public/private code/vendor вместе, сохранив текущие `.env`, APP_KEY, SESSION_COOKIE и storage. Очистить/пересобрать cache предыдущей версии, проверить вход/страницы и возобновить обслуживание. Схема и записи БД не меняются, откат БД/тарифов не нужен. Не терять новые записи, сделанные после обновления.

## Состав и изоляция

Штатный production runtime с locked no-dev vendor и готовыми frontend assets. Нет demo dataset, snapshots, demo services/controllers/commands, локальных credentials/.env/APP_KEY, Docker или анонимных demo entry points. Новый Static Demo ZIP устанавливается отдельно и не нужен production.

Это инструкция оператору. В рамках подготовки deployment, production DB changes и push не выполнялись.
