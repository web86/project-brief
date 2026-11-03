# Phase 4B.2 — локальный backend checkpoint

## Границы и исполнение

- Внутренний executor закрепляет опубликованные версии и policy за подготовленным verified claim, используя исходный timestamp проверки. Повтор возвращает тот же период. Trial длится 604800 секунд UTC. Executor завершает onboarding только для verified владельца с TOTP, подтверждёнными recovery codes и принятым agreement. Вызов executor пока отсутствует в публичном onboarding и в интерфейсе оператора.
- Catch-up под workspace mutex записывает Paid → Grace → закреплённый free и Trial → закреплённый free. Повтор использует current period и revision. Audit и outbox входят в ту же транзакцию. Время перехода берётся из исходной границы, даже если обработка произошла позже.
- Expiry записывает один cycle и четыре execution epochs. Высокие отметки ресурсов позволяют исключать held-проекты из effective-выборки без обновления проектов. Админские запросы инициируют catch-up. Held marker применяется к проектным выборкам разработчика и клиентским сессиям. Владелец сохраняет чтение проекта. Task create/update проверяют удержание проекта под блокировкой workspace. Добавление проектов, клиентов и developer invitations в открытом cycle проходит пересчёт эффективной capacity. Полное покрытие mutations и фоновая материализация остаются открытыми.
- Барьеры добавлены к bearer access, API replay, выдаче integration keys, созданию и чтению agent runs, claim, отчётам, контексту, файлам, Webhook enqueue/send/completion и SSO handoff/session. Состояние blocked действует и при смене режима на Observe. Для текущей epoch необходим совпадающий receipt. Сетевой запрос, уже отправленный до потери доступа, физически отозвать невозможно.
- Миграция `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs` добавляет два nullable поля. До первого epoch прежние handoffs совместимы. Действия по публикации версий, переводу Compatibility, production-активации и изменению данных Jino отсутствуют.

## Approval и восстановление ресурсов

- `POST /api/admin/resource-selection/previews/{preview}/apply-basic` проверяет владельца и fresh MFA, ревизию и idempotency key. Снимает удержание с одного проекта и явно выбранных клиентских участий, сохраняя остальные ограничения.
- `POST /api/platform/workspaces/{workspace}/resource-selection/previews/{preview}/approve-paid` принимает `serviceDays` и код `reason`. Требует primary PlanAuthority, fresh MFA и актуальный platform preview. Позднее назначение сохраняет уже записанный Basic/cycle. Своевременное продление того же тарифа закрепляет следующий период на границе UTC, а продление в Grace начинается с фактического момента подтверждения. Обе операции пишут audit без имитации платежа.
- `POST /api/admin/resource-selection/previews/{preview}/restore-paid` применяет выбор проектов, клиентских участий и разработчиков из нового owner preview. Ревизия и idempotency key обязательны для всех трёх операций. Restore также проверяет текущие grants и capacity для AI-агентов и восстанавливает выбранные действующие резервы приглашений. Срок приглашения сохраняется.
- Владелец отдельно подтверждает rearm с fresh MFA и текущей ревизией. Новая epoch разрешает только выбранное участие и текущие права. Старые API replay, Webhook deliveries, Agent runs и SSO handoffs остаются закрыты. Повторный restore снова блокирует ранее открытые epochs.
- Команда `plans:reconcile-subscriptions` ограничивает число workspace и записей в batch. В режиме `--apply` догоняет сроки и отменяет stale runs/deliveries.
- Диагностика проверяет опубликованные версии и policy 7/7, legacy cohort, состояние accounting и конфигурацию email. Неподтверждённая доставка, rollback и rearm остаются блокирующими условиями. Публичный gate закрыт.

## Открытые условия выпуска

Подтверждения Basic, manual paid, restore и rearm подключены к существующему frozen preview. До выпуска требуется сверка покрытия mutations и QA одного финального runtime, включая извлечённый архив. Операторский допуск остаётся закрытым до проверки доставки и карантина/forward fix на стенде. Production ZIP и Jino runbook относятся к завершённому этапу.

## Пересчёт capacity

Backend использует текущий pinned entitlement snapshot и тот же `WorkspaceUsage` с фильтром эффективного участия. Создание, активация и восстановление проекта, добавление клиента, выделение developer seat и создание или активация агента сверяют предложение под workspace mutex. Если новый эффективный набор превышает лимит, транзакция откатывается. Client identities использует существующий accounting. Новый резерв приглашения учитывается в текущем cycle, а при принятии проверяются identity, срок и доступность назначенных проектов.

При смене frozen selection границы marker обновляются под тем же mutex. Ресурсы, появившиеся после первого expiry, учитываются в новом выборе и снова удерживаются, если не выбраны.

Условия выпуска остаются открытыми. Требуется повторный полный QA новой реализации, завершение операторского controlled canary, проверка альтернативных mutation paths и репетиция карантина/forward fix. Публичная активация закрыта.
