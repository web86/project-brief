# Phase 4B.2 — локальный backend checkpoint

## Границы и исполнение

- Внутренний executor закрепляет опубликованные версии и policy за подготовленным verified claim, используя исходный timestamp проверки. Повтор возвращает тот же период. Trial длится 604800 секунд UTC. Executor завершает onboarding только для verified владельца с TOTP, подтверждёнными recovery codes и принятым agreement. Вызов executor пока отсутствует в публичном onboarding и в интерфейсе оператора.
- Catch-up под workspace mutex записывает Paid → Grace → закреплённый free и Trial → закреплённый free. Повтор использует current period и revision. Audit и outbox входят в ту же транзакцию. Время перехода берётся из исходной границы, даже если обработка произошла позже.
- Expiry записывает один cycle и четыре execution epochs. Высокие отметки ресурсов позволяют исключать held-проекты из effective-выборки без обновления проектов. Админские запросы инициируют catch-up. Held marker применяется к проектным выборкам разработчика и клиентским сессиям. Владелец сохраняет чтение проекта. Task create/update проверяют удержание проекта под блокировкой workspace. Добавление проектов, клиентов и developer invitations в открытом cycle закрыто до подключения полноценного пересчёта capacity. Полное покрытие mutations и фоновая материализация остаются открытыми.
- Барьеры добавлены к bearer access, API replay, выдаче integration keys, созданию и чтению agent runs, claim, отчётам, контексту, файлам, Webhook enqueue/send/completion и SSO handoff/session. Состояние blocked действует и при смене режима на Observe. Для текущей epoch необходим совпадающий receipt. Сетевой запрос, уже отправленный до потери доступа, физически отозвать невозможно.
- Миграция `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs` добавляет два nullable поля. До первого epoch прежние handoffs совместимы. Действия по публикации версий, переводу Compatibility, production-активации и изменению данных Jino отсутствуют.

## Approval и восстановление ресурсов

- `POST /api/admin/resource-selection/previews/{preview}/apply-basic` проверяет владельца и fresh MFA, ревизию и idempotency key. Снимает удержание с одного проекта и явно выбранных клиентских участий, сохраняя остальные ограничения.
- `POST /api/platform/workspaces/{workspace}/resource-selection/previews/{preview}/approve-paid` принимает `serviceDays` и код `reason`. Требует primary PlanAuthority, fresh MFA и актуальный platform preview. Позднее назначение сохраняет уже записанный Basic/cycle. Своевременное продление того же тарифа закрепляет следующий период на границе UTC, а продление в Grace начинается с фактического момента подтверждения. Обе операции пишут audit без имитации платежа.
- `POST /api/admin/resource-selection/previews/{preview}/restore-paid` применяет выбор проектов, клиентских участий и разработчиков из нового owner preview. Ревизия и idempotency key обязательны для всех трёх операций. Старые задачи, credentials и premium execution остаются заблокированы до отдельного rearm.
- Диагностика проверяет опубликованные версии и policy 7/7, legacy cohort, состояние accounting и конфигурацию email. Неподтверждённая доставка, rollback и rearm остаются блокирующими условиями. Публичный gate закрыт.

## Открытые условия выпуска

Остались кнопки подтверждения в интерфейсе, восстановление агентских участий и резервов приглашений, проверяемое подтверждение rearm интеграций, покрытие всех workspace/project/client mutation guards, bounded reconciliation и операторская активация после проверки rollback. Backend-операции тестируются изолированно. Публичный end-to-end сценарий ещё не принят. Production ZIP и Jino runbook относятся к завершённому этапу.
