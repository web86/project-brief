# Обновление production — 0.4.9.1

Эта инструкция подготовлена для ручной установки. Deployment, push и GitHub Release в рамках задачи не выполнялись. Поставка содержит production vendor, frontend assets и прежний backend Milestone 4.9. Изменений backend, разрешений, уведомлений, Telegram/Deno, комментариев и файлов в 4.9.1 нет.

## Сборка и проверка ZIP

Из чистого коммита ветки `codex/milestone-4-9-1-branding-loading`:

```sh
./bin/build-release
```

Сборщик выполняет frontend tests, проверку SVG/PNG-мастера, API production build, Laravel regression, установку production vendor в staging, проверку PHP platform requirements, сканирование секретов/лишних файлов, SHA-256 файлов и runtime-проверки на отдельной копии. ZIP и распакованная поставка находятся в `release/`. Сверить `release.json` (version `0.4.9.1`, commit), SHA-256 ZIP из отчёта и `checksums.txt` внутри архива.

## Установка поверх 4.9

1. Сохранить резервные копии public index/assets, private application/vendor, БД, `.env`, `APP_KEY`, `storage` и release metadata. Проверить действующий PHP web-SAPI по [инструкции 4.9](../milestone-4-9/PRODUCTION.md): CLI и web-SAPI могут отличаться.
2. В private application выполнить `php artisan down` и `php artisan optimize:clear`.
3. Наложить private application и vendor из ZIP, сохранив действующие `.env`, `APP_KEY` и всё `storage`. Наложить public-файлы в document root, включая `index.html`, manifest, favicon, SVG, все пять PNG и новые hashed assets. Сохранить прежние hashed assets для открытых вкладок и отката. Не помещать private-каталог или ZIP в document root.
4. Выполнить команды в private application:

```sh
php bin/check-server
php artisan migrate --force --no-interaction
php artisan optimize
php artisan up
```

В 4.9.1 новых миграций нет; поверх установленной 4.9 migrate будет no-op. При обновлении с более ранней версии сначала учесть миграцию и требования Milestone 4.9 из связанной инструкции. Не выполнять `migrate:fresh`, `db:wipe`, seed, `first-install` или `key:generate` на существующей установке.

## Проверка после обновления

- Hard reload `/admin`, клиентского проекта, `/app`, `/admin/login`, приглашения. Light/dark/system, RU/EN, медленная сеть и blocked JS. Нет зелёной вспышки, через 25 секунд доступен retry, повторная попытка сохраняет URL. Для уже открытой вкладки потребуется reload, чтобы получить новый HTML.
- Внутренние переходы: проекты, идея, обсуждения, настройки, аккаунт; остаётся предыдущая страница и один небольшой индикатор. Мобильный drawer не меняет размеры и ссылки.
- Проверить HTTP 200 для manifest, favicon, Apple icon и четырёх PNG по URL с `?v=4.9.1`. HTML/manifest должны обновляться или revalidate; очистить их серверный/CDN-кэш при наличии. Не удалять пользовательские данные браузера или service worker ради замены иконок.
- Manifest имеет нейтральные `background_color`/`theme_color`; системная PWA-заставка может оставаться светлой при тёмной теме приложения. `start_url=/app`, `scope=/`, `id=/`, standalone сохранены.
- На реальных Android/iOS открыть уже установленную PWA после обновления и проверить запуск, иконку, маску и переходы. Обновление установленной иконки/Apple Home Screen зависит от ОС и браузера и может происходить с задержкой. Если Home Screen сохранил прежний Apple icon после обновления сайта, повторное добавление ярлыка выполняется вручную по решению пользователя.
- Service worker остался push-only и не кэширует приложение через fetch; новая поставка не меняет существующие offline-возможности или подписки. Проверить прежний сценарий работы без сети и восстановление соединения. Полноценное открытие приложения без сети с нуля не являлось возможностью текущей версии.

## Откат

Maintenance → вернуть public HTML, manifest, favicon, SVG и PNG предыдущей поставки вместе с соответствующими assets → очистить/revalidate серверный кэш → проверить запуск и темы → up. Backend 4.9, схему БД и storage можно сохранить. Для полного отката private application/vendor использовать сохранённый backup без удаления новых данных. Миграции 4.9 откатывать не требуется; ограничения их отката описаны в инструкции 4.9.
