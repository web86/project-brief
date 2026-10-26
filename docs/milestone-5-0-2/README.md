# Milestone 5.0.2 — Integration UI polish

Issue [#4](https://github.com/web86/projects-manager/issues/4), version 0.5.0.2.
Baseline: Milestone 5.0.1, local commit `1116f41`. Branch:
`codex/milestone-5-0-2-ui-polish`.

## Feedback and visible state

Integration actions use the existing global toast service. Token/webhook creation,
editing, project-filter save, enable/disable, secret rotation and deletion report
success; test/manual retry report queued via info; clipboard copy reports success
once per revealed secret. Request, clipboard and history failures use localized
`formatApiError` errors. Field validation, loading, delivery state and attempt errors
remain inline; transient success/error banners have been removed. There is no
autosave. Pending mutations and clipboard operations reject duplicate activation;
history reads do not generate success notifications. A successful mutation followed
by a failed refresh reports only the refresh error and offers a retry; creation
forms reset so retrying the read does not accidentally create a second object.

The token list follows an **active-only** policy: revoked and expired keys are
excluded when evaluating the current list. Expiry is evaluated when the list changes;
server authentication independently checks expiry on every request. The management
API still returns token metadata including revokedAt; its contract is unchanged.
After a successful DELETE the card is removed immediately, with the exact RU toast:

> API-ключ отозван. Для дальнейшей работы создайте новый.

A failed/cancelled DELETE preserves the card. Mutation generations, scoped reads
and deleted-ID guards prevent old list/history results from restoring removed
objects. Reloads use persisted revocation state. Revocation retains the database
row, hash, scopes, timestamps/last use and idempotency records; bearer requests,
including previous idempotent writes, return 401. No secret is returned by list
requests. One-time secrets are cleared on dialog close, workspace change or unmount.

## Webhook deletion

The localized confirmation includes the webhook's name, permanent loss of delivery
and attempt history, and the fact that accepted remote effects cannot be recalled.
Cancellation and request failure preserve both the endpoint and selected history.
Successful deletion removes that endpoint and its selected history; unrelated
selected history is retained. Deleting the endpoint being edited resets its form.

Existing transactional deletion and foreign keys already provide the required
cascade: endpoint → deliveries → attempts and endpoint → project-selection pivot.
Other endpoints, their selections/history and shared immutable event records remain.
The processor and deletion serialize on the endpoint lock: an accepted in-flight
attempt can complete before deletion returns; no later pending attempt is sent for
the deleted endpoint. Deletion cannot undo an event already accepted by a receiver.
No backend production logic or migration is changed in this patch.

## Navigation

The general sidebar's project heading is now the compact arrow-left “All projects”
link to `/admin`; the lower duplicate block is removed. Project/task sidebars keep
their existing single return link. Integrations appears in “My settings” with
account/notifications for the active workspace's owner only. The same components
serve desktop and the mobile drawer. Team uses a distinct `user-check` SVG while
Clients retains `users`, with the existing size/stroke/color behavior.

REST v1, webhook payloads, event names, HMAC, filter semantics, retries, cron and
existing owner/admin/client boundaries are unchanged.

See [verification and screenshots](VERIFICATION.md), [release notes](RELEASE.md)
and [deployment/rollback](PRODUCTION.md). This is a local deliverable; production
installation and actual receiver testing remain with the deployment owner.
