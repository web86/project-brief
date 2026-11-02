# Milestone 10.0 — проект интерфейсов

Статус: **экранные структуры и UX, без Vue/CSS/routes implementation**.
Сохранить существующие shell/navigation, CSS tokens, themes, buttons/LoadingButton,
API client, CSRF/step-up, loading/empty/error states. Новый UI framework не нужен.

## 1. Доступ и навигация

Существующий `/platform` получает PlanAuthority-based раздел «Тарифы и политики»:
каталог/версии, правила, подписчики, previews/rollouts, Trial/Grace/fallback,
retention settings/history. Только primary + текущие MFA/strong/fresh gates.
Обычный platform_admin видит прежнюю диагностику; скрытое меню не заменяет серверный
запрет. Прямые deep links и reload защищены теми же middleware.

Future delegation моделируется capability check, но интерфейса помощников,
новых ролей и списка delegation permissions на первом этапе нет. Bootstrap/recovery
primary выполняется operator runbook, без кнопки «Сделать себя главным» в UI.

Owner: «Мои настройки → Тариф workspace», «Приостановленные ресурсы» и «Хранение
и удаление». Сохранить текущую collapsible navigation. Suspended non-owner видит
безопасное объяснение и ссылку обратиться к owner, свой profile/security и доступ
к другим workspace; не видит чужие subscription/content/usage details.

## 2. Глобальные Trial / Grace / fallback settings

```text
Политика доступа v3                         [Создать новую редакцию]
Первоначальный Trial    7 дней              [Studio · версия 2]
Grace после paid        7 дней              [предыдущие paid возможности]
Бесплатный fallback                        [Basic · версия 1]
После Trial: сразу Basic, без дополнительного Grace
Действующие периоды: закреплены за прежней policy/версиями
[Сохранить draft] [Preview] [Подтвердить публикацию]
```

Список target versions только published, допустимого access class/availability.
Точная version и UTC boundary видны, не только план по имени. Поля duration
типизированы и ограничены сервером; client date не источник времени. Изменение
pointer показывает «для новых периодов»; перенос existing Trial/Grace — отдельный
reviewed action с affected counts. Нет checkbox «обновить всех» по умолчанию.

Eligibility блок объясняет verified email, один Trial владельцу и отсутствие
перезапуска новым workspace. Для existing compatibility workspace явно нет
обратного отсчёта и автоматического перевода на Basic.

## 3. Каталог и версии

Unlimited число тарифов с pagination/search/status/visibility/sort order; нет
фиксированной тройки карточек в editor. Колонки: name/code, version, free/paid
price metadata, status, subscriber counts active/all/by version, open/history.
Hidden/archived/draft/published различимы текстом, не только цветом.

Published версия read-only; «Создать новую версию» копирует выбранную. Draft
показывает base/hash/revision/diff, четыре translation values ru/en/tr/zh-CN.
Цена «По согласованию» для paid amount=null, без обещания checkout/платежа.
Registry editor строится из доступных typed definitions: boolean, integer,
unlimited toggle, enum и quota unit/window. Paid-only constraint виден рядом
с feature; на free target запрещено включение, включая override. FormWatch —
«Предусмотрено, адаптер не подключён», не зелёное «Работает».

### Seats — один источник истины

```text
Места разработчиков
Включено: [10]       Технический максимум: [50]
Дополнительные места: разрешены, только подтверждение платформы
Derived developers.max: до 50; не редактируется отдельным полем
Workspace: активных 8 · приглашения 2 · extra одобрено 0 · доступно 10
```

Included и maximum имеют разные значения и живут в одном policy. Нет второго
editable developers.max/hard limit в общем registry editor. На workspace экране
primary подтверждает quantity extra с preview; owner лишь запрашивает. Agents не
считаются human seats. Изменение состава/hold/expiry возвращает свежий server count.

Dirty form, field errors+summary/focus, revision conflict с reload/diff без потери
ввода. Descriptions plain text, никакого HTML execution. Не поддержанный scope
не предлагается как произвольный выбор; scope migration — отдельный preview.

## 4. Preview, rollout и копирование правил

Save draft → preview → diff/impact → explicit policy → fresh step-up → confirm
точных hash/revisions/target set. Keep-existing default. Publication и rollout
показываются разными outcomes. Большой batch: processed/total, applied/skipped/
needs_review/failed/cancelled; resume remaining, stale preview требует пересчёта.

Platform drilldown только workspace UUID/разрешённые metadata, counts/limits,
asOf, loss keys, dates, reserved seats и suspension/epoch impact. Не выводить
project/task titles, client emails, files, private chat/context или token secrets.

Rule copy: выбрать старую/новую version → увидеть remap кандидатов, конфликты,
недопустимые targets/approval и disabled rules → подтвердить новый rules revision.
Нет скрытого копирования при publish. Paid transitions — «Подтверждение платформы»;
payment approval недоступно до adapter. History сохраняет actor/time/reason/diff.

## 5. Страница owner и уведомления

Current plan/version, pinned policy, effective phase, UTC deadline с локальным
представлением через существующий formatter, usage asOf и next change. Блоки:

- Trial: «Полный пробный доступ до …; затем Basic без дополнительного Grace».
  Pending email verification объясняет оставшееся время, не предлагает restart.
- Grace: «Предыдущие paid возможности сохраняются до …; затем Basic».
- Basic после expiry: причина и дата, сколько проектов/людей приостановлено,
  «Выбрать проект», «Запросить paid доступ», «Экспортировать данные».
- Compatibility/Observe: сохранённые права, новые ограничения ещё не применены;
  не показывать ложное требование срочно продлить.

Предупреждения до Trial/Grace end идут существующим authorized channel и in-app,
с настраиваемыми bounded offsets, dedupe и без project content. Задержанный email
не продлевает срок; banner/reason вычисляются сервером. Численные retention сроки
не выдумываются. Request paid/extra сообщает pending approval, не «уже куплено».

## 6. Basic: выбор проекта и клиентов

Экран приостановленных проектов показывает owner-authorized имя/историю, исходное
состояние, тарифную причину, unique-client count. История/export доступны, full
работа disabled сервером и UI. Archive и тарифная приостановка — разные labels.

Выбрать один проект → preview. Если клиентов больше трёх, показать deduplicated
identities и их project participations, предложить оставить <=3 активных.
Остальные **приостанавливаются**, не удаляются; явно описать потерю рабочего
доступа и сохранение данных/истории. Не предлагать merge аккаунтов ради счётчика.
Ambiguous identities отображаются как требующие review, не объединяются по имени.

Подтверждение одной операцией активирует project и выбранных clients. При другом
active project — явный switch/archive preview, не скрытое замещение. Stale usage,
concurrent activation или изменённый клиентский состав требуют нового preview.
Owner остаётся единственным developer; suspended команда видна без смены ролей.

## 7. Paid restoration и интеграции

После approved paid period показать «Доступ оплачен/предоставлен вручную» строго
по фактам; manual grant не выдавать за транзакцию оплаты. Ресурсы не возвращаются
автоматически. Owner выбирает проекты/команду/участия в пределах новых limits;
preview показывает ручные inactive/deleted/revoked состояния и не восстанавливает их.

Отдельное подтверждение интеграций показывает names/IDs, scopes, current binding,
project grants и disabled/expired/revoked reasons. Нет повторного раскрытия secret.
Общий key остаётся общим; UI не создаёт новый автоматически и не расширяет scopes.
Webhooks остаются независимыми, один тип событий, без выбора имени агента.
UUID агента остаётся selectable card text; в agent modal нет UUID/webhook URL.

Сообщение после rearm: «Старые события и поручения не запускаются повторно.
Создайте новое поручение». Cancelled history читается; кнопки повторного выполнения
создают новый submission, а не переотправляют старый run. Нельзя кнопкой «Включить
всё» неожиданно активировать источники/endpoint/automation. Partial restore
показывает конкретные applied/needs_review/failed, а не общий success toast.

## 8. Retention и самостоятельное удаление

Primary editor отдельно показывает active_free / suspended_excess /
closed_abandoned / owner_requested: applicability, срок, предупреждения, action,
backup-retention. Automatic purge disabled с причиной «Не пройдена staging
проверка»; публикация settings не обходит code gate. Active free проект защищён
от удаления из-за прошлого Trial/paid expiry.

Owner видит только применимую policy и понятные consequences. Удаление workspace:
impact preview/counts → предложение export → fresh step-up → typed confirmation
→ durable job status/cancel до purge. Ясно отделить private storage deletion от
сроков backups и не обещать мгновенного уничтожения всех копий. Глобальный User
и другие workspace не удаляются. После начала purge нет ложной кнопки rollback.

## 9. Адаптивность, темы и языки

Обязательные новые primary UI labels/errors — RU/EN; сохранить существующий locale
switch/fallback. Пользовательские owner/client/member notices и capabilities —
RU/EN/TR/zh-CN; plan translation values также четыре. Chinese storage alias zh
проходит текущий adapter. Никаких новых locale codes или зашитых дат/валют.

Mobile 390px/desktop, light/dark/system, существующие design tokens, readable
long RU/TR labels и Chinese strings, contrast >=4.5:1 для обычного текста.
Таблицы — responsive cards или bounded horizontal container без overflow страницы.
Keyboard/focus/labels/live progress, statuses не только цветом. Workspace switch
очищает snapshot и pending fetch; stale response не показывает чужой workspace.
Snapshot validUntil обновляет UI на boundary, но разрешение всегда backend.

Это проект экранов; Vue, screenshots и mockup assets в текущем задании не создаются.
