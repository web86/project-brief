# Agent webhook contract

Optional HTTPS endpoint configured by the owner on the agent. A separate per-recipient `whsec_` 32-byte random secret is returned once when the URL is first set or changed; stored encrypted using APP_KEY. Clear and save a new URL to rotate this recipient's signing secret (old endpoint/deliveries are removed). API token and signing secret are different credentials. An agent with no endpoint can use REST directly.

Events: agent.task.assigned, agent.task.updated, agent.task.unassigned, agent.task.cancelled. Updates follow task public-description/status changes; private notes/financial changes do not emit agent events. Deleting an assigned task cancels its assignment. Disabling an agent cancels all its assignments.

Payload has version=1, id (event UUID), type, occurredAt (UTC ISO 8601), workspaceId, projectId, taskId, agentId, assignmentId, state. No task descriptions, titles, attachments, client contacts, secrets or project snapshots. Fetch authorized details through Agent API.

Existing transactional webhook outbox is reused. Assignment commits without depending on external delivery; no network call occurs in the assignment HTTP request. Existing `php artisan integrations:webhooks` cron with bounded batch/runtime performs delivery. No additional daemon, worker or frequent polling on Jino.

Headers:

- X-ProjectBrief-Event: event type
- X-ProjectBrief-Delivery: delivery UUID (deduplicate and persist on recipient)
- X-ProjectBrief-Timestamp: Unix seconds
- X-ProjectBrief-Signature: `sha256=` + lowercase HMAC-SHA256 of `timestamp + "." + raw body`, using the recipient's signing secret

Verify the raw bytes before JSON parsing, compare signatures in constant time, reject timestamps outside a five-minute window, and deduplicate delivery IDs. Return 2xx only after durably accepting the event. A delivery retains the same event/body/delivery UUID across retries, with a fresh timestamp/signature. Receiver processing must be idempotent.

Six maximum attempts; bounded backoff 30,60,120,240,480,960 seconds (capped at an hour). Retry 429/5xx and transport failures; other non-2xx are terminal. Existing delivery and attempt journals record status, safe error code, duration and HTTP status. Response bodies are not logged. Owner card displays latest delivery status/error. Existing signed transport enforces TLS verification, no redirects/proxy, 3-second connection/8-second request deadlines, 16 KiB response limit and public DNS validation pinned into cURL. URL validation is repeated on every attempt and rejects private/reserved/rebinding destinations.

Agent endpoints receive only their own agent/task events for a currently accessible assignment generation, never generic project or client events. Access is checked at enqueue and immediately before delivery under the same workspace revocation lock. Already queued events are cancelled if a project, task, assignment or agent is revoked. **An unassigned/cancelled event is therefore not delivered to the former agent once its task access is gone.** Owner-managed general endpoints may subscribe to these minimal events to track cancellation, while the former agent learns revocation from 404/401 or an absent task in its next scheduled read. This intentionally prioritizes the requirement not to notify an agent after access is revoked. Duplicate/out-of-order delivery is possible; always re-read authorized task state.
