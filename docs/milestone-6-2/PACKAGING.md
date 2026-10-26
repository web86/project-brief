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

Окончательные измерения и результаты будут добавлены после пересборки; описание архива публикуется в `RELEASE-ARTIFACT.json`, контрольная сумма — в `SHA256SUMS.txt`.
