# Phase 4B.2 — lifecycle, capacity и подготовка выпуска

База `1f939fd`, runtime v0.9.16 `ab6af9a`. Ветка `codex/milestone-10-0-phase-4b2`.

## Реализовано

- «Мои настройки → Тариф и подписка» на `/admin/settings/subscription`. Старый URL открывает ту же страницу. Заголовок раздела и breadcrumbs используют один перевод.
- Владелец видит закреплённый тариф, фазу периода, дату окончания при её наличии и доступные измерения usage/limits. При неполной конфигурации показывается состояние проверки. Источник данных — существующие EntitlementResolver и WorkspaceUsage.
- Phase 4A frozen preview используется для расчёта выбранных ресурсов, лимитов и превышений. Владелец видит имена клиентов и агентов и их UUID. Платформенный preview отдаёт метаданные без имён. Сервер сохраняет действующие проверки tenant, owner/PlanAuthority и fresh MFA.
- `/admin/account/security` использует рабочий shell для обычного аккаунта и отдельную страницу для подготовленного владельца, который ещё проходит onboarding. TOTP и резервные коды сгруппированы, Passkeys и способы входа отображаются отдельно. Формы и операции безопасности общие для обоих контекстов.

## Состояние выпуска

Ветка содержит lifecycle, suspension, execution epochs, frozen Basic selection, manual paid, selective restore, rearm и effective capacity. Controlled canary допускает отдельные подготовленные claims только после подтверждения primary PlanAuthority. Compatibility сохраняет Observe, production registration и Trial по умолчанию выключены.

Обе additive migrations и операторские действия перечислены в [Jino checklist](JINO.md). Готовность проверяется по [QA.md](QA.md), где результаты разделены по runtime и артефакту.

## Продолжение backend

Актуальное состояние реализации описано в [backend checkpoint](BACKEND.md). Отдельные [Jino checklist](JINO.md), [карантин и forward fix](ROLLBACK.md) и [canary gates](CANARY.md) применяются после проверки релизного артефакта. Исходники v0.9.16 подтверждают, что возврат старого кода с рабочей базой после suspension не сохраняет действующие барьеры.
