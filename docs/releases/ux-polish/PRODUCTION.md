# Project Brief v0.9.11 — UX polish and client privacy

Пакет предназначен для обычного обновления `brief.web86.site` с установленного v0.9.10 / Milestone 10.0 Phase 2. Это не публичное demo и не следующий этап коммерческого enforcement.

## Изменения

Общий сайдбар платформы; компактные каталог и редактор тарифов; исправленная Workspace-подсказка; пропуск повторных необязательных рекомендаций безопасности; серверный allowlist клиентской истории и исключение внутренних/исторических агентских комментариев, файлов и уведомлений. Внутренняя история, права, Phase 1–2, API, Webhooks, SSO, Team Chat и human-reviewed publication сохраняются.

## Обновление существующего Phase 2

Новых migrations и обязательных `.env` параметров относительно `3306015` нет. Для этого обновления **не запускать PlansFoundationSeeder**, не переназначать PlanAuthority, не инициализировать тарифы заново, не выполнять fresh/reset/backfill. Команда `php artisan migrate --force` для данного обновления не требуется, если указанный Phase 2 действительно установлен. Наличие старых pending migrations сначала отдельно расследовать; не предполагать их применение по этой инструкции.

1. Проверить `release.json`, SHA-256 ZIP и `checksums.txt`. Сохранить текущие `public/`, код private runtime, `.env`, `APP_KEY`, uploads/storage и согласованную резервную копию БД. Записать текущий release/commit. Не помещать private каталог в web root.
2. Включить maintenance штатной командой `php artisan down`; на время замены кода остановить существующие внешние cron/processors согласно действующему runbook. Не менять их конфигурацию и секреты.
3. Заменить код на содержимое `public/` и `private/project-brief-app/` этого ZIP по существующей схеме Jino. **Сохранить существующие `.env`, APP_KEY и storage**; merge пустых runtime-каталогов не должен удалять файлы. Не копировать `.env.production.example` поверх `.env`. Не загружать локальное QA/demo окружение.
4. Из private runtime выполнить `php artisan optimize:clear`, затем `php artisan optimize`. Не запускать `bin/first-install` на существующей инсталляции и не выполнять seed/setup commands. `bin/first-install` оставлен только для отдельной первой установки/проверенного восстановления.
5. Выключить maintenance (`php artisan up`), вернуть штатные processors. Проверить обычный вход и MFA, профиль/соглашение, workspace/project navigation, `/platform`, планы, комментарии и вложения. Клиенту не должны приходить agent events/identity/private content; разработчик должен видеть внутреннюю историю. Проверить `/demo`, `/demo/developer`, `/demo/client`, `/api/demo/status` — 404. Выполнить существующие scoped live smoke-проверки интеграций без раскрытия секретов.

Все shell-команды выше предназначены оператору после его решения о deployment. В рамках подготовки архива они не выполнялись на Jino/production.

## Откат

Включить maintenance и приостановить processors. Вернуть предыдущие согласованные `public/` и private code/vendor как одну версию, сохранив текущие `.env`, APP_KEY и storage. Очистить/пересобрать кеш предыдущего runtime, проверить вход/основные страницы и вернуть обслуживание. Схему БД откатывать не требуется: этот релиз не меняет её. Не откатывать БД автоматически и не терять записи, сделанные после обновления. Существующие additive Phase 1–2 таблицы и pinned subscriptions/PlanAuthority сохраняются.

## Исключено из пакета

Demo provider, identity/read-only middleware, demo controllers/commands/services/config/routes, ProfessionalDemoSeeder/JSON/factories, Docker/launcher, локальные `.env`/APP_KEY/passwords, SQL dumps, тесты и screenshots. Production frontend не включает demo-entry chunk. Остаётся только штатный `PlansFoundationSeeder` как runtime-зависимость отдельно поддерживаемой первоначальной настройки; при update он не запускается.

Архив не зависит от будущего Static Demo и не требует его установки. Публичное demo размещается только отдельным статическим пакетом на отдельном домене.
