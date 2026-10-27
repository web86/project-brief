# Production 0.7.0: Account Security & Passkeys

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
