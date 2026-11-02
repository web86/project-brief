# Ограничения и следующий этап

Обновление 03.11.2026: Phase 3 реализован — [Inventory, Accounting и Observe Preview](../phase-3/README.md). Список ниже фиксирует историческую границу Phase 2; актуальные оставшиеся Phase 4 задачи — [Phase 3 NEXT](../phase-3/NEXT.md).

- Панель управляет конфигурацией; usage/accounting providers, реальные workspace
  previews, rollout executor и owner subscription UI не подключены.
- Нет auto subscriptions/backfill, Trial activation/verification/OAuth,
  downgrade/Grace executor, suspension/restore, execution barriers или payments.
- Storage/AI/run/FormWatch quotas не утверждены. Их definitions остаются planned;
  FormWatch feature unavailable. Capacity/storage IO/race enforcement не заявлены
  готовыми. Destructive retention и новый universal exporter отсутствуют.
- Safe eligibility/security/retention maps пока code-owned: UI показывает
  поддерживаемую политику, не разрешает произвольные новые параметры/expressions.
- Rules можно подготовить/включить как configuration; выполняющего переходы
  caller нет. Copies первоначально disabled/manual; payment approval недоступен.
- Preview конфигурации bounded и синхронный, ready с TTL 15 минут. Это не preview
  влияния на 1000 workspace. Workspace impact unavailable до реальных providers.
- Operator verification требует организационной процедуры и защищённого доступа
  к серверной консоли. HTTP self-bootstrap, delegation и assistants отсутствуют.
- Compatibility class через обычный management editor не создаётся; hidden
  free/paid планы поддержаны. Inventory и migration exception — следующий этап.
- Copy rules ограничен 100 связанными rows, затем explicit unavailable; ни
  молчаливого обрезания, ни частичного apply. Version/rule/catalog/history lists
  paginated, для picker есть load-more.

Следующий этап начинать с изолированного inventory и verified client accounting,
compatibility Observe, invitation occupancy и real preview providers. Затем
reviewed frozen transitions, lifecycle catch-up и per-operation guards по D1–D12.
Сохранить workspace-first locks, existing security/access/scopes и human-reviewed
private agent results. До этих adapters никакие тарифные ограничения не включать.
