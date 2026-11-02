# Следующие этапы после Phase 1

D1–D12 остаются утверждёнными. Следующие компоненты не включены в Phase 1 и
не объявляются протестированными как работающие enforcement adapters.

1. Защищённый management HTTP/UI и operator bootstrap/recovery primary authority:
   existing MFA/strong/fresh proof, CSRF/session revocation, authority revision,
   owner/primary isolation, RU/EN admin labels. Catalog service не заменяет эти gates.
2. Compatibility inventory/backfill и hidden compatibility versions, сначала Observe.
   Проверить реальное существующее состояние, не назначать Basic/Trial массово.
   Полный verified client accounting, provenance/ambiguous aliases без auth merge;
   occupied human + invitation identity union, не observed count.
3. Preview executor и reviewed transitions: frozen target set, revisions/fingerprints,
   workspace-first current reads, bounded batches/checkpoints, partial/resume/audit.
   Paid downgrade выбирает допустимые active projects/people/clients/agents,
   остальное held обратимо; unsafe selection отклоняется. Permanent block_growth
   для сохранения сверхлимитных active ресурсов не допускается.
4. Verification/onboarding и basic Google/Яндекс login в текущей auth-системе.
   Проверенный email/identity, explicit linking, one owner Trial, subject hashes и
   key rotation. Trial начинается только после проверки, 7×24 часа, без Grace;
   pending registration не получает workspace access. Basic social login — не SSO.
5. Lifecycle executor: pinned policies, paid 7-day Grace, logical UTC boundaries,
   no-cron correctness, audited periods/supersession, Basic fallback и выбор 1/3.
   Pure clock уже возвращает fallback_required; он не выполняет downgrade.
6. Participation overlays, high-watermarks, workspace/resource ownership review,
   execution epochs и pre-replay/pre-send/pre-result checks во всех existing
   human/client/API/agent/webhook/SSO writers. Restore не возвращает stale execution.
7. Durable storage adapter вокруг существующих writers/cleanup: all project states,
   trash/private files/branding, exact component bytes, logical original count,
   derivative family, physical publication copies. Reserve bytes+files до IO вне
   внешней rollback transaction, держать cleanup_pending и expired lease до verified
   cleanup. Reconcile, idempotent decrement, storage read-only при overage;
   read/download/delete и existing PDF/DOCX сохраняются. Коммерческие числа ещё
   не утверждены; универсальный storage backend/exporter не создаётся.
8. Quota ledger только с реальным AI/run/FormWatch consumption boundary и
   утверждёнными числами: durable dedupe, uncertain outcome, trusted receipt,
   сохранение consumption при renewal/scope/window change. FormWatch unavailable
   до реального adapter. Retention jobs/purge — только с утверждённой policy,
   warning/legal hold/backups и проверяемым удалением; automatic purge выключен.

## Отложенные конкурентные acceptance contracts

| Будущий тест                                           | Обязательный результат                                                                                |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Две последние capacity allocations / invitation accept | Один workspace mutex; identity union; multi-key all-or-nothing; delta=0 replay не создаёт второй seat |
| Две uploads у границы bytes и files                    | Оба limits зарезервированы до IO; один проигравший; без oversubscription                              |
| Crash/outer rollback/unknown IO/expired lease          | Durable held/cleanup_pending остаётся occupied; нет повторной неизвестной операции и ложного release  |
| Частичное физическое удаление, повтор receipt          | Bytes уменьшаются один раз, logical file освобождается только после удаления всей family              |
| Downgrade против claim/send/report/replay              | Current epoch/participation проверены до replay/execution; stale queued work не возобновляется        |
| Ротация scope/window/renewal против ledger writes      | Consumed/reserved/uncertain не обнуляются, reviewed mapping и безопасная boundary                     |

В Phase 1 реально выполнены независимые concurrent **catalog publication/edit**
тесты. Таблицы storage/capacity/epoch и эта матрица — подготовка, а не доказательство
прохождения перечисленных будущих race tests.
