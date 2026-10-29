# Project Brief 0.8.3 / Milestone 8.2 — Jino deployment and rollback

Prepared locally from completed0.8.2 (`03932e0`). No push, merge or deployment was performed. These instructions do not authorize deployment. Local HTTPS/SQLite/MariaDB/PHP8.4 tests are not Jino preflight.

## Source-project correction (branch, not a replacement ZIP)

`codex/fix-sso-source-projects` is based on completed0.8.3 (`e816abd`) and needs no new migration or infrastructure. Existing source provisioning/request provenance supplies automatic project authorization, including existing records; additional manual grants remain for unattributed projects. Configure SSO/origin once with an empty manual list. Tokens for creation, approval and SSO may differ; use the same source for creation/SSO. Request origin is the submitting source even if another credential approves. Source-assigned provisioning now deduplicates externalId/body across replacement credentials; source-less token behavior is unchanged. Preserve original credential/provisioning/request records and rotate by revocation rather than physical deletion/reassignment.

Before deploying this correction, repeat hosting smoke with two projects from one source, another source, an unattributed legacy project, different creator/SSO tokens and a lost-result retry after rotation. Existing0.8.3 archive/checksum metadata remains historical and does not contain this correction. Rolling only this correction back to original0.8.3 restores manual-only grants: automatic-only projects/sessions will be refused until explicitly permitted or accessed through ordinary email login. The full SSO rollback to0.8.2 below still requires revoking marked access tokens first.

## Backup and maintenance

Check archive SHA-256/bytes, CRC and internal manifest against RELEASE-ARTIFACT.json/SHA256SUMS.txt. Rehearse on private Jino PHP8.4/MariaDB staging. Back up the database, exact public/private/vendor set, APP_KEY, `.env`, uploads/branding/storage, sessions, actual `.htaccess` and schedules. Keep independent rollback access. Preserve MFA/Passkeys, RP ID/origin and existing security settings. HTTP_BOOTSTRAP_LIMIT_ENABLED stays disabled until the separate proxy/NAT preflight.

Pause external API callers and writes, drain HTTP requests and enter maintenance with `php artisan down`. Replace public/private/vendor from the ZIP following DEPLOY-JINO.md while retaining `.env`, APP_KEY, working storage and maintenance marker. Do not run first-install, db:wipe, migrate:fresh, key:generate or seed commands on an existing installation.

## Migration and configuration

Requires completed0.8.2 foundation, including `2026_10_29_123816_create_project_requests_foundation`. Additive migration `2026_10_29_174316_add_client_sso_handoffs` adds disabled-by-default source SSO/origin, explicit source/project grants, hashed identity bindings and one-use hashed handoffs, plus a nullable marker on existing project_access_tokens. Existing token scopes, clients, memberships, invitations and ordinary sessions are unchanged. The marker deliberately has no nullable-on-delete FK: a missing handoff must deny SSO, never turn its access token into an ordinary Magic Link.

MariaDB DDL implicitly commits. Take the backup first; inspect actual schema/migration status if DDL fails rather than blindly rerunning.

```sh
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
php artisan migrate:status --no-interaction
php artisan account:security-diagnostics
```

Use HTTPS end to end, the canonical FRONTEND_URL, secure HttpOnly session cookies and the existing host's session policy. Configure proxy/APM to exclude Authorization/Cookie and API/form response/request bodies for `/api/v1/client-sso/handoffs` and `/client-sso/*`; no codes in access-log URLs. The external relay must be no-store, use the helper's nonce CSP and Referrer-Policy:origin (path/secret never sent); source Origin must match exactly, including port. Verify host-level headers do not override the relay's policy. Configure source origin, optional manual legacy grants and an SSO-only server credential explicitly in the existing owner UI. There is no automatic upgrade of existing credentials and no default source grants.

No dependency change, service, Redis, worker, new cron or registration. Existing integrations:webhooks scheduling remains as before. Expired unused handoffs are opportunistically pruned in small batches; consumed rows retain session revocation linkage and identity bindings remain stable.

## Staging smoke

- Existing owner/developer password, TOTP/recovery, optional Passkeys; existing invitations,30-minute repeat Magic Link, client project/task/section creation, exports, provisioning, requests and Live Sync still work.
- Defaults off; old tokens lack clients:sso. Owner-only source config requires normal sensitive-action assurance. Other roles cannot enable sources or grant projects.
- Trusted existing external session + CSRF + verified database email + immutable user ID + saved project ID: one click opens the correct explicitly granted project. Inspect cross-site POST and same-origin completion cookies under actual browser/session configuration. Exact source origin required; wrong/null origin denied. No code in URL/referrer/storage/log/APM/webhook/history state.
- Replay/expired code and concurrent redemption deny all but one; active client/project and workspace checked again at consumption. Revoked token/source/grant blocks pending handoff; email change/conflict does not rebind. SSO-only scope cannot provision or approve requests.
- Existing other client/staff/MFA session refuses SSO and remains usable. Ordinary email login is available after SSO failure. Source changes revoke existing SSO access, never ordinary Magic Links. Check4locales, both themes and desktop/mobile.
- Test external cURL TLS verification, no redirects, private credential file permissions and existing account email-proof lifecycle. Never publish tests/client-sso-external.php or create a mock-login route in production. Real web86.site account integration requires a separately existing account system.

After actual hosting smoke and explicit deployment authorization, `php artisan up`, resume external callers/writes. Keep the previous0.8.2 archive.

## Application rollback — revoke SSO first

Pause external callers/writes, drain requests and enter maintenance. **Before restoring0.8.2 code, permanently revoke SSO access tokens as well as handoffs and disable all sources.** Old code does not understand the SSO marker and cannot perform source checks. In a backed-up maintenance database, run these statements (table names assume the existing empty DB prefix):

```sql
START TRANSACTION;
UPDATE project_access_tokens SET revoked_at = CURRENT_TIMESTAMP
 WHERE client_sso_handoff_id IS NOT NULL AND revoked_at IS NULL;
UPDATE client_sso_handoffs SET revoked_at = CURRENT_TIMESTAMP WHERE revoked_at IS NULL;
UPDATE integration_sources SET sso_enabled = 0;
COMMIT;
```

Restore the exact completed0.8.2 public/private/vendor set, preserving APP_KEY, `.env`, storage, branding, session files, `.htaccess` and maintenance marker. Keep the additive schema and binding/grant/handoff/revocation data. Clear/rebuild caches under old code and verify ordinary project access/Magic Links/staff MFA. Existing session cookies referencing revoked SSO access are denied by ordinary old token revocation checks. Do not simply deploy old code while SSO tokens remain live.

Do not run general migrate:rollback or delete marker/binding rows. The new migration refuses destructive down when identities/handoffs exist. Re-enabling after forward recovery does not revive revoked codes/sessions. Do not restore a stale whole database without a separate reviewed recovery plan for subsequent business writes. The existing request/webhook model is supported by0.8.2; any deeper rollback must follow the earlier request outbox precautions too.
