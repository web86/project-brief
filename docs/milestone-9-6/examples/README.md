# Independent demonstration executor

Requirements: Node.js 20.19+ (or 22+). No install step, AI account, LLM key, worker, or Project Brief source dependency.

1. In **Integrations → AI agents and external executors**, create an active agent and explicitly allow a test project. You authorize transfer of assigned task content to this executor.
2. Configure the agent, open API credentials, select profile/read/report/comments scopes and issue a token. Copy it once to your external secret store. Token rotation revokes earlier credentials.
3. Add this agent to the project's **Team**, then select it under **External executors** on a task.
4. In a separate machine/process with the example file, run:

```sh
export PROJECT_BRIEF_URL=https://your-project-brief.example
# Set PROJECT_BRIEF_AGENT_TOKEN through your secret manager or read it silently:
read -r -s PROJECT_BRIEF_AGENT_TOKEN
export PROJECT_BRIEF_AGENT_TOKEN
node agent.mjs
unset PROJECT_BRIEF_AGENT_TOKEN
```

For local QA only, an HTTP loopback origin is accepted. Production requires HTTPS. Never put credentials in a URL, command argument, Git, screenshot, ticket, or chat. Output contains only stable agent/task/report identifiers; task text and tokens are not printed.

The example runs once, reads assigned tasks, computes a description digest locally, posts a comment and a report, then exits. Run again manually or through an external scheduler at a reasonable interval, for example every five minutes. There is no polling process on Project Brief/Jino. A `submitted` task remains in its original Project Brief workflow status for human review. Re-running is safe via per-assignment idempotency keys.

Review the agent-authored result in the task. Disable the agent in workspace integrations. Running the example again must fail with HTTP 401. Removing it only from the project makes that project's tasks disappear; the workspace registration remains.

For production orchestration add bounded retry with jitter, honor `Retry-After`, and persist webhook delivery IDs if using webhooks. This intentionally small demo exits on any HTTP failure. See AGENT-API.md, AGENT-WEBHOOKS.md, and AGENT-SECURITY.md in the release documentation.
