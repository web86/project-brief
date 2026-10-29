# Milestone 8.0 — Integration Foundation (0.8.0)

Base: `codex/live-sync-ui-polish`, 0.7.8, commit `5918f51`. Work branch: `codex/milestone-8-0-integration-foundation`. Local implementation; publication and Jino deployment require separate authorization.

- [API contract and examples](API.md) · [OpenAPI](openapi.json)
- [Server-side PHP example for WordPress/MODX](provision-project.php)
- [Deployment, migration and rollback](PRODUCTION.md)
- [Verification](TESTS.md) · [Security report and dependency audit](SECURITY.md)

## Architecture audit and decisions

The existing v1 API is stateless: `AuthenticateIntegration` verifies hashed `pb_*` bearer tokens and limits requests per IP/workspace. `IntegrationWrites` serializes writes with the workspace lock and stores replay responses in `integration_requests`. The existing project creation service, token management, owner-only integrations UI, transactional webhook outbox, and `integrations:webhooks` processor are reused. Legacy `/api/v1/projects` and its `sourceSystem` external links keep their contract and request hashes.

Before this milestone, `project_clients` represented a client **inside one project**. `ProjectAccessToken`, `ClientInvitations`, `AccessLinkController`, and `EnsureClientProjectAccess` bind a verified session to that membership and token. They do not provide a global client account. Developer users/workspace memberships are a separate existing model; provisioning never creates a developer user or changes its MFA/passkeys.

An additive `workspace_clients` contact identity now holds normalized email, name and optional company/website. Its UUID is `clientId`. A new `project_clients` row remains the access membership (`projectClientId`) for each project. Contacts are reused only inside the token workspace. Matching a legacy project's email may supply the contact's existing name; legacy memberships, links and sessions remain unchanged. Reusing a contact never copies authentication, activates an old membership, updates another project's client, or joins workspaces.

The operation uses one transaction for contact, project, membership, durable external-ID binding, replay record and outgoing event records. Email runs after this transaction commits; webhook HTTP delivery remains in the existing processor after commit. A delivery failure leaves a recoverable project and explicit invitation state. `ProjectProvisioning` does not depend on an authentication method for creating records.

## Authentication boundary and future policies

`projectUrl` is `/project/{uuid}` under configured `FRONTEND_URL`, without a bearer credential. A valid client session opens only its own project; another active client session is refused. An authenticated developer is routed through the existing admin project authorization and account-security checks. A guest requests a short-lived email link through the existing invitation/token/mail services and returns to the canonical project URL.

The public login endpoint sends only to an existing active membership. It creates neither contacts nor access memberships and never revokes an existing verified session. Unknown project/email, inactive project/member and mail failure share a generic accepted response. CSRF, per-IP and hashed mailbox/project throttles apply. Deactivating the project client is the way to revoke membership; revoking one link revokes that link/session immediately, while an otherwise active member may prove mailbox control again.

Contacts contain no authentication credentials or trust level. Membership and token usability remain separate checks on each protected request. Future client WebAuthn, MFA, OIDC/SAML or workspace assurance policies must bind verified identity and assurance to each workspace/project authorization; a matching contact email is never proof that another workspace's requirements were satisfied. No new client methods or workspace assurance policy are claimed or enabled in 8.0. Existing developer Passkeys/MFA continue unchanged.

## Operational limits

Each existing API token is the integration namespace. External IDs are unique per token/workspace; a replacement token is a new integration namespace. Preserve the original integration token and saved `projectId`/`projectUrl` for recovery. Revoke compromised tokens; reconcile saved mappings before provisioning through a replacement token. IDs and idempotency keys are case-sensitive. A repeated external ID cannot change client or project data.

Idempotency responses are retained by the existing mechanism. Provisioning tombstones survive permanent project deletion, so a new key cannot recreate that external order. Operations UI keeps the latest 500 authenticated requests per workspace; invalid tokens are rejected before a tenant operation can be recorded. Payloads, email addresses, idempotency keys and secrets are not copied into that operations log.

There is no new queue, Redis, WebSocket, process or cron. `pending` can be resumed by replaying the same request. A process lost during `sending` has an uncertain mail outcome and is not automatically resent. The owner can explicitly resend using the existing project Clients UI, or the client can request a fresh login email. The permanent link remains usable independently of invitation delivery. The existing notification/outbox processor still requires its previously configured scheduler.
