# Исправление комплектации production ZIP 6.2

Из production-дистрибутива исключены целиком `GUIDES` и `INTEGRATIONS`: исторические отчёты milestones, тестовые PDF/DOCX, скриншоты и результаты проверок. Исходные материалы остаются в Git. Пользовательские интерфейсы, бизнес-логика и механизмы PDF/DOCX не изменены.

Разрешённый состав: `public/`, `private/project-brief-app/`, `deployment/telegram-bridge.mjs`, `DEPLOY-JINO.md`, `PRODUCTION.md`, `release.json`, `checksums.txt`. Публичные PWA-иконки и служебные изображения PHPWord являются runtime-ресурсами. Все четыре шрифта, CJK-встройка DOCX и лицензии сохранены.

`build-release.mjs` копирует только нужные инструкции установки/обновления/отката. `forbiddenProductionPath` отклоняет произвольные корневые файлы, исторические каталоги и проверочные документы/результаты даже внутри runtime-каталогов. Регрессионный тест проверяет запрещённые пути и допустимые шрифты, лицензии, PWA-иконки и PHPWord-ресурсы.

Сборка проверяет CRC/SHA-256 содержимого ZIP, распаковывает его в новую временную директорию, повторно проверяет состав и все строки `checksums.txt`, затем выполняет существующую runtime-проверку на изолированной SQLite. Стенд не использует production БД, SMTP или внешние интеграции.

Дополнительная проверка браузерного экспорта из распакованного ZIP:

```bash
RELEASE_BROWSER_QA_DIRECTORY="$PWD/docs/milestone-6-2/production-packaging/qa" \
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs \
PHP_INI_SCAN_DIR=:$PWD/api/dev-php \
node tests/release-runtime.mjs /absolute/path/to/clean-unpacked-zip
```

Playwright используется только на локальном стенде. Проверка выполняет PDF RU/EN/TR/ZH-CN в Chromium/Firefox при ширине 1440/390 px и четыре DOCX в Chromium. Китайский текст добавляется исключительно во временную тестовую БД. Результаты сохраняются вне дистрибутива. Safari и физические телефоны не проверяются.

## Проверенный результат — 26 октября 2026

| Метрика                    |          До |          После |
| -------------------------- | ----------: | -------------: |
| Размер ZIP, байт           | 122 716 659 | **37 716 083** |
| Размер ZIP, МБ (10⁶ байт)  |      122,72 |      **37,72** |
| Размер ZIP, МиБ (2²⁰ байт) |      117,03 |      **35,97** |
| Файлов в ZIP               |       7 814 |      **7 590** |

Удалено из дистрибутива **85 000 576 байт (69,27%)**. ZIP: `project-brief-v0.6.2-41da2a3.zip`, source commit `41da2a3e0aada09d87d23de8c6041d9ffd58f547`. SHA-256: `19047be35580010bd465ea86bd4483a93f8532126fa2352f188aec1635c43d64`.

Сравнены 7 586 runtime-файлов с предыдущим ZIP: 7 585 побайтово идентичны; в `vendor/composer/installed.php` изменены только две ссылки на source commit. Runtime-файлы не удалены. Исходные материалы предыдущих milestones не удалены из Git; прежнее описание архива сохранено в [previous-release.json](production-packaging/previous-release.json).

Проверки:

- Frontend: **252/252**; Laravel: **682/682**, **8 057 assertions**; brand check, production build и Composer platform requirements — успешно.
- ZIP: CRC всех файлов и SHA-256 всех **7 589** строк манифеста подтверждены. Проверка состава разрешает только runtime и инструкции установки/отката.
- Чистая распаковка: first-install, повторный запуск с сохранением ключа, миграции, health/SPA/assets, сессии администратора/клиента, права, JSON/DOCX, maintenance/откат из maintenance и интеграционные runtime-проверки — успешно. Стенд использовал временную SQLite и локальный PHP, production не затронут.
- Из чистого ZIP скачаны **16 PDF**: Chromium/Firefox × desktop 1440/mobile viewport 390 × RU/EN/TR/ZH-CN. Горизонтального переполнения и JavaScript-ошибок нет. Дополнительно скачаны **4 DOCX** на всех языках в Chromium desktop.
- Все PDF: A4, локализованный заголовок, исходный китайский/турецкий/русский текст, встроенные реальные glyph subsets и ToUnicode. Китайская страница визуально проверена после Poppler-рендера; символы читаемы.
- Все DOCX: исправный ZIP/XML, локализованный заголовок, исходный китайский/турецкий текст, обе полные CJK-гарнитуры побайтово совпадают с исходными TTF после снятия ECMA-376 obfuscation; лицензия присутствует. Это структурная проверка, новый визуальный прогон Word/LibreOffice не выполнялся.
- Проверочные PDF, скриншоты и результаты находятся в [production-packaging/qa](production-packaging/qa). Новые DOCX проверены во временном стенде; в Git сохранены их размеры, SHA-256 и результаты проверки встройки, без повторного копирования четырёх больших документов. Ранее сохранённые DOCX остались на месте.

Полное описание архива — [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json), контрольная сумма — [SHA256SUMS.txt](SHA256SUMS.txt), детализация — [archive-results.json](production-packaging/archive-results.json), [browser-results.json](production-packaging/qa/browser-results.json), [document-results.json](production-packaging/qa/document-results.json). Целевая ветка публикации — `projects-manager/main` (`web86/projects-manager`); deployment не выполняется.
