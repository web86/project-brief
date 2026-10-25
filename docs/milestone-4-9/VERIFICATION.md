# Проверки Milestone 4.9

Дата: 25 октября 2026, Europe/Istanbul. Ветка: `codex/milestone-4-9-comment-attachments`, база `projects-manager/main` / `93a1d04`.

## Результаты

| Проверка                                                   | Результат                         |
| ---------------------------------------------------------- | --------------------------------- |
| Laravel, полный suite                                      | 605 тестов, 6226 assertions, PASS |
| Frontend, `npm test`                                       | 233 теста, PASS                   |
| Новый backend suite `CommentAttachmentTest`                | 12 тестов, 113 assertions, PASS   |
| Browser E2E, Chromium + настоящий Laravel + локальный SMTP | PASS, идеи и общая переписка      |
| Browser: RU/EN × light/dark/system × 320/360/390/1440 px   | 24 конфигурации, PASS             |
| `npm run build`                                            | PASS                              |
| `vendor/bin/pint --dirty --format agent`                   | PASS, стиль исправлен             |
| `npx prettier --check .`                                   | PASS                              |
| `git diff --check`                                         | PASS                              |

Browser evidence: [browser-results.json](browser-results.json). В двух основных сценариях создано ровно 6 комментариев, 6 вложений и 6 доставленных писем, включая повторы после потерянного ответа. Письма принимает локальный SMTP sink на loopback: production SMTP и реальные адресаты не используются. Отправка через Push/Telegram проверяется backend-тестом с транспортными doubles: пакет из двух файлов даёт по одному уведомлению на Email/Push/Telegram, повтор не доставляет повторно. Остальные персональные preferences/проектные правила покрыты существующими тестами централизованной доставки.

Новый backend suite проверяет оригинал и отдельную миниатюру, файл без текста, несколько одинаковых имён, общий комментарий без task_id, запреты MIME/форматов/пикселей/размера/числа/суммы, rollback при ошибке БД или диска, авторскую область idempotency, изменённый payload с прежним ключом, UUID другого проекта, отзыв доступа клиента и разработчика, корзину/восстановление, независимый лимит файлов идеи, уведомления без приватных URL/бинарных вложений. Тест legacy migration проверяет сохранность каждой прежней строки и null в новых полях.

Browser проходит file picker, настоящий Cmd+V из системного clipboard, drag/drop, удаление до отправки, ошибку HTTP с сохранением формы, потерю ответа после backend commit с повтором, ссылки из писем при уже открытой идее, увеличение/Escape, desktop/mobile viewer, загрузку и побайтовое сравнение оригинала, документ в ответе разработчика. Скриншоты ниже сделаны в настоящем приложении с disposable test database.

## Скриншоты

- Идея: [форма desktop](task-desktop-form.png), [форма mobile](task-mobile-form.png), [комментарий с изображением](task-desktop-comment.png), [ответ с документом mobile](task-mobile-comments.png), [просмотрщик desktop](task-desktop-viewer.png), [просмотрщик mobile](task-mobile-viewer.png).
- Общая переписка: [форма desktop](general-desktop-form.png), [форма mobile](general-mobile-form.png), [комментарий с изображением](general-desktop-comment.png), [ответ с документом mobile](general-mobile-comments.png), [просмотрщик desktop](general-desktop-viewer.png), [просмотрщик mobile](general-mobile-viewer.png).
- [320 px, English, dark](general-320-en-dark.png).

## Воспроизведение

```sh
npm test
npm run build
npx prettier --check .
cd api
php artisan test --compact
vendor/bin/pint --dirty --format agent
cd ..
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/comment-attachments.browser.mjs
./bin/build-release
```

Browser test использует только disposable SQLite и loopback порты 5195/8020/2529. Удаляет свои временные uploads и базу после завершения. Для браузера нужен установленный Chromium; в desktop sandbox требуется разрешённый локальный запуск сервера/браузера. Сборщик ZIP повторяет frontend/backend tests и production build, устанавливает production vendor в staging, проверяет отсутствие secrets, составляет checksums и запускает `tests/release-runtime.mjs` на отдельной копии готовой поставки. Packaged runtime дополнительно проверяет multipart комментариев обоих типов, idempotency, GD thumbnail, оригинал, download, независимость вложений идеи, миграции и прежние production-сценарии.

## Ограничения среды

Проверка браузера выполнена Chromium с мобильными viewport, без физического Android-устройства. WebKit не установлен, iOS Safari не запускался. Production-сервер/Jino не инспектировался и не менялся; действующие web-SAPI лимиты и реальные Email/Push/Telegram следует проверить при ручной приёмке по [PRODUCTION.md](PRODUCTION.md). СУБД автоматических тестов SQLite; блокировка строки/уникальный индекс предусмотрены для production MySQL, отдельный конкурентный прогон на MySQL здесь не выполнялся.

Push, deployment, GitHub Release и изменение Deno bridge не выполнялись.
