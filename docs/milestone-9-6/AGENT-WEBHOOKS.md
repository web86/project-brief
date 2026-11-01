# Agent webhook contract

The owner manages independent receivers in Integrations → Webhooks, using the
existing owner session and fresh MFA. A receiver can be created before any agent.
Choose Integration / Agents, name, HTTPS URL, events, all/selected projects and
activity. The category only selects subscriptions; no separate type/table exists.
New rows always have `external_agent_id = NULL`; the API rejects `agentId`.
One receiver may receive notifications from multiple agents. Mixed ordinary
integration/agent subscriptions remain supported and survive editing.

A separate per-recipient `whsec_` 32-byte random secret is returned once and stored
encrypted with APP_KEY. Rotate through the existing webhook action. API Bearer
and signing secret are different credentials. Webhook notifications grant no
Agent API rights; operations still require the correct bearer, linked agent ID,
scopes and all existing access checks. REST execution also works without webhooks.
Agent creation/edit does not accept a nonempty `webhookUrl` or alter receivers.

Agent choices match `AgentAccess::EVENTS`: agent.task.assigned, agent.task.updated,
agent.task.unassigned, agent.task.cancelled, agent.run.requested,
agent.message.created, agent.run.cancelled. Task updates follow public-description/
status changes; private notes/financial changes do not emit agent events. Deleting
an assigned task cancels its assignment. Disabling an agent cancels its assignments.

Payload has version=1, id (event UUID), type, occurredAt (UTC ISO 8601), workspaceId, projectId, taskId, agentId, assignmentId, state. No task descriptions, titles, attachments, client contacts, secrets or project snapshots. Fetch authorized details through Agent API.

Existing transactional webhook outbox is reused. Assignment commits without depending on external delivery; no network call occurs in the assignment HTTP request. Existing `php artisan integrations:webhooks` cron with bounded batch/runtime performs delivery. No additional daemon, worker or frequent polling on Jino.

Headers:

- X-ProjectBrief-Event: event type
- X-ProjectBrief-Delivery: delivery UUID (deduplicate and persist on recipient)
- X-ProjectBrief-Timestamp: Unix seconds
- X-ProjectBrief-Signature: `sha256=` + lowercase HMAC-SHA256 of `timestamp + "." + raw body`, using the recipient's signing secret

Verify the raw bytes before JSON parsing, compare signatures in constant time, reject timestamps outside a five-minute window, and deduplicate delivery IDs. Return 2xx only after durably accepting the event. A delivery retains the same event/body/delivery UUID across retries, with a fresh timestamp/signature. Receiver processing must be idempotent.

Six maximum attempts; bounded backoff 30,60,120,240,480,960 seconds (capped at an hour). Retry 429/5xx and transport failures; other non-2xx are terminal. Existing delivery and attempt journals record status, safe error code, duration and HTTP status. Response bodies are not logged. Owner card displays latest delivery status/error. Existing signed transport enforces TLS verification, no redirects/proxy, 3-second connection/8-second request deadlines, 16 KiB response limit and public DNS validation pinned into cURL. URL validation is repeated on every attempt and rejects private/reserved/rebinding destinations.

Independent receivers use existing event/project filters. Real agent payloads carry
the source `agentId`, not an endpoint-bound identity. These are minimal owner-managed
notifications; a recipient must re-read details through authorized APIs.

Existing personal endpoints retain their own-agent/current-assignment access
checks at enqueue and immediately before delivery under the workspace revocation
lock. They are not migrated or recreated. Pending deliveries are cancelled on
revocation. An unassigned/cancelled event is not delivered to the former personal
agent after task access is gone. Independent subscriptions can receive those
minimal cancellation events. Duplicate/out-of-order delivery is possible; always
re-read authorized state. Removing a project grant/team membership still cancels
pending notifications concerning that agent/project through the existing flow.
