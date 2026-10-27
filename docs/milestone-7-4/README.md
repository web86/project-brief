# Milestone 7.4 — UI/UX Polish & Client Feedback

Работа выполняется в локальной ветке `codex/milestone-7-4-ui-feedback` от `7ab2ef4`. Deployment, push и merge не выполняются. Версия production-пакета: 0.7.4.

## Реализовано

1. Единый стиль карточек команды и клиентов: аватары, имя как основной акцент, второстепенный email, компактные роли/статусы, иконки существующего AppIcon, сгруппированные действия. Длинные строки переносятся, обе темы сохранены.
2. При создании клиента есть переключатель email-приглашения и обязательный email при включении. Используются прежние `/access/` токены, AccountMail и локализованный account-action шаблон; новый email-link действует 7 дней. Существующий механизм ручных ссылок сохраняет свой срок. Клиент фиксируется до отправки письма. SMTP-сбой возвращает успешное создание с отдельным результатом доставки; из карточки можно повторить отправку. Нормализованный email внутри проекта предотвращает повторное создание и автоматическое повторное письмо. Аккаунты User не создаются. Явная отправка нового приглашения заменяет только ссылку выбранного клиента, с прежними правами manage_clients и security step-up.
3. Основная информация идеи сворачивается через существующие disclosure-стили и sectionCollapse. Состояние сохраняется синхронно, отдельно для project/task. Редактор не размонтируется и не теряет черновик. В обсуждении идеи редактор расположен первым; список сортируется по createdAt DESC, затем по серверному sequence DESC. Серверный порядок общего Discussion API и его cursor не менялись. В идеях уже загружается весь список; сортировка не обрезает записи.
4. Клиентское обсуждение перенесено CSS из правой колонки под основную информацию; справа остаётся история. Структура колонок разработчика сохранена. CommentForm/CommentList едины для обоих интерфейсов.
5. До 640 px toast-viewport находится в `(0,0)`, занимает всю ширину, учитывает safe-area сверху и по бокам внутри уведомления. Компактные иконка/текст/закрытие, перенос длинных сообщений, вертикальный стек со скроллом до 40dvh. Desktop positioning и существующие таймеры/состояния сохранены.
6. Завершённость: активный проект с хотя бы одной актуальной задачей, все задачи `done`; SoftDeletes исключает корзину. Назначенный разработчик или владелец запускает запрос отзыва кнопкой карточки/страницы. Клиент автоматически получает окно один раз после запроса команды, при открытии проекта. Независимое ручное «Оставить отзыв» доступно завершённому проекту. Рейтинг 1–5 обязателен, текст необязателен, native radio обеспечивают клавиатуру и доступные подписи. Показ, закрытие и отправка хранятся на сервере отдельно для каждого клиента. Повторное завершение задач не сбрасывает состояние. После запроса кнопка показывает «Запрос отправлен», после получения хотя бы одного отзыва — «Отзыв отправлен».

Новые строки интерфейса и email добавлены в RU/EN/TR/ZH. Отзывы не включены в клиентские ProjectResource, export или общий Discussion API. Клиентский feedback endpoint возвращает только состояние текущего клиента; текст и рейтинг не раскрываются другим клиентам.

## База и доставка

Миграция: `2026_10_27_203324_create_project_feedback_table.php`. Одна новая таблица `project_feedback`, unique `(project_id, project_client_id)`, FK на workspace/project/client и существующую запись notification_deliveries. Прежние данные не изменяются; destructive down запрещён.

После сохранения отзыва намерение письма владельцу workspace фиксируется в той же транзакции; первоначальная отправка выполняется после HTTP-ответа, повторная — через существующий scheduler и `projects:feedback-mail --limit=20`. SMTP-сбой сохраняет отзыв и failed delivery. Успешные HTTP retries/доставки дедуплицированы. Письмо использует ProjectActivityMail, verified notification email владельца и экранирование Blade; содержит проект, клиента, рейтинг, текст, время и ссылку на проект. Обычный SMTP не даёт атомарного exactly-once при аварии между подтверждением SMTP и фиксацией результата в БД.

## Проверки

- Новые Laravel feature tests: 12 сценариев, 106 assertions — создание с/без письма, перевод, TTL/вход, дубликат, SMTP failure/retry, права и foreign project, пустой/частичный/полный проект, корзина, запрос назначенным разработчиком, claim/dismiss по клиентам, ручная отправка, повторный HTTP, XSS в письме, переоткрытие задач, private state, expired access и DB unique.
- Frontend: стабильный порядок при одинаковом времени и переставленном входном массиве, 1000 комментариев без потерь/мутации, состояния request button; i18n parity RU/EN/TR/ZH. Итоговый канонический builder: **302/302 frontend** и **790/790 Laravel**, **9590 assertions** (PHP 8.5.10 / SQLite).
- [BROWSER-RESULTS.json](BROWSER-RESULTS.json): 12 сценариев Chromium — 390/768/1440 px × light/dark × developer/client. Четыре локали распределены по матрице. Проверены сворачивание/немедленный reload, сохранность черновика при сетевой ошибке, новый комментарий сверху, расположение клиента, overflow, форма приглашения и результат ошибки, кнопки запроса отзыва, Escape/refresh/manual return, клавиатурный рейтинг, retry отзыва и отсутствие повторного окна, четыре одновременных notices.
- [BEFORE-RESULTS.json](BEFORE-RESULTS.json): исходные 12 сценариев, скриншоты сняты до изменения интерфейса.
- [attachments/browser-results.json](attachments/browser-results.json): реальный Laravel + отдельная SQLite + локальный SMTP-приёмник; комментарии идеи и общего обсуждения в обе стороны, chooser/paste/drop, восстановление файлов/текста при ошибке, retry без дублей, приватные downloads, 24 viewport/locale/theme combinations; 6 comments/files/deliveries, ошибок браузера нет. Старая fixture обновлена отметкой прочтения рекомендаций 7.x; исторические отчёты сохранены.
- Vite build, scoped Prettier, Pint dirty и git diff --check. Каноническая проверка ZIP дополнительно проверяет реальные packaged feedback/invitation HTTP endpoints, миграцию, single claim, двойную отправку, ownership, durable mail intent, exports и account security.

Проверки локальные и package-only. Внешний SMTP, production/Jino, физические iOS/Android и Safari/WebKit не проверялись. Browser UI matrix использует изолированные API fixtures; отдельный attachments E2E использует реальный Laravel.

## Основные экраны до/после

| Экран                        | До                                                   | После                                                          |
| ---------------------------- | ---------------------------------------------------- | -------------------------------------------------------------- |
| Команда, 390 dark            | [до](screenshots/before-team-390-dark.png)           | [после](screenshots/after-team-390-dark.png)                   |
| Клиенты, 390 light           | [до](screenshots/before-clients-390-light.png)       | [после](screenshots/after-clients-390-light.png)               |
| Клиентская идея, 1440 light  | [до](screenshots/before-idea-1440-light-client.png)  | [после](screenshots/after-idea-1440-light-client.png)          |
| Идея разработчика, 768 light | [до](screenshots/before-idea-768-light-admin.png)    | [после](screenshots/after-idea-768-light-admin.png)            |
| Mobile notices, 390 light    | [до](screenshots/before-toasts-390-light-client.png) | [после](screenshots/after-toasts-390-light-client.png)         |
| Новая форма приглашения      | —                                                    | [390 light](screenshots/after-client-invitation-390-light.png) |
| Новый отзыв                  | —                                                    | [390 dark](screenshots/after-feedback-390-dark.png)            |
| Кнопка на карточке проекта   | —                                                    | [1440 light](screenshots/after-project-card-1440-light.png)    |

## Production ZIP

Сборщик: `./bin/build-release`; чистый source commit `2c788c0e64a7e0e37268cb23695471ca7aeef1fd`. [Production ZIP](../../release/project-brief-v0.7.4-2c788c0.zip) — **39 172 435 bytes**. [RELEASE-ARTIFACT.json](RELEASE-ARTIFACT.json), [SHA256SUMS.txt](SHA256SUMS.txt).

SHA-256: `be63cce5b96f09d1b9671393174a3228c12f6ab3abc31b9c948caa7c731b9796`.

Сборка прошла с `NODE_EXTRA_CA_CERTS=/etc/ssl/cert.pem`: используется системный доверенный CA, TLS-проверка не отключалась. Проверены CRC ZIP, внутренние SHA-256, отсутствие секретов/QA-артефактов, production vendor и 8826 файлов runtime. Временная установка из ZIP прошла actual feedback/invitation HTTP, однократный claim, повторную отправку, foreign project denial, durable mail intent, существующие интеграции/экспорты и signed WebAuthn. PHP 8.4/MariaDB для 7.4 отдельно не перезапускались.

Тестовые документы, скриншоты, fixtures и QA-отчёты исключены из runtime ZIP. В архив включено самостоятельное PRODUCTION.md. Устаревшая ручная ссылка удаляется из карточки после email-приглашения, включая SMTP failure; это проверено браузером на всей матрице ширин/тем.

[Обновление и откат](PRODUCTION.md) — новая миграция, scheduler/retry, staging smoke, сохранение .env/storage и откат к прежнему коду без удаления отзывов. Отдельного разрешения на deployment нет.
