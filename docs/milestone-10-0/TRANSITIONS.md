# Milestone 10.0 — версии, жизненный цикл и восстановление

Статус: архитектура по D4–D10, **исполняемого lifecycle пока нет**.
Схема: [DATABASE.md](DATABASE.md); counts/guards: [ENTITLEMENTS.md](ENTITLEMENTS.md).

## 1. Published versions, rules и rollout

Plan draft → published → archived; опубликованная версия immutable, новая draft
копирует выбранную published. Current pointer — только для новых назначений;
existing подписчики по умолчанию остаются pinned. Price/name не определяют
upgrade: mixed diff может одновременно расширять и уменьшать доступ.

Publish, rollout и копирование rules — отдельные действия. Копирование правил
предыдущей версии формирует draft change set с remap source/target, проверкой
дубликатов, archived/hidden targets, paid requirement, approval и scope/window.
Ничего не включается автоматически; primary видит diff и подтверждает rules revision.
Нет self-transition на ту же version, произвольного DSL или неявной логики по цене.

| Rollout policy          | Поведение                                                                          |
| ----------------------- | ---------------------------------------------------------------------------------- |
| keep_existing (default) | Публикация меняет только предложение новым подписчикам                             |
| schedule_period_end     | Approved changes выбранным workspace, immutable targets; бессрочным отдельная дата |
| apply_now               | Per-workspace application после preview; partial outcome виден                     |

Preview фиксирует actor/authority revision, source/target hashes, settings/registry,
rule revision, frozen target set, usage/seat/reservation/overrides, execution epochs,
suspension/retention последствия. Publish повторяет draft hash check под catalog
lock. Application — workspace lock, текущие UTC boundaries/revisions/fingerprint,
затем atomic change/event; catalog lock здесь не берётся.

Drift с новой потерей, overage или incompatible mapping → needs_review, а не
применение старого согласия. Lease/checkpoint, bounded batches, applied/skipped/
needs_review/failed/cancelled отдельно. Повтор той же команды возвращает outcome,
другой input с прежним key →409. Нет общей транзакции на всю платформу; уже applied
не откатывается из-за отказа следующего item. Отмена batch останавливает remaining,
ранее approved scheduled changes отменяются явно. Manual batch actor проверяется
на текущую primary authority; system expiry не ждёт действующей browser session.

Owner request default deny без enabled rule. Paid target — manual approval primary
до платежей. approval=payment unavailable; redirect/paid=true не proof. Free auto
change допустим только явным rule, с preview, step-up и capacity checks. Archive
закрывает новые назначения, сохраняет подписки/history; replacement global pointers
подтверждаются отдельно, закреплённые периоды не переписываются.

Generic paid-to-paid overage по умолчанию reject до выбора безопасной policy.
Explicit reviewed block_growth сохраняет excess данные/config и запрещает рост;
он не даёт бесконечное право external execution сверх конечного agents.active.max.
Например, Studio → Freelancer с несколькими агентами требует в preview выбора
одной effective active identity; остальные tariff-held, без удаления registrations
или shared key. Для обязательного Basic fallback применяется D9, а не generic
block_growth: все прежние active проекты/non-owner developers приостановлены.

## 2. Глобальные настройки и закрепление версий

Immutable platform policy version содержит Trial duration, Grace duration,
trial entitlement version, free fallback version, eligibility/security termination
и retention policy. Начально: **7 дней / 7 дней / Studio / Basic**.
Primary редактирует draft → preview → publish pointer. Действующие периоды хранят
свою policy version, concrete entitlement/fallback version и UTC dates.

Новая редакция Studio/Basic или policy pointer не меняет действующий Trial/Grace.
Для переноса ongoing periods нужен отдельный explicit preview/reviewed change.
Нельзя удалить/подменить pinned fallback или читать latest Basic по slug на expiry.
Сроки дня — 24 часа по UTC, не локальная полночь и не выполнение cron.

## 3. Первоначальный Trial D5

Eligibility: новый подходящий owner и действительно новый workspace, нет ранее
использованного owner Trial claim/подтверждённого subject claim; существующий/imported
workspace не подходит. Registration t0 атомарно создаёт Trial claim и pinned period:
`ends_at=t0+7*24h`. Claim scoped владельцем, не workspace, и не переносится на новые
workspace. Existing user, уже использовавший Trial, получает только разрешённый
обычный free assignment, без повторного Trial; D6 legacy untouched.

До email verification Trial pending и premium недоступен; core onboarding/verification
доступны, active project workflow нового коммерческого workspace ещё не начинается. Verified email активирует **оставшееся** время до того же ends_at, не
перезапускает семь дней. Смена email, удаление/создание workspace, owner transfer
или повтор регистрации не сбрасывают claim. Anti-abuse дополняет существующие
registration/IP/mail throttles; не вводить fingerprint как authentication principal.
Root может разбирать отказ metadata-only; публичного reset/restart Trial API нет.

| UTC условие                                                   | Effective entitlement                                                             |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| t0<=now<ends_at, eligibility/verified email подтверждены      | Полная pinned Studio version, в рамках included seats и safety/technical limits   |
| До verification в том же интервале                            | Core onboarding, Trial pending; нет premium execution или active project workflow |
| now>=ends_at и paid continuation не активирована              | Pinned Basic, без дополнительного Grace, immediate suspension cycle               |
| Valid paid continuation уже одобрена и начинается на boundary | Paid version по approved change, без искусственного Basic gap                     |

Конец Trial не превращается в paid service end и не выдаёт следующие семь дней
Grace. Семь дней доступа не означают отключение текущих server quotas.

## 4. Paid service → Grace → Basic

Paid service period назначает primary вручную с dates/reason/approval; это не
имитация платежа. Начало/конец/Grace/fallback закреплены policy. Future payments
позднее смогут вызывать тот же executor после проверенного provider event.

| UTC условие                                              | Effective state                                           |
| -------------------------------------------------------- | --------------------------------------------------------- |
| now<service_ends_at                                      | Paid entitlements                                         |
| service_ends_at<=now<grace_ends_at                       | Grace, прежняя paid version/features; уведомление/renewal |
| now>=grace_ends_at, нет своевременного paid continuation | Basic + suspension, paid execution denied                 |

Renewal до expiry может заранее утвердить следующий period с начала предыдущего
end, без quota reset. Renewal в Grace прекращает её с новой approved даты; нельзя
backdate факт оплаты/доступа. Если уже наступил Basic boundary, сначала catch-up
и suspension, затем новый paid period и отдельное подтверждение restore.

Досрочное security termination имеет отдельный reason/policy: немедленный deny
premium, без автоматического Grace; scope прекращения, owner recovery/экспорт и
notification задаются явно. Никакого автоматического security bypass из-за тарифа;
законный account security lock остаётся сильнее core-access гарантии.

## 5. Точное время и catch-up без cron

SubscriptionClock проверяет authoritative UTC now и все прошедшие boundaries на
каждой protected operation, включая bearer read/write/replay, run claim/result,
SSO consume/session и webhook pre-send. Cache validUntil не выходит за ближайшую
boundary; запрос, начатый до boundary, не получает безусловное разрешение на поздний
side effect. Проверка повторяется в критической секции/перед send.

Под workspace lock исполнитель применяет переходы в хронологическом порядке.
При своевременно approved непрерывном paid continuation он имеет приоритет перед
fallback. Позднее решение нельзя backdate, чтобы скрыть уже случившийся Basic gap.
Даже если cron не работал между expiry и новым paid approval, suspension/epoch
сначала фиксируются; новая подписка не оживляет старые поручения/events.

Первое применение expiry делает O(1) запись current period/revision, suspension
cycle, feature-group epoch barriers и notification outbox. Все 100 active проектов
логически остановлены этим marker сразу; materialization rows/terminal statuses
идёт bounded batch позднее. Public write/read adapters используют effective state,
а не ждут физического batch. Если переход не удалось сохранить, premium/growth
fail closed; owner core read/export/security сохраняются при доступной auth/БД.

Cron только materializes/reconciles, отменяет stale work, отправляет deduped notices
и обслуживает retention. Он не продлевает право и не выбирает случайный Basic version.
Управленческий preview не может остановить обязательный ранее утверждённый expiry.

## 6. Автоматический downgrade D9

1. Записать pinned fallback period, событие/причину и suspension cycle.
2. Удержать все прежние active проекты, не меняя projects.status/status_before_trash.
   Inactive/trash остаются такими же; новые базовые действия проходят capacity guard.
3. Удержать всех non-owner human developers; owner остаётся active, seats=1.
   Не менять roles, project assignments или memberships; другие workspace человека
   и его личный security/account доступ не затрагиваются.
4. Закрыть agents, full/agent API, webhooks, SSO, FormWatch и иные paid-only features.
   Existing secrets/hash/scopes/bindings/grants/settings не удалять/не расширять.
5. Поднять relevant execution epochs. Старые queued/running runs не могут получить
   start/context/file/result; старые deliveries/retries не могут отправляться.
   Batch ставит cancelled с subscription reason, сохраняя context/messages/history.
6. Уведомить owner через deduped outbox и in-app status: что остановлено, почему,
   как выбрать Basic project, вернуть paid service, выгрузить/удалить данные.

Не вызывать AgentController::disable или массовый membership status update ради
тарифа: текущие методы отзыва меняют credentials/links/assignments и не обратимы
как tariff overlay. Настоящий manual revoke сохраняет свою прежнюю семантику.

Owner не теряет вход, MFA/recovery, чтение разрешённой истории, безопасный экспорт,
subscription/settings и управление приостановкой/удалением. Owner data-export
coverage должна включать разрешённую private историю/файлы приостановленных
ресурсов; обычный client PDF не считается полной выгрузкой владельца. Для неё
нужен owner-only adapter поверх существующих private file/stream checks, без
ослабления public export filters или автоматической публикации originals. Non-owner suspended
участник не продолжает работу через старую session/live sync/download; сохраняет
свой account/security и доступ в другие разрешённые workspace.

### Выбор одного проекта на Basic

Owner получает preview выбранного project UUID, текущего effective active count
и distinct client identities. Если <=3 клиентов, подтверждает activation/release.
Если >3, выбирает не более трёх identities для active participation; остальным
накладывается tariff hold, без удаления строк, link history, reports или files.

Одна транзакция проверяет owner/fresh proof/revisions, project capacity=1 и client
union<=3, сохраняет participation holds и project release. Другой active проект
нельзя молча заменить: сначала явное archive/suspend прежнего или подтверждённый
атомарный switch с preview. Две вкладки не активируют два проекта. Magic Links
оставленных клиентов работают по прежней auth; приостановленные участия доступа
не получают. Архив не позволяет продолжить полный task/comment/upload workflow.

## 7. Восстановление paid доступа

Новый paid period сам по себе не снимает suspension и не replays events.
Owner preview показывает выбранные projects/members/clients/agents, limits,
reserved invites, прежние/текущие permissions и integration credentials metadata.
Подтверждение scoped конкретными UUID, target revision и current grants.

Release не меняет baseline statuses: manual inactive/revoked/deleted/expired не
восстанавливаются. Extra seats из прошлого коммерческого периода не переносятся
без нового explicit approved grant. Лишние ресурсы остаются held; partial restore
показывает applied/needs_review/failed, не объявляется полным успехом.

Интеграции подтверждаются отдельно: token/source/endpoint/agent должен оставаться
valid, source/project grants и shared link generation проверяются заново. Secret
не раскрывается повторно. Включение webhook не доставляет старые cancelled события;
новый агентский запуск требует **нового поручения/submission**, а не resubmit старого
runId. Не добавлять epoch в logical quota/idempotency key так, чтобы это сбросило budget.
Исторические completed сообщения/context остаются читаемыми при текущем
разрешённом Agent API доступе к восстановленному active проекту; это не разрешает
старый queued/running run. До materialization его API state уже projected cancelled,
не misleading queued. Future run может включать разрешённую старую conversation
в новый frozen context после явного human поручения.
До replay старого execution success проверяются epoch и текущий run state; stale
→ typed conflict, без нового run/result/charge. Human team-only history сохраняется.

## 8. Retention и удаление D10

| Класс            | Норма                                                                                                                                 |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| active_free      | Обычный действующий Basic project не удаляется из-за прошлого Trial/paid expiry; коммерческое expiry само не purge condition          |
| suspended_excess | Настраиваемые retention days, warnings, applicability и keep/review/purge action; только реально приостановленные excess/paid ресурсы |
| closed_abandoned | Отдельный inactivity/closure criterion и срок; отсутствие платежа не равно abandoned                                                  |
| owner_requested  | Explicit scope/impact preview, fresh step-up, confirmation и durable deletion job                                                     |

Retention policy закрепляется на cycle/job; изменение global pointer относится к
новым назначениям. Сокращение срока для прежнего cohort требует отдельного preview,
confirmation и новых предупреждений, без retroactive немедленного purge.

Сроки/предупреждения не утверждены; not_configured означает keep/review, не мгновенное
удаление. Automatic destructive purge **disabled** до проверенной staging процедуры
и code availability gate. UI setting сам не может перескочить этот gate.

Автоматическая процедура: deduped warning → cooling period → current eligibility/
policy/workspace revision recheck → исключить active resources/legal hold/new paid
restore → зафиксировать frozen deletion scope → остановить background execution →
удалять данные/private files по durable journal → verify absence → metadata audit.
Перед каждым destructive item повторять applicability; найденный active ресурс
исключить/перевести job в review, а не удалить ради окончания batch.

Owner deletion: существующие owner/step-up checks, точная typed confirmation,
описание irreversible DB/file последствий и backup-retention. Request не удаляет
user account/другие workspace. До начала purge отмена возможна; после начала —
только остановка remaining, без обещания автоматического восстановления удалённого.
Private file cleanup использует существующий durable journal с расширенным inventory;
partial failure повторяем безопасно. Audit не содержит private paths/контент.

Backup copies не объявляются немедленно уничтоженными вместе с active storage.
Фиксируются сроки/limitations backup-retention и запрет обычного restore tombstoned
ресурсов; конкретный backup process должен быть подтверждён отдельно. Истечение
Trial/Grace само не запускает destructive command.

## 9. Overrides, scopes и будущие платежи

Override typed и ограниченный по времени, с reason/primary approval; не меняет
paid eligibility, seat policy, scope/window и safety. Expiry override — отдельная
возможная потеря, включается в preview/UTC checks. Совместимые overrides переносятся
только по явно показанной policy; несовместимые → needs_review. Scope/window
изменение переносит consumption/reservations по D12, не превращает usage в ноль.

Будущий payment adapter проверит signature/provider account/amount/currency/event
idempotency, затем вызовет тот же approved change executor. Out-of-order events не
переписывают более новое manual решение. Billing provider tables/checkout/refund/
proration в текущий архитектурный этап и первую реализацию не включены.
