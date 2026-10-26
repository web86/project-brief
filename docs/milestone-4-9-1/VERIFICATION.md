# Проверки Milestone 4.9.1

Дата завершения: 26 октября 2026, Europe/Istanbul. Ветка: `codex/milestone-4-9-1-branding-loading`, база `projects-manager/main` / `e6899ba`. База получена через `git fetch projects-manager main`; Milestone 4.9 уже включён в main.

## Выполненные проверки

| Проверка                                   | Результат                                                       |
| ------------------------------------------ | --------------------------------------------------------------- |
| Frontend `npm test`                        | PASS, 234 теста                                                 |
| Laravel `php artisan test --compact`       | PASS, 605 тестов, 6226 assertions                               |
| `vendor/bin/pint --format agent`           | PASS, backend-файлы не изменились                               |
| `npm run build`                            | PASS                                                            |
| `npm run brand:check`                      | PASS, SVG/PNG/inline splash соответствуют мастеру               |
| `npm run format:check`                     | PASS                                                            |
| `git diff --check`                         | PASS                                                            |
| `npm run test:branding`                    | PASS, 8 групп сценариев; 12 сочетаний viewport × theme × locale |
| `npm run test:appearance`                  | PASS, 11 групп сценариев                                        |
| `node tests/navigation-layout.browser.mjs` | PASS, 32 конфигурации                                           |

Детальные browser results: [branding](browser-results.json), [appearance](appearance-results.json), [navigation](navigation-results.json). Production ZIP и повторные проверки сборщика фиксируются отдельно в `RELEASE-ARTIFACT.json` после сборки из чистого коммита. Сборщик проверяет vendor, PHP platform, отсутствие секретов и лишних файлов, checksums, migrations, повторную установку, SPA reload, assets и packaged runtime на disposable-копии.

## Что проверено

- До запуска Vue удержан запрос entry JS, `#app` пустой. На 1440/390/320 px × light/dark × RU/EN видны правильный фон, SVG, три точки и локализованная подпись. Сняты 18 кадров «до» и 53 «после», включая drawer, ошибку, favicon 16/32 и маску PWA. Геометрия и начальный кадр осмотрены визуально.
- Сохранённые light/dark, system и отсутствие настройки; смена системной темы до и после монтирования; совпадение meta theme-color с фоном. Unit-тест проверяет передачу слушателя темы от head bootstrap к Vue и отсутствие оставшегося слушателя, который мог бы перезаписать ручной выбор.
- Hard reload `/admin`, клиентского проекта, `/app`, входа и приглашения. Блокировка загрузки JS вызывает RU/EN fallback в dark; retry загружает текущий URL. Таймаут 25 секунд проверен виртуальными часами; при позднем успешном старте заставка всё равно закрывается. API 503 на `/app` оставляет доступную ошибку приложения вместо бесконечной заставки.
- Normal/reduced motion; анимация меняет только opacity, исчезновение не задерживает mount. Внутренний переход с задержанным API сохраняет предыдущий h1, показывает ровно один overlay и не создаёт bootstrap splash.
- Проекты, идеи, обсуждения, настройки, экспорт, аккаунт/персональные настройки, клиентский интерфейс, drawer, темы и язык. Appearance suite дополнительно проверяет keyboard, контраст, отсутствие overflow, 320/360/390 px и ошибки localStorage. Изменения API/маршрутов/авторизации не вводились.
- Manifest сохраняет `id=/`, `start_url=/app`, `scope=/`, `display=standalone`; нейтральный фон, versioned URLs. Все публичные бренд-ресурсы отвечают 200. PNG имеют реальные размеры 192/512/180, непрозрачный RGB-фон и отдельную растеризацию каждого размера. Unit-тест проверяет каждый foreground-пиксель maskable на попадание в safe circle радиусом 40%; screenshots показывают круглую маску. Favicon контрастен на светлой/тёмной подложке при 16/32 px.
- Аудит `index.html`, `main.js`, theme service, CSS, LoadingOverlay, всех перечисленных layout/view и public assets. Старый зелёный в production frontend остался только в семантических success/status/toast стилях. LoadingOverlay уже использует `--action`; задержка 80 ms, fade и reduced motion внутренней навигации сохранены. Push-only service worker побайтово не менялся.

## Изменения тестовой инфраструктуры

Старый appearance suite обновлён под действующее API: Telegram status fixture, права проекта и селектор event checkbox. Исправлен расчёт контраста для сериализации CSS `color(srgb ...)`, чтобы значения 0–1 не трактовались как RGB 0–255. В navigation fixture добавлен `notificationPreferenceId`, который действующий personal bootstrap требует с Milestone 4.9. Это только тестовые данные; логика приложения не менялась.

## Ограничения и исторический тест

`npm run test:mobile-header` запускался, но его историческая fixture не поддерживает `/api/client/telegram`. При временной диагностической актуализации fixture обнаружилось также ожидание старого `.mobile-push-footer`, которого больше нет в текущей композиции уведомлений. Этот suite оставлен без изменений: его сценарии Push относятся к прежнему layout. Актуальные мобильные бренд, темы, языки, размеры и drawer проверены проходящими branding/appearance/navigation suites выше. Это известная несовместимость старого теста, а не успешно пройденная проверка.

Проверки выполнены в Chromium с мобильными viewport, без физического Android/iOS и без проверки native install/update UI, Home Screen/закладок на устройстве. Установленная PWA может обновлять иконку с задержкой ОС/браузера; реальное обновление проверяется при ручной приёмке по [PRODUCTION.md](PRODUCTION.md). В существующем worker нет fetch-кэша: полноценный offline cold start не добавлялся и не заявляется. Production-сервер, CDN-кэш, web-SAPI, deployment и реальные внешние уведомления не менялись. Браузерные API-данные синтетические; Laravel и packaged runtime используют disposable SQLite.

## Повторение

```sh
npm test
npm run brand:check
npm run build
npm run format:check
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs npm run test:branding
APPEARANCE_OUTPUT_DIR=../artifacts/milestone-4-9-1/appearance/ PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs npm run test:appearance
M471_OUTPUT_DIR="$PWD/artifacts/milestone-4-9-1/navigation" PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/navigation-layout.browser.mjs
cd api
php artisan test --compact
vendor/bin/pint --format agent
cd ..
./bin/build-release
```

Для E2E требуется локальный Chromium/Playwright и разрешённый запуск localhost-серверов. Снимки старого оформления уже сохранены из базы до внесения изменений; `BRANDING_BASELINE=1` не следует запускать на новой версии для перезаписи этих снимков.
