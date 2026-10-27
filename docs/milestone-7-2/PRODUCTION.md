# Production 0.7.2 — Flexible Account Security

This is an update guide, not deployment authorization. No live Jino changes were performed. Use the site's PHP 8.4 binary; confirm CLI and FPM versions/extensions with hosting support. Keep all existing Jino paths, HTTPS configuration and secrets. Node, npm and Composer are not required on the server.

## Policy after updating

| Account                       | Required for full access              | Password login                                        | Alternative login                                         |
| ----------------------------- | ------------------------------------- | ----------------------------------------------------- | --------------------------------------------------------- |
| Owner of any active workspace | Confirmed TOTP + saved recovery codes | Password + TOTP or unused recovery code               | Existing UV Passkey; owner TOTP enrollment still required |
| Non-owner User                | No mandatory factors                  | Password; TOTP/recovery also required if TOTP enabled | Existing UV Passkey                                       |
| ProjectClient                 | Existing client link                  | Unchanged                                             | Unchanged                                                 |

Passkeys are optional for everyone. An owner stalled at the old third enrollment step gains full access once TOTP and saved recovery are present, without registering another key. Existing factors remain valid. Developers see a one-time optional recommendation; Later acknowledges it globally. Settings remain available. Role changes apply across all workspaces; demotion never deletes factors.

Sensitive UI mutations require a session-, account-version- and membership-bound proof lasting five minutes. Confirm using TOTP/recovery without re-entering the password, UV Passkey, or current password only for non-owners without TOTP. Registration is not fresh verification. Factor changes revoke other account sessions and clear proof; client sessions and integration bearer tokens are preserved. After a confirmation dialog the user explicitly submits the original action again. Permanent project deletion additionally requires confirmation for the exact resource/set and operation, valid for two minutes.

## Update from 0.7.1

1. Verify the ZIP SHA-256 against the accompanying artifact report; use a matched public/private/vendor release. Rehearse on a protected disposable copy of the actual installation first.
2. Pause writes, scheduler/webhook workers and drain in-flight requests. Back up database, private storage/uploads, installed public/private/vendor and `.env` together. Preserve the old matched package for rollback. Enter maintenance using the installed private application:

   ```sh
   cd ~/project-brief-app
   php artisan down
   ```

3. Replace application code, vendor and public assets using `DEPLOY-JINO.md`. Preserve the installed `.env`, APP_KEY, optional PASSKEY_USER_HANDLE_SECRET, database credentials, storage, public entrypoint paths and all other site-specific settings. Do not run first-install, key:generate, seeders or account:recover-security for an ordinary update.
4. From the updated private application, with maintenance still active:

   ```sh
   php artisan migrate --force --no-interaction
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   ```

   New migration: `2026_10_27_091325_add_security_recommendations_seen_at_to_users.php`. It adds one nullable timestamp, without rewriting existing data. The 0.7.1 account-security migration and earlier `2026_10_26_200540_add_project_trash_lifecycle.php` remain unchanged. All schema/runtime diagnostics should pass. For this site the expected origin/RP match booleans should be true: `PASSKEY_ORIGIN=https://brief.web86.site`, `PASSKEY_RP_ID=brief.web86.site`. Do not change already correct configuration or regenerate secrets.

5. Resume the website with `php artisan up`, refresh old/PWA assets and sign in. Old strong TOTP/Passkey sessions retain ordinary access, but lack the new sensitive-operation proof. A legacy weak session on an account with TOTP must reauthenticate. Test with disposable data: owner TOTP without Passkey, optional Later, optional Passkey registration/login, developer optional TOTP password challenge, team indicators, sensitive confirmation with explicit resubmit, scoped permanent delete, existing client link and bearer integration. Resume background jobs after checks pass.

The original 0.7.0 runtime defect remains fixed: the production package retains `Webauthn\Credential` and Laravel console `Secret` classes. Do not manually prune vendor. The canonical builder verifies these exact runtime paths and signed UV enrollment from the extracted no-dev ZIP.

## Recovery and diagnosis

Save recovery codes privately. Each code and accepted TOTP step remains single-use. New TOTP setup grants only a narrow five-minute recovery display/acknowledgement window; saving recovery codes does not authorize another sensitive operation. Wait for a new TOTP code or use an unused recovery code for fresh proof. If setup expires, confirm again. Owner TOTP cannot be disabled; replace it after fresh confirmation or follow the existing audited CLI operator-recovery procedure. After CLI recovery, finish TOTP and recovery acknowledgement; Passkey remains optional. Do not invoke operator recovery to fix a browser/ZIP error.

If the device created a Passkey but server storage failed, inspect the server device list before retrying. If absent, Add Passkey generates a new challenge. Do not replay credential JSON, disable UV/origin/RP checks, or delete working credentials. All security actions use toast plus contextual blocking errors.

Read-only diagnosis in the private application:

```sh
php -v
php --ini
php artisan migrate:status --no-interaction
php artisan account:security-diagnostics
php -r 'require "vendor/autoload.php"; echo class_exists(\Webauthn\Credential::class) && class_exists(\Illuminate\Console\View\Components\Secret::class) ? "PASS: runtime classes present\n" : "FAIL: runtime classes missing\n";'
```

Record only HTTP method/path/status, safe `code`, `stage`, `diagnosticId`, elapsed time, OS/browser version and whether native verification completed. Keep APP_DEBUG=false. Do not share cookies, CSRF values, challenge/credential payload, QR/URI, TOTP/recovery codes, HAR, `.env`, raw SQL or exception dumps. Existing sanitized account-security log records contain no such values.

| Response                                                 | Action                                                                             |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| 400 PK_PAYLOAD_INVALID                                   | Update browser; retry a new ceremony; retain diagnostic reference                  |
| 401 SEC_SESSION_EXPIRED                                  | Sign in again; check cookie presence/attributes, never values                      |
| 403 SEC_FORBIDDEN / SEC_OWNER_TOTP_REQUIRED              | Check current ACL or finish mandatory TOTP; do not grant roles to bypass           |
| 419 SEC_CSRF_EXPIRED                                     | Reload and review state; no automatic mutation replay                              |
| 422 SEC_FACTOR_INVALID                                   | New TOTP or unused recovery code; accepted codes cannot be reused                  |
| 422 PK_CHALLENGE_* / PK_SESSION_CHANGED                  | One setup tab, same session, fresh challenge within two minutes                    |
| 422 PK_ORIGIN_MISMATCH / PK_RP_MISMATCH / PK_UV_REQUIRED | Verify exact effective origin/RP and required user verification; retain validators |
| 423 security_step_up_required                            | Confirm using an allowed method; review and explicitly submit again                |
| 409 security_action_confirmation_required                | Review current resources and create a new deletion confirmation                    |
| 429 SEC_RATE_LIMITED                                     | Wait for the existing limit                                                        |
| 500 PK_PERSIST_FAILED / SEC_SERVER_ERROR                 | Correlate safe reference/stage and SQLSTATE/driver code privately                  |

For Jino TLS termination verify certificate, forwarded public Host, HTTPS/X-Forwarded-Proto and documented proxy sources. Do not trust wildcard proxies or derive RP/origin from untrusted headers. Check same-origin secure host-only HttpOnly/Lax cookies, shared session/cache locks, DB write permissions, clocks and matched cached config/assets. Diagnostic booleans distinguish these clues; none alone establishes a WebAuthn cause.

## Rollback to matched 0.7.1

Pause background jobs/writes, drain requests and enter maintenance. Restore the matched 0.7.1 public/private/vendor, preserve the same `.env`/APP_KEY/storage, clear and rebuild caches. The nullable recommendation column may remain; old code ignores it. Do not blindly roll back migrations or restore a stale database over live writes. If removing the column is necessary, first verify this is the last applied migration and back up; its down method removes only that timestamp, never factors. A database restore must match the application/storage backup and an agreed data-loss window.

0.7.1 again requires owner Passkeys and lacks global voluntary developer TOTP enforcement. Newly admitted owners without Passkeys would return to restricted setup, and developers relying on TOTP lose the new password-entry enforcement. Keep maintenance until this policy downgrade is explicitly accepted and safe access is established. Do not erase factors or client links to make rollback work. Earlier trash rollback rules still apply below 0.6.3.

Local/package verification is documented in the accompanying report. It does not establish physical Touch ID success or live Jino readiness.
