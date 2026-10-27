# Production 0.7.1: Account Security & Passkeys

Deployment is a separate authorized action. This repository task does not upload, merge, push, or alter the live installation. Read `DEPLOY-JINO.md` and preserve all 0.6.3 project-trash and 6.2 PDF/DOCX prerequisites. This release includes additive migration `2026_10_26_210728_add_account_security_and_passkeys.php`; the earlier `2026_10_26_200540_add_project_trash_lifecycle.php` and all prior migrations remain byte-for-byte intact.

## Before deployment

1. Rehearse on a disposable copy using the actual production PHP/database/session drivers and HTTPS origin. Confirm that every owner has an authenticator and a compatible platform or FIDO2 security key available. Unsupported browsers remain in limited setup; they must use a compatible device to complete it. Do not temporarily grant unrestricted access.
2. Back up database, private uploads/storage, current public/private/vendor, `.env`, APP_KEY and all other secrets together. Pause user writes, scheduler and webhook cron; drain in-flight requests. Enable maintenance on the installed application. Retain a tested rollback package and the pre-deploy backup.
3. Keep APP_KEY. It encrypts authenticator secrets and recovery codes. `APP_URL` must be the public HTTPS origin without an application path. Passkeys default to its exact origin and host. If overridden, set `PASSKEY_ORIGIN=https://brief.web86.site` and `PASSKEY_RP_ID=brief.web86.site` consistently. Never infer these from an untrusted Host header, permit arbitrary origins, or enable HTTP in production.
4. User handles default to HMAC with APP_KEY. If APP_KEY rotation is planned, first set a stable independent `PASSKEY_USER_HANDLE_SECRET` equal to the prior handle secret and retain it. Changing the handle secret breaks existing credentials. Domain/RP changes also require a coordinated re-enrollment plan, not a silent config edit.
5. Keep `SESSION_DRIVER=database`, encrypted sessions, Secure/HttpOnly/Lax cookies and a supported shared cache lock store. In a multi-node installation share DB and cache/locks; the cookie session driver cannot provide route blocking. Ensure the accounts' `auth_session_version` column already exists. Preserve the current session lifetime and all client access tokens.

## Apply the package

Use one matched public/private/vendor release and verify its `release.json`/`checksums.txt`/ZIP SHA-256. Replace production vendor as a whole; Node, npm and Composer are not required on the server. Do not copy tests, screenshots, source documentation or development `.env` files to the web root.

From the private application directory, while maintenance and background pauses are active:

```sh
php artisan down
php artisan migrate --force --no-interaction
php artisan optimize:clear
php artisan route:cache
php artisan config:cache
php artisan view:cache
php artisan up
```

Verify that the effective origin/RP config matches the browser before opening traffic. First-install is for a new installation only. Existing owners are not deleted, and factors are not fabricated during migration. Their next request enters controlled setup; an old session needs fresh password confirmation. Existing fully enrolled sessions lacking strong assurance must reauthenticate. Password reset retains factors and never grants a full session. Developer password access and client links remain unchanged. Existing bearer integration tokens continue to work; UI token/webhook mutations require fresh confirmation.

## Owner setup and normal recovery

- Sign in with password. Enroll TOTP by scanning the QR or using the manual otpauth URI; confirm a current code. A previously accepted code cannot be reused, including immediately after setup. Wait for a new thirty-second code or use a recovery code.
- Save the eight recovery codes offline or in a password manager. Acknowledge saving, then register a Passkey. Retry or resume after reload when WebAuthn is cancelled; no privilege fallback exists.
- Future entry: UV Passkey, or password followed by TOTP/one-use recovery. Password reset requires mailbox proof and the owner's factor or fresh strong same-account session.
- Lost authenticator: use UV Passkey or password + recovery, then replace the authenticator and save a new recovery set. The old authenticator remains valid until replacement confirmation. Lost Passkey: use password + TOTP/recovery, add a replacement first, then remove the lost credential. Add two independent Passkeys to reduce recovery risk. Do not delete the final owner credential or disable TOTP by editing database rows.
- New recovery-code generation invalidates the prior set and briefly restricts the owner until saving is acknowledged. Factor changes revoke other account sessions; client sessions and existing bearer tokens are unaffected.

## Total loss: operator recovery

This procedure requires trusted private-host CLI access and **independent verification** of the owner's identity and authority using the organization's established channel. A support email, password-reset email or knowledge of the address alone is insufficient. Preserve an incident record outside the application; application logs intentionally contain no secrets.

From the correct private installation:

```sh
php artisan account:recover-security owner@example.com
```

The command refuses noninteractive operation. Type the exact owner email when prompted. It deletes that User's Passkeys, clears TOTP/recovery enrollment, cancels password-reset/challenge state and revokes all account sessions using `auth_session_version`. Workspace memberships, clients, personal links and integration bearer tokens are retained. The audit event is `operator_recovery_started` with user ID only.

The CLI displays a random **single-use token**, valid for 30 minutes. Deliver it only through the independently verified secure channel, never a public issue, logs, command arguments or a support screenshot. On `/admin/login`, expand “Operator recovery token” and supply it with email and the existing password. If the password was also lost, perform the normal mailbox password reset after operator verification; the CLI token is still required. This opens **restricted setup only**. Complete authenticator, saved recovery codes and Passkey in that browser. If the session is lost, the token expires, or setup cannot finish in time, repeat the verified CLI procedure; never clear the recovery flags manually. Do not reuse the displayed token.

After recovery, test the new factors, confirm old recovery values/credentials and old sessions fail, review the user ID audit trail and review existing integration tokens for independent compromise (do not automatically revoke business integrations).

## Smoke and release gate

Use disposable accounts/data on staging before deployment. Verify owner enrollment restrictions on direct API, `/api/session`, selected workspaces, export, trash, team, clients and integrations; owner role in another workspace; invitation and reset flows; UV Passkey and password+TOTP/recovery; wrong origin/RP/challenge/UV/signature/counter, replay, rate limits and concurrency; fresh confirmation; factor rotation and session revocation. Check developer password/optional Passkey and the existing client link lifecycle, comments and files. Check RU/EN/TR/ZH-CN, 390px and desktop, light/dark/system. The local verification report does not certify live Jino, physical authenticators, SMTP delivery, proxy headers or a production deployment.

## Rollback

An older release has password-only owner authentication and must not serve newly enrolled owner accounts. **Do not perform an unattended code-only downgrade.** Keep maintenance, pause cron and writes, and obtain an explicit security rollback decision.

The preferred recovery from a defective new build is a corrected 0.7.x build preserving the factors/schema. If rollback to 0.6.3 is necessary, restore a consistent pre-deploy database/storage/public/private/vendor snapshot, preserve the appropriate `.env`/APP_KEY, invalidate owner account sessions, and keep the downgraded owner interface behind an operator-controlled access restriction until upgraded again. Such restoration loses subsequent writes and restores the previous weaker authentication policy; agree to that explicitly. Client session invalidation is not required merely to roll back account factors.

The additive schema can remain while maintenance is active. If removing it is required and this migration is the last applied migration, only in maintenance after exporting the encrypted factor/credential data and accepting its loss:

```sh
php artisan migrate:rollback --step=1 --force --no-interaction
```

Never run the command blindly if later migrations exist. It drops Passkeys/challenges and the new user columns. Restore the matched old code/vendor, clear/rebuild caches, keep the access restriction, and smoke disposable data before resuming background work. Earlier project-trash rollback requirements still apply if downgrading below 0.6.3: recover projects from trash and drain `project_file_deletions` before reverting its schema.

## 0.7.0 → 0.7.1: Passkey diagnostics and retry hotfix

The observed Jino 422 has **not** been attributed to a specific production check. In 0.7.0 `AccountSecurityController::passkey()` converted every WebAuthn/storage exception into `AccountSecurity::valid(false)`, producing the same `errors.code` as an invalid TOTP. The registration route never calls `verifyFactor()`; confirmed TOTP supplies a version-bound ten-minute server proof, retained through recovery acknowledgement. Do not re-enter the enrollment TOTP at step 3. A genuinely expired proof requires a fresh code or unused recovery code in the confirmation form. TOTP replay protection remains active.

The hotfix preserves HTTP status and returns `code`, `stage`, `diagnosticId`. Logs named `account_security_failure` contain only those values, exception class, presence/match booleans and, for a DB failure, SQLSTATE/numeric driver code. No credential, challenge, cookie, password, TOTP/recovery code, SQL query/binding, raw exception message or stack is recorded by this handler. Keep `APP_DEBUG=false`; do not export HAR, request bodies, cookies, setup QR/URI, `.env`, or complete exception logs for troubleshooting.

### Upgrade (operator action, not executed by this task)

1. Back up the database, private storage and matched code/public/vendor. Put the site in maintenance with `php artisan down` from the real private application directory, using the hosting PHP **8.4** binary.
2. Replace code/vendor/public assets from the verified 0.7.1 ZIP using the existing Jino deployment procedure. Preserve the installed `.env`, `APP_KEY`, optional `PASSKEY_USER_HANDLE_SECRET`, database credentials, private storage and host-specific entrypoint paths. **Do not run first-install, key:generate, seeding or account:recover-security.** No new migration is introduced by this hotfix; use `php artisan migrate:status --no-interaction` to verify the existing Milestone 7 migration is applied.
3. Run `php artisan optimize:clear`, then `php artisan config:cache`. Run the read-only diagnostic command below. If schema or origin/RP checks fail, keep maintenance and investigate the existing configuration; do not regenerate secrets.
4. Run `php artisan up`. Close duplicate setup tabs, reload the updated application/PWA and continue the existing enrollment. Existing TOTP and recovery codes remain valid under their existing replay rules. Check the server device list before retrying: if a device credential was created but is absent from the list, Add Passkey starts a new challenge. Never resend captured credential JSON or delete working credentials to bypass a failure.

### Safe Jino diagnosis

Run from the installed private application folder, with the same PHP 8.4 binary used by the website (CLI and PHP-FPM can load different extensions/configuration):

```sh
php -v
php --ini
php artisan migrate:status --no-interaction
php artisan account:security-diagnostics
```

This read-only command prints PHP/SAPI/time, required extension booleans, config-cache state, schema checks, session flags and matches for **exactly** `PASSKEY_ORIGIN=https://brief.web86.site` and `PASSKEY_RP_ID=brief.web86.site`. It deliberately exports neither configured secrets nor database contents. All schema booleans and both expected-origin/RP booleans should be true. Confirm the website runtime is also PHP 8.4 with OpenSSL; ask hosting support to compare its FPM extension list and clock without publishing phpinfo.

Reproduce once with DevTools Network open. Record only method/path, HTTP status, `code`, `stage`, `diagnosticId`, elapsed seconds from options to submit, browser/OS versions, and whether Touch ID completed. Match the reference locally:

```sh
rg 'account_security_failure' storage/logs/laravel*.log | tail -n 20
```

If the site uses stderr/syslog instead of file logging, find the same event in the configured host log. Do not publish unrelated log lines. No cookie values or credential/challenge data are needed. The relevant failure codes are:

| HTTP / code                                                 | Meaning / next check                                                                                                                                                                                 |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 400 `PK_PAYLOAD_INVALID`                                    | Credential deserialization failed. Browser version, extension/password-manager interception and fresh clean-browser retry.                                                                           |
| 401 `SEC_SESSION_EXPIRED`                                   | Account session absent/revoked. Check cookie **presence and attributes**, not its value; sign in again.                                                                                              |
| 403 `SEC_FORBIDDEN`                                         | Enrollment prerequisites/authorization not satisfied. Status should show confirmed TOTP and saved recovery codes. Do not change roles.                                                               |
| 419 `SEC_CSRF_EXPIRED`                                      | Session/CSRF changed. Refresh; check cookie domain/path, Secure, HttpOnly, SameSite, and duplicate applications/cookies.                                                                             |
| 422 `PK_CHALLENGE_MISSING/INVALID/SESSION/EXPIRED/MISMATCH` | Missing, consumed, different-session, expired or replaced request. Only one setup tab; options and submit must use the same session, within two minutes. Start a new ceremony, never replay JSON.    |
| 422 `PK_SESSION_CHANGED`                                    | Security version changed during registration. Refresh status and confirm identity again.                                                                                                             |
| 422 `PK_ORIGIN_MISMATCH` / `PK_RP_MISMATCH`                 | Compare effective cached configuration and actual browser origin. Exact HTTPS domain; no scheme/path in RP ID. Do not relax either validator.                                                        |
| 422 `PK_UV_REQUIRED` / `PK_VERIFY_FAILED`                   | UV or another WebAuthn validation failed. Keep UV required; try a supported authenticator.                                                                                                           |
| 422 `SEC_FACTOR_INVALID`                                    | Actual password/TOTP/recovery confirmation failed. It must come from a factor endpoint, never Passkey validation. An already used TOTP must remain rejected.                                         |
| 423 `security_step_up_required`                             | Ten-minute proof expired. Confirm again using a new factor code, then create a new Passkey request.                                                                                                  |
| 429 `SEC_RATE_LIMITED`                                      | Wait for the existing limit; do not remove throttling.                                                                                                                                               |
| 500 `PK_PERSIST_FAILED` / `SEC_SERVER_ERROR`                | Use stage, exception type and safe SQLSTATE/driver code to distinguish database/schema/permission/JSON problems from session update or runtime failures. Do not share the SQL statement or bindings. |
| 503 `PK_UNAVAILABLE`                                        | Configured transport origin is invalid/insecure. Correct deployment configuration, retaining HTTPS validation.                                                                                       |

For the Jino reverse proxy, verify the domain certificate, forwarded public Host, HTTPS termination, `X-Forwarded-Proto`, and documented proxy source addresses with hosting support. Failure records show `request_secure`, `forwarded_https`, `request_origin_matches`, `session_cookie_present` as booleans only. `request_secure=false` behind TLS termination is a configuration clue, **not proof of the WebAuthn failure**: this application validates against the configured RP ID and allowed origin, not a forwarded Host. Do not add wildcard trusted proxies or trust arbitrary forwarded headers. Secure host-only cookies and same-origin CSRF remain required. Also check DB/session write permissions, no stale config cache/mixed release assets, and no old tab replacing the pending challenge.

Verified locally: exact production origin/RP with a Chromium virtual resident UV authenticator and real Laravel cookies/CSRF/database, plus protocol rejection and storage-failure regression tests. This is not a production smoke, physical Touch ID test, or confirmation of the currently installed Jino configuration.
