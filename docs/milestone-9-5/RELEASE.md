# Project Brief v0.9.5 — release report

Подготовлен локально 31.10.2026 (Europe/Istanbul), сборка 30.10.2026 20:58 UTC. Ветка `codex/milestone-9-5-team-improvements`. База v0.9.4 и все предыдущие изменения сохранены. Push, deployment и изменение production DB не выполнялись.

## Артефакт

- Production ZIP: `release/project-brief-v0.9.5-94943a0.zip`.
- Размер: **39 344 087 байт**.
- SHA-256: `174f1c59fa486121bfd0dcf505647880691b4e621488c5e5bb9a0705948db303`.
- Рядом сохранён `project-brief-v0.9.5-94943a0.zip.sha256`.
- Исходный коммит runtime: `94943a0da76cc8fb6a2f8e241262e3121ea81317`; последующий коммит отчётов содержит только документацию и не меняет ZIP.
- Канонический `./bin/build-release` завершился успешно: 8901 runtime-файл, проверка секретов/запрещённых файлов, production vendor, внутренние checksums, извлечение ZIP и runtime-тесты. Отдельный `unzip -t` — без ошибок.
- Скриншоты, QA-изображения и новые изображения отсутствуют в diff ветки относительно базы `6b7be1b`; файлы доказательств остаются только в OS temp. В production ZIP нет QA-снимков.

## Реализовано

Полные редактируемые RU/EN/TR/ZH-CN примеры соглашения, язык по умолчанию и enabled languages, fallback без смены интерфейса, неизменяемые снимки опубликованных редакций, явное заполнение существующего редактора. Существующие публикации и факты принятия не перезаписываются. При проверке исправлена потеря вложенного в заголовок native-списка при переключении языка; HTML allowlist и безопасные ссылки сохранены.

Карточки команды с MFA-индикаторами, отдельная карточка владельца, аккуратная форма приглашения. Одно право project superadministrator — owner-only, false по умолчанию, работает только в назначенных проектах. Обычный администратор сохраняет стандартные функции задач; advanced management/корзина защищены сервером. Создание проектов, глобальное администрирование, окончательное удаление/очистка корзины не делегируются. Scoped приглашения не повышают глобальные права, приём перепроверяет текущие права пригласившего.

Бренд приглашения виден до входа и сохраняется после F5/MFA. Подтверждённый контекст приглашения хранится в server session без raw token в localStorage; ротация MFA его больше не теряет. Header публичного документа и обязательного принятия получает проверенный бренд документа, независимо от активного административного workspace. Security onboarding стал компактным, с раскрытием формы Passkey и сохранением существующих policy/MFA/recovery/step-up правил.

Общая вкладка заменена приватным чатом команды. По уточнению пользователя прежние общие сообщения разработки переводятся в team-only, task/idea comments не меняются. Переиспользованы сообщения/файлы/read/Live Sync; новый polling и тяжёлые зависимости отсутствуют. Приватные сообщения и вложения исключены из клиентских API/уведомлений/экспорта/webhooks/integrations. Проверяются назначение/workspace, submissionId/fingerprint, unread, отзыв доступа и скачивание файлов. Отчёт о фактическом хранении — [SECURITY-CHAT.md](../../SECURITY-CHAT.md).

## Проверки

| Проверка                                                           | Результат                                                                                                                                                                                                         |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Полный Laravel suite, PHP 8.5.10                                   | **1004 / 1004**, 14838 assertions                                                                                                                                                                                 |
| Полный frontend suite                                              | **407 / 407**                                                                                                                                                                                                     |
| MariaDB 11.8.9, host PHP с GD                                      | **84 / 84**, 1634 assertions                                                                                                                                                                                      |
| Финальные затронутые MariaDB-тесты после доработок                 | **25 / 25**, 393 assertions                                                                                                                                                                                       |
| Целевой PHP 8.4.26 + MariaDB 11.8.9                                | **25 / 25**, 393 assertions; соглашения, private chat/права, MFA-invitation, независимые Passkey attempts                                                                                                         |
| Browser Milestone 9.5, SQLite                                      | Сценарии успешны, **193** снимка; 4 языка, 1440/390 px, light/dark/system, открытые admin modal/project invitation; итоговый header бренда проверен                                                               |
| Browser Milestone 9.5, MariaDB + 4 PHP workers                     | Сценарии успешны, **193** снимка; конкурентные POST из двух реальных вкладок возвращают один message ID                                                                                                           |
| WYSIWYG browser                                                    | **2 группы**, 32 снимка, 0 JS errors: ссылки, Undo/Redo, активное форматирование, списки/языки, reload/publish/public/required agreement                                                                          |
| Account security browser                                           | **23 сценария**: TOTP/recovery, native user activation, подписанные virtual UV assertions, retry/cancel/fallback, developer/client regression; Firefox с mock provider                                            |
| Integrations + /platform, одна session/две вкладки                 | **4 группы**, 0 JS errors, настоящие подписи virtual UV authenticator                                                                                                                                             |
| Извлечённый ZIP                                                    | Signed WebAuthn enrollment с production vendor; compiled UI → реальный UV step-up → одно изменение project superadministrator; CLI/HTTP/runtime, SSO, provisioning, platform ACL, комментарии/файлы и maintenance |
| Production build/Pint/brand assets/CRC/internal hashes/secret scan | Успешно                                                                                                                                                                                                           |

Повторные прогоны не суммируются в число уникальных тестов. Полный Laravel suite выполнялся на PHP 8.5; выбранный security/MariaDB набор дополнительно проверен на PHP 8.4. В Docker QA image нет GD, поэтому полный выбранный MariaDB набор с изображениями выполнялся host PHP с GD.

Браузер проверял реальные формы, прямой запрет клиентского private API, бренд приглашения после F5, публикацию, переключение языков, модальное окно и Escape. Серверные регрессии отдельно покрывают четыре языка принятия, историю/дату, bypass/XSS/опасные URL, отзыв superadmin/назначения, workspace isolation, файлы, дедупликацию, unread, приватные уведомления и Live Sync. Подробности — [QA.md](QA.md).

## PR_CHALLENGE_INVALID — причина и результат двух вкладок

В исходном обращении указан `PR_CHALLENGE_INVALID`; текущий код использует `PK_CHALLENGE_INVALID`. Подтверждённый дефект старой реализации: новый passkey_confirm удалял предыдущую запись с теми же session_hash/purpose и заменял единственный session pointer. Integrations и /platform разделяли это назначение. Исправление введено в базовой v0.9.2 и сохранено в v0.9.5: независимый attemptId, user/session/purpose/context binding, TTL и атомарное одноразовое consume, без ослабления подписи/RP ID/origin/UV. Подробная диагностика — [Milestone 9.3 / PASSKEY.md](../milestone-9-3/PASSKEY.md).

Повторный браузерный прогон v0.9.5: assertion A создания API-key удерживается, вкладка B /platform создаёт независимую попытку; обе успешно завершаются, включая ротацию session. Один API-key при double click. Replay отклонён, TTL отклонён, Retry с новым challenge успешен, browser cancel не создаёт ключ, допустимый TOTP fallback сохраняет операцию. Проверено также серверными тестами на MariaDB. Исходный production-инцидент и физический Touch ID этим не подтверждаются.

## Невыполненные live-проверки и ограничения

Jino/FPM/deployment, физический Touch ID/Safari/WebKit и реальные мобильные устройства, внешняя доставка SMTP/Telegram/push, реальные production backup/restore, TLS/HTTPS-only/HSTS и шифрование дисков/СУБД/backup на хостинге не проверялись. Chromium 390 px — viewport emulation; Firefox WebAuthn использует mock provider. Юридическая экспертиза и редактура примеров носителями всех языков не выполнялись.

Текст переписки и файлы **не шифруются приложением при хранении; E2EE нет**. Доступ защищается авторизацией и private storage; внешние приватные уведомления не содержат сообщения. Факты и отдельное предложение серверного encryption-at-rest описаны в SECURITY-CHAT.md, масштабная миграция шифрования не внедрялась.

Обновление и безопасный rollback для Jino — [PRODUCTION.md](PRODUCTION.md). **Старый v0.9.4 runtime нельзя открывать на текущей v0.9.5 БД:** он не знает приватный канал. Использовать совместимый forward fix либо согласованное восстановление пары runtime + pre-v0.9.5 backup в maintenance.
