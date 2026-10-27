# Project Brief 0.7.3 — Seamless Security Confirmation

This guide does not authorize deployment. No live Jino changes were performed. Use the site's PHP 8.4 binary and existing paths. Node, npm and Composer are not required on Jino.

## Behavior and policy

An enrolled Passkey is the preferred sensitive-action confirmation. A prepared challenge allows the original Save click to invoke WebAuthn directly, without an application dialog. Successful server verification continues the exact original draft once. A valid five-minute proof avoids another prompt. Cancellation/unavailability offers Retry, another allowed method, or Cancel; Cancel retains the draft. A cold page or expired browser activation may require one explicit Passkey button.

Owners still require confirmed TOTP and saved recovery codes; Passkeys remain optional under 0.7.2 policy. TOTP/recovery can confirm without a password. Password alone is permitted only for a non-owner without TOTP. Registration does not create fresh proof. Account/workspace/version and ACL checks remain server-side; clients and bearer integrations are unchanged. Permanent deletion still requires a separate confirmation for the exact resource/set.

Only a typed server refusal with `mutationNotStarted: true` permits one automatic continuation after confirmation. Network errors, timeouts, CSRF errors, unknown responses and registration credential POSTs are never replayed automatically. For an uncertain result, reload and inspect the server state before submitting again.

## Update from 0.7.2 on Jino

1. Verify the ZIP SHA-256 using the accompanying SHA256SUMS.txt. Rehearse on a protected disposable copy. Pause scheduler/webhook workers and writes; drain requests. Back up database, installed public/private/vendor, `.env` and storage/uploads together. Preserve the matched 0.7.2 ZIP for rollback.
2. Enter maintenance in the installed private application:

   ```sh
   cd ~/project-brief-app
   php artisan down
   ```

3. Replace application code, **whole matched vendor** and built public assets according to DEPLOY-JINO.md. Keep the installed `.env`, APP_KEY, PASSKEY_USER_HANDLE_SECRET if present, database credentials, storage, public entrypoint paths and all other site settings. Do not run first-install, key:generate, seeders or account:recover-security on an existing installation.
4. There are **no new migrations or ENV parameters in 0.7.3**. For an installation already on 0.7.2, clear/rebuild caches while maintenance is active:

   ```sh
   php artisan optimize:clear
   php artisan route:cache
   php artisan config:cache
   php artisan view:cache
   php artisan migrate:status --no-interaction
   php artisan account:security-diagnostics
   php -r 'require "vendor/autoload.php"; echo class_exists(\Webauthn\Credential::class) && class_exists(\Illuminate\Console\View\Components\Secret::class) ? "PASS: runtime classes present\n" : "FAIL: runtime classes missing\n";'
   ```

   All existing migrations should be applied, including 0.7.2's `2026_10_27_091325_add_security_recommendations_seen_at_to_users` and earlier `2026_10_26_200540_add_project_trash_lifecycle`. If upgrading from an older version, follow its migration guide first; do not assume this no-migration instruction covers that upgrade.

   For this site, effective origin/RP match diagnostics should be true: `PASSKEY_ORIGIN=https://brief.web86.site`, `PASSKEY_RP_ID=brief.web86.site`. Keep already correct values and secrets. Retain the original strict origin/RP/UV/challenge validators; never manually prune vendor.

5. Run `php artisan up`, refresh the browser/PWA assets, then check using disposable data: expired proof -> Team Save -> native Passkey -> one save/toast; existing proof -> immediate save; Cancel -> unchanged draft; TOTP/recovery alternative; developer password-only alternative when allowed; revoked ACL; scoped deletion confirmation; existing client link and bearer API. Resume background work after verification.

## Safe diagnosis

Physical Touch ID/Safari and live Jino proxy/FPM behavior require a deployment rehearsal; local tests do not certify them. If no native prompt appears, allow background preparation to finish and use the explicit retry button. Do not manufacture clicks or disable browser restrictions. Avoid simultaneous security ceremonies across tabs (one-use session challenge).

For a failed action record only HTTP method/path/status, safe `code`, `stage`, `diagnosticId`, browser/OS version, whether native confirmation completed, and whether a mutation request was sent. Keep APP_DEBUG=false. Do not export HAR, cookies, tokens, credential/challenge JSON, QR/URI, TOTP/recovery codes, `.env`, raw SQL or exception dumps. A successful native prompt alone does not mean the server saved a credential.

| Status/code                                              | Safe next step                                                             |
| -------------------------------------------------------- | -------------------------------------------------------------------------- |
| 400 PK_PAYLOAD_INVALID                                   | Update browser and start a new ceremony; retain safe reference             |
| 401 SEC_SESSION_EXPIRED                                  | Sign in again; inspect cookie attributes/presence only                     |
| 403 SEC_FORBIDDEN / SEC_OWNER_TOTP_REQUIRED              | Recheck actual ACL or complete mandatory enrollment                        |
| 419 SEC_CSRF_EXPIRED                                     | Reload/review state; no automatic mutation replay                          |
| 422 SEC_FACTOR_INVALID                                   | Wait for a new TOTP or use an unused recovery code                         |
| 422 PK_CHALLENGE_* / PK_SESSION_CHANGED                  | Same session, one setup tab, a new challenge within two minutes            |
| 422 PK_ORIGIN_MISMATCH / PK_RP_MISMATCH / PK_UV_REQUIRED | Check effective configuration and required verification; keep validators   |
| 423 security_step_up_required                            | Confirm; automatic continuation only with explicit pre-mutation marker     |
| 409 security_action_confirmation_required                | Review exact current resources and reconfirm deletion                      |
| 429 SEC_RATE_LIMITED                                     | Wait for the existing limit                                                |
| 500 PK_PERSIST_FAILED / SEC_SERVER_ERROR                 | Correlate safe reference/stage with private sanitized SQLSTATE/driver code |

If a device created a Passkey but persistence failed, inspect the saved server device list. If absent, Add Passkey starts a new registration/challenge; never resubmit credential JSON. Preserve working credentials. Accepted TOTP/recovery codes and WebAuthn challenges remain single-use.

Read-only checks: `php -v`, `php --ini`, migration status and account diagnostics above. On Jino compare CLI/FPM versions/extensions, exact public Host and HTTPS forwarding, documented proxy sources, secure host-only HttpOnly/Lax cookies, DB/session/cache write permissions and locks, clocks, matched assets/config. Do not trust wildcard proxies or derive RP/origin from untrusted headers.

## Rollback to 0.7.2

Pause writes/jobs, drain requests and enter maintenance. Restore the **matched** 0.7.2 public/private/vendor package, retain the same installed `.env`/APP_KEY/storage, clear and rebuild caches, then verify and resume. No schema rollback is required. The old explicit resubmit confirmation UX returns; factors, account/client sessions and API tokens do not need replacement. Do not restore a stale database over new writes or erase factors to make rollback work. If a database restore is necessary, use a matched backup and an explicitly accepted data-loss window.
