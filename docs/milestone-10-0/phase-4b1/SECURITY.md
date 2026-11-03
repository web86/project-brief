# Security boundaries

New account metadata is additive; no existing User acquires pending status merely because email_verified_at is null. The global onboarding middleware precedes business/security middleware and denies pending business reads and mutations, including cross-tenant IDs. Session projections expose progress rather than workspace contents. Existing API-token, client-link and external SSO authorization remain separate.

State is one-time, unpredictable, provider/session-bound and consumed under lock before token exchange. Every provider uses PKCE S256. Link attempts additionally bind actor and auth_session_version; fresh strong proof is rechecked at callback. Recovery mode cannot link. Provider subjects are unique by provider/issuer/hash; User-first identity locking prevents callback/link inversions. No email-based automatic account merge occurs. Same-subject linking and duplicate callbacks are tested using independent MariaDB processes.

Google RS256 verification uses the JOSE library already present in composer.lock through the existing web-push dependencies; no dependency was added. Provider endpoints/JWKS are fixed HTTPS URLs, TLS verification enabled, redirects disabled, bounded response JSON and timeouts. QA fakes these endpoints and signs ephemeral RSA fixtures. No supplied URL is fetched.

OAuth token response secrets remain transient; only hashed identity, assurance timestamps and encrypted short-lived attempt material are stored. Pending missing-email facts and invitation context are encrypted and session-bound. Verification proof is hashed and current-address bound. Session ID and CSRF token rotate through the existing primary login/security handoff. Linking rotates the session; unlink/password changes revoke existing sessions according to existing security policy.

Mandatory audit is in the same transaction as verification/provisioning/identity changes. A forced audit failure rolls back verification, workspace and claim. Audit metadata contains safe action/provider codes, not raw email, subject, token, code, verifier or nonce. Primary-only diagnostics disclose booleans and aggregate counts with an allowlist of reason codes, no arbitrary stored reason strings.

Application errors sanitize provider failures and do not log raw OAuth responses. Callback responses have no-store and no-referrer. **Hosting access logs must also redact callback query arguments:** authorization codes arrive in the standard OAuth query. Configure the `/auth/google/callback`, `/auth/yandex/callback`, `/auth/github/callback` locations to log path/status only (for example nginx `$uri`, not `$request_uri`/`$request`), and apply equivalent Apache/Jino logging controls. Keep APP_DEBUG=false; never use log mail transport or request-body tracing for account routes.

Optional bounded cleanup: `php artisan onboarding:prune-attempts --dry-run --limit=100`, then the same command without --dry-run. Limit must be 1–500. It removes expired short-lived OAuth attempts only, not Users, workspaces, claims or durable aliases. It is not scheduled automatically and introduces no queue/Redis/worker requirement.

Production registration and Trial activation remain hard off even with requested env flags true. No commercial executor, automatic destructive retention or operator email-confirm bypass exists.
