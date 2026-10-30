# Milestone 9.5 — QA

Проверки выполняются локально и на disposable БД. Production/Jino не изменялись. Окончательные результаты канонической сборки и контрольная сумма — RELEASE.md.

## Подтверждено

Канонический builder завершился успешно: Laravel 1004 / 1004 (14838 assertions), frontend 407 / 407. Извлечённый ZIP прошёл runtime, signed WebAuthn и compiled UI проверки. Точная контрольная сумма и ограничения — [RELEASE.md](RELEASE.md).

- PHP 8.5 / Laravel 13, Vue 3; без новых зависимостей. Pint и production Vite build проходят.
- Focused SQLite: 21 тест / 434 assertions для миграции, корзины и новых сценариев; после расширения MFA отдельный Milestone95Test — 8 / 115.
- MariaDB 11.8.9 на локальном Docker, host PHP с GD: 84 / 1634, все успешны. Сюда входят соглашения, права/корзина, назначения, приглашения, чаты/файлы/unread/notifications/Live Sync и workspace isolation. Первый прогон PHP 8.4 Docker выявил отсутствие GD в QA image; успешный полный выбранный набор повторён host PHP с GD. Production dependencies проверены каноническим builder. Финальные затронутые тесты дополнительно прошли на PHP 8.5/MariaDB и PHP 8.4.26/MariaDB: по 25 тестов / 393 assertions.
- WebAuthn browser suite: 23 успешных сценария на изолированном HTTPS — enrollment/recovery, необязательный Passkey, нативная user activation, один PATCH при двойном клике, отмена и сохранение черновика, новый challenge/retry, реальная подписанная Chromium assertion, discoverable login, MFA и fallback. Firefox проверен с mock provider/реальным сервером; это не аппаратная подпись Firefox.
- Milestone 9.5 browser: templates/language toggle/publish/reload; team card; private messages; одинаковый submissionId из двух реальных вкладок; подтверждённое приглашение + F5; отсутствие чата у клиента и отказ прямому API. В визуальной матрице HTML lang и data-theme проверяются явно: RU/EN/TR/ZH-CN × 1440/390 × light/dark/system, включая длинный бренд, соглашения, карточки, чат, security, публичный документ и футер. Дополнительно снимаются открытый admin modal и форма project invitation. Скриншоты только вне Git. SQLite-прогон: 193 снимка и успешные сценарии; отдельная WYSIWYG-регрессия: 32 снимка, ссылки/Undo/Redo/списки сохраняются. Браузер MariaDB использует четыре PHP workers и POST из двух реальных вкладок: успешный прогон, 193 снимка.
- Визуально просмотрены desktop/mobile light/dark на разных языках. Найденное прилипание формы приглашения к карточке владельца исправлено; добавлены нормальные padding/gap и мобильная колонка. Уменьшены пустые области security; разделитель даты чата не содержит время сообщения. Проверяются горизонтальное переполнение, Escape модального окна, активные tool buttons и уникальные language labels.

Общий header публичного документа и обязательного принятия получает бренд из проверенного ответа документа, независимо от выбранного административного workspace; при загрузке не показывается чужой бренд. Для этого добавлена отдельная регрессия, 31 focused frontend test прошёл.

Отдельный двухвкладочный Passkey browser прогон Integrations + /platform прошёл 4 группы / 0 ошибок с настоящими virtual UV signatures, TTL/replay/cancel/retry/TOTP fallback.

## Регрессионные гарантии

Новые feature-тесты покрывают приватность файлов/текста, клиента и постороннего участника, повторную отправку/409, unread, отсутствие текста в уведомлениях и отсутствие клиентских адресатов, IntegrationReadAccess, Live Sync, отзыв пригласившего и MFA rotation. Серверные тесты существующего соглашения сохраняют XSS/URL allowlist, четыре языка принятия, запрет API до согласия, историю, дату, явное повторное принятие, изоляцию workspace, Passkey/TOTP и ссылочные блоки. Тесты старых per-project advanced grants/создания обновлены под явно согласованное новое право; structure/export остаются независимыми.

Миграционные тесты явно проверяют team_only: прежние общие сообщения становятся private, task comments остаются public. При MariaDB rollback-тестировании старой миграции корзины обнаружен FK, использующий composite index; down теперь предварительно создаёт workspace index, чтобы не ломать FK. Миграция v0.9.5 не выполняет down, предотвращая раскрытие приватного канала.

## Не проверено на production / ограничения

- Jino/FPM, реальная TLS/HTTPS-only/HSTS конфигурация, disk/DB/backup encryption и восстановление настоящего production backup.
- Физический Touch ID, реальный Safari/WebKit и мобильные устройства; Chromium 390 px — эмуляция viewport. Firefox WebAuthn использует mock provider. Реальный WebKit не объявляется успешным.
- Реальная внешняя SMTP/Telegram/push доставка: используются локальный SMTP/fakes и проверка payload/адресатов.
- Профессиональная юридическая экспертиза примеров и редактура носителями всех языков. Это полноценные эквивалентные примеры для адаптации владельцем.

Нельзя объявлять эти пункты успешными по результату unit/browser/package тестов. Прикладного шифрования переписки при хранении и E2EE нет, подробности в SECURITY-CHAT.md.
