# Milestone 10.0 — проверка проекта и будущая приёмка

Статус: документационный этап по D1–D12; baseline **v0.9.10 hotfix**.
Ни один новый runtime/security/concurrency тест 10.0 не считается выполненным:
реализации пока нет. QA предыдущего v0.9.10 не переносится на эти guards.

## 1. Проверки текущей документации

Проверить наличие ровно восьми требуемых Markdown-файлов, local links, JSON
examples, Prettier/whitespace, согласованность registry names/schema/API/scenarios.
Сверить все D1–D12 с матрицей ниже и отдельно C1–C14/противоречия/R1–R12 в
ARCHITECTURE. Production files/git HEAD остаются исходными; commit/push/build не
выполняются. Snapshot предыдущих восьми файлов сохранён вне репозитория для diff.

| Решение | Где спроектировано                                          | Приёмочный сценарий                                                                        |
| ------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| D1      | ENTITLEMENTS counts/seats, DATABASE canonical policy        | Owner included, people distinct, agents excluded, 1/1/10..50                               |
| D2      | ENTITLEMENTS identities, DATABASE aliases/link              | Stable cross-project UUID, verified provenance/email fallback/ambiguity без auth merge     |
| D3      | ENTITLEMENTS effective active, TRANSITIONS Basic activation | Inactive/suspended исключены; active restore проверяет projects+clients; archive read-only |
| D4      | DATABASE versions/rules/previews, TRANSITIONS rollout       | Immutable/pinned, copy rules preview, partial/resume/audit                                 |
| D5      | TRANSITIONS Trial/Grace/clock, DATABASE policy/claims       | 7-day Trial без Grace, paid 7-day Grace, UTC/no cron, anti-repeat                          |
| D6      | MIGRATION compatibility/Observe                             | Никакого automatic Basic/Trial existing workspace                                          |
| D7      | Seats/reservations в ENTITLEMENTS/DATABASE, UI              | Included+approved extra, hard max, holds/accept/recount                                    |
| D8      | Registry matrix/paid gate, API adapters                     | Basic deny premium; Freelancer agent-only API; Studio unlimited tariff agents              |
| D9      | Cycle/epochs/restore в DATABASE/TRANSITIONS/API/UI          | 100 проектов paused, owner core, select 1/3, no stale execution/rearm surprise             |
| D10     | Retention в TRANSITIONS/DATABASE/API/UI/MIGRATION           | Active-free protection, owner step-up deletion, automatic purge off                        |
| D11     | Registry/Ledger в ENTITLEMENTS                              | Run vs AI consumption, durable dedupe/uncertain, FormWatch unavailable                     |
| D12     | Scope definitions/preview/mapping                           | Unsupported scopes rejected; consumption/reservations не обнуляются                        |

Первичный доступ/authority проверяется отдельно от D1 developer permissions.
Документировать реально выполненные doc checks после финальной сверки. Ни preview,
ни Markdown assertion не доказывают, что proposed runtime protocol уже работает.

## 2. Unit/domain и policy tests будущей реализации

- Registry: unknown key/type/scope, numeric string, negative/fraction/overflow,
  enum order, explicit unlimited, missing available value safe default, unavailable
  adapter, derived developers.max input и paid feature на free/override rejected.
- Canonical developer_seats: нет второго stored maximum; Basic/Freelancer=1,
  Studio extra=0/2/40 → capacity 10/12/50, >50 denied, extra без primary rejected;
  actor primary не становится workspace developer. Unlimited compatibility typed.
- Versions/policies: editing creates new immutable version, current pointer не
  сдвигает subscribers, Trial/fallback/policy pinned, invalid/archived targets,
  publish retry/hash/revision, rule copy remap/conflict/disabled/manual approval.
- Overrides: interval overlap, exact expiry, compatible carry explicit, paid-only
  cannot escape eligibility, seat/type/scope/window/safety override rejected.
- Deltas: positive blocked, zero/reduce allowed только в разрешённой операции,
  multi-key all-or-nothing, preview counts и authoritative providers совпадают.
- Snapshot: validUntil не за boundary, fresh DB check в critical section, no
  query per feature/N+1, registry unavailable и missing subscription fail policy.

## 3. Trial / Grace / UTC / eligibility

Проверять `boundary−1µs`, точное equality и `boundary+1µs` по серверному UTC, не
только travel на целый день. Local timezone/DST/leap/month end не меняют 7×24h.

| Сценарий                                       | Ожидается                                                                                      |
| ---------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Новый eligible owner+workspace, verified email | Trial pinned Studio, starts registration t0, end=t0+7d, safety limits действуют                |
| Unverified email / позднее verification        | Premium deny; onboarding доступен; verification не продлевает исходный end                     |
| Trial end без paid continuation                | Basic сразу, **нет дополнительного Grace**, suspension/epoch barrier                           |
| Paid end без renewal                           | Grace ровно 7d прежних paid features, затем Basic                                              |
| Grace=другое approved policy значение          | Используется pinned duration; редактирование pointer не меняет ongoing interval                |
| Timely approved paid continuation на boundary  | Нет лишнего Basic gap; target diff/capacity всё равно проверены                                |
| Late renewal после Basic при выключенном cron  | Сначала recorded gap/suspension/epoch; новый paid не resurrect old work                        |
| Security termination                           | Separate no-Grace policy, audit/reason, existing security restrictions не обходятся            |
| Два новых workspace одного владельца           | Один owner Trial claim, не два; first-user/imported workspace legacy exception                 |
| Email change/delete/re-register/owner transfer | Claim не сбрасывается; verified subject handling/recovery без fake-account merge               |
| Массовые registrations                         | Existing anti-abuse/email confirmation + durable claim, без содержимого/секретов в diagnostics |
| Existing workspace/Observe                     | Нет Trial/Basic/нового expiry автоматически, counts only                                       |

Cron полностью остановлен на весь Trial/Grace и до последующего paid approval:
каждый relevant read/write/job сам соблюдает deadline. Проверить boundary внутри
долгого запроса: guard в начале HTTP request не разрешает поздний новый side effect.

## 4. Human seats, clients и activity regression

- Owner included; admin без assignments, один человек в нескольких проектах,
  superadmin без double count, inactive/reactivate, другой workspace независим.
  Project-team removal не удаляет workspace member; roles/DEFAULTS сохранены.
- Pending invitations union: same email/multiple invitations/projects, known User
  delta=0, accept vs revoke/expiry/edit/resend, account email change, old pending
  holds при backfill, suspended hold после Basic не позволяет acceptance.
- Clients: manual/provisioned contact, nullable workspace_client_id, current SSO
  source bindings, same valid identity на пяти проектах=1, email case/space fallback,
  no-data stable UUID, ambiguity/conservative separation, no cross-workspace/source
  ID trust, distinct verified people с общей mailbox не merge. Новые token/session/
  SSO права не появляются из accounting linkage; email edit не освобождает ложные seats.
- Effective active predicate: inactive/trash/tariff-suspended проекты/клиенты
  исключены; only-contact directory rows не расходуют seats; restored/activated
  project reintroduces client union. Проект с initial client проверяет обе capacity.
- Archive bypass: human/client task/comment/upload/status/approval routes, direct
  API, old session/live sync и background jobs не продолжают full workflow.
  Owner history/export/security и safe reduce/archive/delete сохраняют permissions;
  owner data-export включает разрешённую private историю/files held ресурсов,
  public/client export filters не ослабляются.
- Retained privacy: client не видит private agent originals/messages/context/files;
  human selected team/client copy отдельна. Past output publication не новый run,
  но требует current project activity и прежних permissions.

## 5. Downgrade, Basic activation, paid restore

Синтетический dataset: 100 active проектов, несколько non-owner developers,
несколько агентов/общих и individual keys, pending invitations, >3 unique clients,
queued/running/completed runs, mixed/independent/personal webhook fixtures.

1. Снять полные fingerprints IDs/base statuses/roles/memberships/grants/scopes/
   hashes/links/context/history/private files/endpoint secret versions.
2. Exact Trial/Grace boundary без cron: все прежние active проекты и non-owner
   participation логически suspended **сразу**, owner=единственный Basic developer.
   Read/export/subscription/recovery owner доступны, premium denied.
3. До физической materialization rows вызвать старые API/claims/results/deliveries:
   barrier работает; bulk 100 status/role rewrites не требуются, fingerprints целы.
4. Basic choose project с <=3 identities: один active. Если >3 — explicit retained
   identity selection и holds остальных без удаления. Atomic switch и stale preview;
   нельзя активировать второй проект или начать full работу в архивном.
5. Новый paid approved period: прежние suspended ресурсы не восстанавливаются сами.
   Selective restore по limits/extra approval; manual inactive/revoked/deleted не
   меняются. Partial outcome и blocked items видны.
6. Integration rearm отдельным confirmation/current authorization: expired/revoked
   key, removed grant, different link generation и disabled source не получают доступ.
   Старый queued run/event/retry terminal; нужен новый submission/new event.
7. Проверить все fingerprints: data/history/authorship сохранены; changed fields
   ограничены новой entitlement metadata/явными user действиями, без scopes widening.

## 6. Полный аудит Agent API и keys v0.9.10

Покрыть существующие feature suites, расширяя конкретные assertions, а не заменяя
их общим «получен 403». Обязательные paths:

- Unified type=agent shared и individual issuance; legacy /agents/{id}/tokens,
  replace, bind/same-generation/replace/detach, API key without agent, one key
  several agents. Freelancer допускает только agent namespace/capabilities,
  ordinary integration routes и full API denied; Studio→Freelancer с несколькими
  active identities требует explicit выбора одной, остальные held без удаления; Studio unlimited tariff agents
  не bypass технических rate/size/capacity limits.
- IntegrationTokens::create прямые callers, updateToken provisioning flag,
  нового credential+revoke rotation, source issuance, webhook signing rotation.
  Нет fictional unified token rotate route. List/revoke/cancel остаются safe.
- AuthenticateIntegration, AgentAccess и IntegrationWrites: valid key/identity/
  membership/grant/assignment/scope не заменяют entitlement. Check **до** success
  replay, в том числе старые credentials после paid disable и restoration gap.
- Initial assignment (deferRun true/false), first instruction, follow-up,
  multipart private files, submissionId retry/conflict, queued→running claim,
  old runId vs current assignment, result/report/message/file writes after boundary.
  Forbidden old replay не создаёт run/comment/file/history/quota spend.
- Context/history/message/attachment reads и downloads: paid feature/current auth
  для executor, epoch для stale execution; completed history сохраняется по правам,
  human core history отдельно. API old queued/running state projected cancelled
  до поздней batch materialization. Claimed UUID не доказательство отдельного
  holder одного shared secret; текущая trust-boundary модель сохраняется.
- Внешний deterministic executor: first/follow-up result, revoke during execution,
  late result rejected после boundary; реальный model call не нужен основной suite.
  Уже запущенный внешний LLM не объявлять физически остановленным сервером.

## 7. Webhooks, SSO и прочие adapters

- Independent webhook до любой agent registration, external_agent_id=NULL,
  события двух identities с правильным agentId, all 7 events/mixed subscriptions,
  project filter и edit preservation. Tariff gate не проверяется только для personal.
- Enqueue/test/retry/rotate/pre-send, queued event до downgrade, old failed delivery
  после restore: no stale send/replay; raw HMAC/TLS/SSRF/public DNS/retries/lease
  и audit сохраняются. Webhook secret/notification не даёт Agent API rights.
- Boundary vs actual send: current check под lock; уже отправленный пакет может
  завершиться, это явно учтённая граница, не ложная atomic revoke гарантия.
- SSO issue/consume/sessionUsable на обеих boundaries; source/origin/grant/TTL/
  revoked token сохраняются. clients:sso-only credential при full API=false
  работает только при sso.enabled; обычный Magic Link/SPA/security не premium API.
- Branding save и effective portal/email/export rendering, document workspace vs
  session workspace, сохранение assets; exact field allowlist до enforcement.
- Встроенный AI assistant quota adapter отдельно от external agent runs;
  existing minute/day safety caps и global kill switch остаются. FormWatch false
  availability не выполняет реальный или фиктивно успешный вызов.

## 8. MariaDB concurrency — обязательный future gate

Независимые соединения/processes с barrier, current InnoDB isolation и итоговыми
DB/file/event counts. SQLite не доказывает locks. Проверить stale consistent read
до workspace mutex в existing outer transaction, не COUNT из cache.

| Гонка                                                 | Инвариант                                                                       |
| ----------------------------------------------------- | ------------------------------------------------------------------------------- |
| 20 create/activate/restore/provision с одним slot     | Ровно один growth, project+client delta атомарны, orphan нет                    |
| Invite/accept/reactivate/email edit с одним seat      | Union occupied<=capacity, один User, holds не теряются                          |
| Client add/edit/alias/backfill + project activate     | Stable identity mapping, unique active union, ambiguity без undercount          |
| Два Trial claims одного owner/verified subject        | Один claim и period; retry не начинает новую неделю                             |
| Trial/Grace boundary vs request/renew/cron            | Один applied fallback/cycle/event, no omitted Basic gap                         |
| Basic activation двух проектов из разных tabs         | Ровно один active project и <=3 clients                                         |
| Restore vs revoke/key replacement/manual archive      | Recheck baseline/current permission, revoked/inactive не rearm                  |
| Agent start/result/idempotent replay vs downgrade     | Epoch/activity/auth проверены до outcome, новых writes после cutoff нет         |
| Independent webhook send vs expiry/revoke             | Согласованный workspace/lease/endpoint order, pre-send rule соблюдён            |
| Publish/edit/copy rules, два rollout workers + crash  | Immutable hash, revision conflicts, per-item apply один раз, resumable partial  |
| Primary revoke/recovery vs approval/batch             | No privilege bypass; unfinished manual action reviewed, system expiry действует |
| Retention purge vs owner activate/renew/delete cancel | Active ресурсы исключены, scope пересмотрен, no destructive stale preview       |
| Deadlock/timeout/retry                                | Bounded repeat, no duplicate mail/provider call/ledger/event                    |

После корректности — lock duration/p95/query/EXPLAIN; Jino thresholds отдельно.

## 9. Quota Ledger и retention/destruction

Quota: reserve/commit/release/uncertain, first bucket race, signed-safe amount,
UTC windows, repeat key/new payload, crash до/после provider call/commit, no TTL
release при unknown outcome, reconciliation, plan/version/renew/epoch не обнуляют
consumption. Scope/window migration переносит consumed/reserved/uncertain или
ждёт согласованной boundary. Storage trash/suspension не освобождают bytes;
реальное file delete retry освобождает ровно один раз. FormWatch trusted event ID
и adapter нужны до availability=true; численные quotas отдельно утверждаются.

Retention: independent active_free/suspended_excess/closed_abandoned/owner_requested
policy, warning dedupe и cooling period, policy drift/current activity/legal hold,
paid restore в последний момент, owner step-up/revision/typed confirmation,
partial file cleanup retry/absence verification, backup-retention/tombstone.
Обычный active free project не удаляется за прежний Trial/paid expiry. Нельзя
удалить чужой workspace/глобальный User/другие проекты вне confirmed scope.
Automatic destructive purge remains off до полной staging репетиции и gate;
metadata audit без private paths/контента. До gate UI publish не включает purge.

## 10. Security, UI, upgrade и критерий завершения

Security: other platform_admin/legacy role/owner/project superadmin/agent не
получают primary management, no automatic bootstrap, MFA/fresh proof/CSRF/revoked
sessions/authority revision, IDOR по version/workspace/preview/change/alias/retention
job, bounded JSON/plain-text XSS/safe sorts, mandatory audit failure rollback.
Никаких auth headers, keys, Magic Link URLs, prompts, files, emails или private
project content в platform events/preview/telemetry. Tenant/object masking сохранён.

Frontend: canonical seats без editable дубля, global pinned policy UI, clear
Trial-vs-Grace notices, Basic project/client selection, suspended team/owner core,
explicit restore/rearm, retention consequences, stale snapshot/deadline/workspace
switch, pending paid/extra requests, partial batches и copied rules confirmation.
Primary RU/EN, user flows RU/EN/TR/zh-CN; 390px/desktop, обе темы/system, contrast,
keyboard/focus/labels/live progress/long translations, no page overflow/console errors.

Upgrade: populated v0.9.10 → additive schema → resumable inventory/Observe → canary;
fresh install отдельно. Verify shared generations/private history/independent
webhook safety. Rollback к v0.9.10 только после materialization/operational pause,
re-enable catch-up/reconcile, без destructive schema rollback или quota reset.
PHP 8.4 + full existing suites + canonical ZIP/manifest/secret/extracted runtime
потребуются при будущей реализации, не запускаются ради Markdown.

Архитектурный этап завершён после согласованной сверки восьми документов и явного
списка изменений/противоречий/рисков. Он **не** разрешает implementation, commit,
push или deployment. Начало реализации — отдельное поручение пользователя.

### Фактическая проверка документации

Фактически проверено **02.11.2026**: все 8 документов обновлены; 17 локальных
ссылок разрешаются, 2 fenced JSON-примера валидны, D1–D12 представлены в обеих
матрицах. Сверены 14 change entries, 12 risk entries и существование 34 ссылок
на исходные PHP-файлы. Initial limits, canonical seat JSON/API mapping и разделение
agent/full API проверены между документами. Prettier и whitespace проходят.

Ручная смысловая сверка: Trial без Grace; paid Grace с pinned fallback; inverse
archive/client counts; no-cron catch-up; сохранение исходных roles/statuses;
shared-key/replay/independent-webhook guards; owner core access; retention gate;
primary authority отдельно от platform_admin. Численные quota budgets до approval
не включаются и не превращаются в implicit limit=0.

Tracked production diff = 0, git HEAD остался 7ec07e6; изменены только восемь
исходных untracked architecture файлов. Полный diff к сохранённой предыдущей
редакции находится в `/tmp/project-brief-m10-d1-d12.diff`, doc-check metadata —
`/tmp/project-brief-m10-doc-check.json`. Это локальные review artifacts, не часть
runtime или свидетельство реализации. Runtime-тесты Milestone 10.0, commit,
push, release build и deployment в текущем задании не выполнялись.
