# Milestone 7.2.1 — implementation

Based on local 0.7.2 HEAD `72db56a`, on `codex/milestone-7-2-flexible-account-security`. Runtime patch is 0.7.3. No migrations, new ENV, dependencies or auth system.

## Cause and change

0.7.2's API client opened the common step-up dialog after HTTP 423, waited for confirmation and then required a second submission of the action. The dialog presented Passkey alongside TOTP with login wording. The native ceremony therefore could not begin directly from the original sensitive-action click.

The existing step-up service now prepares status and confirmation options on relevant admin screens, including the actual `/admin/settings/integrations` route. Ordinary task and notification screens do not prepare security challenges. Options are memory-only, used once and expire locally after 90 seconds (server challenge remains 120 seconds). The existing installed Passkey serializer calls `navigator.credentials.get()` synchronously when supplied prepared options; there is no API await before that invocation. The original click calls it directly, and the common dialog stays closed while native verification runs. No login endpoint, global fetch interception, synthetic click or custom WebAuthn implementation was added.

Background preparation is single-flight and cannot replace an active ceremony. Cold/expired preparation uses a one-button fallback if user activation is gone. Retry obtains fresh options; expected cancellation/unavailability is not a critical toast. Compact alternatives reuse SecurityConfirmation, SecurityError, BaseDialog, LoadingButton and the toast service in all four locales. TOTP accounts never offer password-only confirmation. Drafts and busy states survive cancellation.

Server status includes the actual remaining proof lifetime, capped at five minutes, and an opaque HMAC context for account/version/workspace/policy. The context contains no session ID or authority; successful confirmation regenerates the session as before. Context changes cancel the pending intent. Browser cache is advisory; every server mutation still verifies current proof, membership and ACL inside its existing execution guards.

The API client snapshots JSON before any await and shares an in-flight identical sensitive request. With prepared status it confirms before sending the mutation. A stale cache can also receive an explicit pre-mutation 423, confirm and continue once with fresh CSRF. Only `security_step_up_required` plus `mutationNotStarted: true` from known guards permits that continuation. Generic 423 responses do not assert this marker. A second denial stops. Timeout, network/HTTP 400/401/403/419/422/500 and credential registration POST are never automatically replayed. Resource-specific irreversible deletion confirmation remains required.

The sensitive-path allowlist covers account email/password, team invitations/membership/permissions, client links, tokens/webhooks, permanent deletion/trash and existing factor management. Ordinary tasks/comments/status/notification preferences, client routes and bearer APIs remain outside proactive gating. Narrow initial TOTP setup/recovery grants retain their existing purpose and do not become general proof. Factor/auth/workspace changes clear the browser cache.

The 0.7.0 vendor-pruning fix and 0.7.2 CLI connection/transaction fixes are retained. Production packaging still explicitly requires Webauthn\Credential and Laravel Console Secret. Existing cryptographic signature/origin/RP/UV/counter/challenge, one-use TOTP/recovery, session/version, CSRF, throttling, client and integration boundaries are unchanged.

## Browser basis and limits

WebKit documents user-gesture requirements and preparation before activation: [Face ID and Touch ID for the web](https://webkit.org/blog/11312/meet-face-id-and-touch-id-for-the-web/). Transient activation is time-limited: [WebKit User Activation API](https://webkit.org/blog/13862/the-user-activation-api/). The implementation does not assume arbitrary await chains retain it. Chromium tests inspect real userActivation at native get and count application showModal calls. Firefox compatibility runs the actual browser engine and serializer with a mocked provider, plus real server recovery fallback; it does not certify physical Touch ID. See VERIFICATION.md for actual availability and results.
