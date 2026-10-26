# Milestone 4.9.1 — Branding & Loading Experience

Ветка: `codex/milestone-4-9-1-branding-loading`. База: актуальный `projects-manager/main`, `e6899ba`, уже включающий Milestone 4.9. Push, deployment и GitHub Release не выполнялись.

Project Brief получил геометрический знак `p.` с отдельной точкой, мягкими углами и тёмно-синим акцентом. Текст `projectbrief` сохраняет системный шрифт и разную насыщенность двух частей. Светлая и тёмная темы, размеры навигации и все ссылки сохранены. Зелёный остаётся цветом успешных действий и статусов.

`BrandLogo.vue` — общий презентационный компонент для общей шапки, административного, проектного и клиентского сайдбаров, навигатора идей и мобильной шапки. Параметры: `compact`, `size` (число в px или CSS-размер), `theme` (`auto`, `light`, `dark`). Компонент не содержит маршрутизации. Страницы входа, приглашения и `/app` получают бренд из общей шапки; второй знак внутри `/app` убран.

Первоначальная заставка остаётся в `index.html` и работает до загрузки Vue/CSS. Фон сразу соответствует теме: `#f7f8fa` или `#15191f`. Под логотипом три CSS-точки и небольшая локализованная подпись. Нет минимальной искусственной задержки, изменения layout или увеличения логотипа. Сохранены `complete()`, `fail()`, порядок `router.isReady()` → mount → nextTick, fade 180 ms и восстановление через 25 секунд. При reduced motion точки статичны, исчезновение мгновенное. Ошибка запуска показывает контрастное RU/EN сообщение и кнопку перезагрузки текущего URL.

Head bootstrap следует изменениям системной темы ещё до Vue; затем передаёт обработку сервису темы без второго оставшегося слушателя. Browser theme-color совпадает с фоном с первого кадра. Внутренние переходы сохраняют прежнюю страницу, полупрозрачный overlay, акцентный spinner и существующую задержку появления 80 ms; начальная заставка повторно не создаётся.

## Исходник и генерация

Единственный редактируемый источник геометрии: [brand-mark.svg](../../src/assets/brand-mark.svg). Знак состоит из path с отверстием и circle, не зависит от шрифтов. Vue импортирует этот SVG как локальную строку; `v-html` получает только этот статический файл. Скрипт синхронизирует inline SVG заставки, отдельные SVG и пять PNG.

```sh
npm run brand:generate
npx prettier --write index.html
npm run brand:check
```

Требуются Python 3 (только стандартная библиотека) и существующие инструменты проекта. Дополнительные графические пакеты и сетевые ресурсы не используются. Скрипт читает SVG-мастер, разбивает cubic paths на отрезки и растеризует каждый размер отдельно с 4× supersampling. PNG имеют непрозрачный фон `#243b62`. Обычная и Apple-иконки увеличивают знак в 1.25 раза; maskable использует исходный масштаб внутри safe circle радиусом 40%. Проверка `brand:check` сверяет все SVG, PNG и inline-геометрию и включена в production-сборщик.

Файлы в `public/`: `brand-logo-light.svg`, `brand-logo-dark.svg`, `brand-mark-light.svg`, `brand-mark-dark.svg`, `app-icon.svg`, `favicon.svg`, `icon-{192,512}.png`, `icon-maskable-{192,512}.png`, `apple-touch-icon.png` (180×180). Отдельные horizontal SVG используют системный sans-serif wordmark; геометрия самого знака полностью независима от шрифтов.

Manifest сохраняет `id`, `start_url`, `scope`, `display`. Статический PWA splash нейтральный светлый; внутри страницы применяется пользовательская тема. Favicon, Apple icon и manifest icon URLs получили версию `v=4.9.1`, чтобы браузер запросил новые ресурсы. Push-only service worker не менялся, обработчик fetch или новый offline-кэш не добавлялись.

## Скриншоты

До и после сняты на одинаковых данных, размерах и темах. Начальный кадр получен с удержанным запросом `/src/main.js`, при пустом `#app`.

| Экран                | До                                                | После                                               |
| -------------------- | ------------------------------------------------- | --------------------------------------------------- |
| Splash desktop light | [до](screenshots/before-splash-1440-light-ru.png) | [после](screenshots/after-splash-1440-light-ru.png) |
| Splash desktop dark  | [до](screenshots/before-splash-1440-dark-ru.png)  | [после](screenshots/after-splash-1440-dark-ru.png)  |
| Splash 390 light     | [до](screenshots/before-splash-390-light-ru.png)  | [после](screenshots/after-splash-390-light-ru.png)  |
| Splash 320 dark EN   | [до](screenshots/before-splash-320-dark-en.png)   | [после](screenshots/after-splash-320-dark-en.png)   |
| Admin desktop light  | [до](screenshots/before-admin-1440-light-ru.png)  | [после](screenshots/after-admin-1440-light-ru.png)  |
| Client 390 dark      | [до](screenshots/before-client-390-dark-ru.png)   | [после](screenshots/after-client-390-dark-ru.png)   |
| Client 320 EN        | [до](screenshots/before-client-320-dark-en.png)   | [после](screenshots/after-client-320-dark-en.png)   |

Дополнительно: [все бренд-ассеты, favicon 16/32 и круглая маска](screenshots/after-brand-assets.png), [drawer 320 dark EN](screenshots/after-drawer-320-dark-en.png), [ошибка RU](screenshots/after-failure-dark-ru.png), [ошибка EN](screenshots/after-failure-dark-en.png), [внутренний переход](screenshots/after-internal-navigation-light.png). Остальные сочетания 1440/390/320 × light/dark доступны в [screenshots](screenshots).

Проверки и ограничения: [VERIFICATION.md](VERIFICATION.md). Обновление production и откат: [PRODUCTION.md](PRODUCTION.md).
