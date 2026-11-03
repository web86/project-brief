# Phase 4B.2 — локальный backend checkpoint

## Границы и исполнение

- Внутренний executor закрепляет опубликованные версии и policy за подготовленным verified claim, используя исходный timestamp проверки. Повтор возвращает тот же период. Trial длится 604800 секунд UTC. Вызов executor пока отсутствует в публичном onboarding и в интерфейсе оператора.
- Catch-up под workspace mutex записывает Paid → Grace → закреплённый free и Trial → закреплённый free. Повтор использует current period и revision. Audit и outbox входят в ту же транзакцию. Время перехода берётся из исходной границы, даже если обработка произошла позже.
- Expiry записывает один cycle и четыре execution epochs. Высокие отметки ресурсов позволяют исключать held-проекты из effective-выборки без обновления проектов. Пока отсутствуют полный набор business access guards, исполнение release через frozen selection и фоновая материализация.
- Барьеры добавлены к bearer access, API replay, выдаче integration keys, созданию и чтению agent runs, claim, отчётам, контексту, файлам, Webhook enqueue/send/completion и SSO handoff/session. Состояние blocked действует и при смене режима на Observe. Для текущей epoch необходим совпадающий receipt. Сетевой запрос, уже отправленный до потери доступа, физически отозвать невозможно.
- Миграция `2026_11_03_151351_add_execution_receipt_to_client_sso_handoffs` добавляет два nullable поля. До первого epoch прежние handoffs совместимы. Действия по публикации версий, переводу Compatibility, production-активации и выдаче paid периода отсутствуют.

## Открытые условия выпуска

Остались ревизионное подтверждение paid assignment/renewal и последовательность продления, Basic selection apply, selective restore, подтверждение rearm интеграций, покрытие workspace/project/client mutation guards, bounded reconciliation и readiness gate для оператора. Полный сценарий Trial → Basic → manual paid → selective restore ещё не принят. Production ZIP и Jino runbook относятся к завершённому этапу.
