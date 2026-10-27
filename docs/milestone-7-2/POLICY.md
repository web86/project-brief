# Milestone 7.2 policy and authorization map

Account factors belong to User globally, never to ProjectClient or a selected workspace. Any active owner membership makes confirmed TOTP and acknowledged recovery mandatory. A confirmed UV Passkey is an optional independent login method. Owner TOTP cannot be disabled; developers may disable theirs after fresh verification and proof of the current password as an alternative. Removing the last Passkey needs fresh proof and current password; with TOTP, a UV proof additionally needs a new TOTP/recovery code to establish the usable password+TOTP alternative. Factors are never fabricated, reset or downgraded during migration or demotion.

| User configuration                                   | Password entry / reset                               | Sensitive proof                                      | Full account access   |
| ---------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------- | --------------------- |
| Developer, none                                      | Password                                             | Current password, five minutes                       | Yes                   |
| Developer, Passkey only                              | Password or UV Passkey                               | Password or fresh UV assertion                       | Yes                   |
| Developer, TOTP only                                 | Password + TOTP/recovery; reset also verifies factor | TOTP/recovery without password                       | After saved recovery  |
| Developer, both                                      | Password + TOTP/recovery or UV Passkey               | TOTP/recovery or fresh UV assertion                  | After saved recovery  |
| Owner, missing TOTP or saved recovery                | Restricted setup regardless of Passkey               | Password grants only TOTP bootstrap when TOTP absent | No                    |
| Owner, TOTP and saved recovery, with/without Passkey | Password + TOTP/recovery, or existing UV Passkey     | TOTP/recovery or fresh UV assertion                  | Yes; Passkey optional |

The one-time global recommendation timestamp is advisory and never part of business API authorization. Later acknowledges it. An account may still open ordinary work directly. The settings page always exposes both sections, with owner-required TOTP and otherwise optional labels. A browser without WebAuthn can continue after mandatory owner TOTP setup.

## Assurance, proofs and lifecycle

Login assurance (`security.user/version/method`) is separate from short-lived sensitive proof. Old 0.7.1 strong sessions retain ordinary access with confirmed TOTP and saved recovery even if Passkey enrollment never finished; old password-only sessions on TOTP accounts remain blocked. Every password entry path uses enabled TOTP, including developer login and password reset. Invitations cannot issue an authenticated session around the TOTP guard. Acceptance regenerates session and clears fresh proof while preserving existing verified login assurance.

A sensitive proof records User ID, security version, SHA-256 session binding, five-minute timestamp, verified method and current ordered membership-policy fingerprint. The existing challenge table is its revocable server ledger. Membership saving/deleting revokes these rows before policy changes, preventing a role flip from reviving old proof. Session regeneration invalidates the explicit hash binding. Login/confirmation regenerate first, then bind proof. Logout, factor changes, version change and role changes invalidate proof. Password assurance is accepted only for non-owners without TOTP. Real WebAuthn registration clears proof and never substitutes for an assertion.

TOTP setup consumes its OTP step, changes account version, revokes other account sessions and grants only a five-minute recovery display/acknowledgement capability. This grant cannot enroll a Passkey or change other sensitive settings, and is removed after acknowledgement. A new code or unused recovery code authorizes the next sensitive action. Recovery codes are one-use under a User row lock. WebAuthn options/challenge are single-use, session/version/purpose-bound and two minutes; origin/RP/UV/counter validation is unchanged. The enrolled-factor/session and reset/invitation tests exercise these paths independently of business-test authentication fixtures.

## Server mutation gates

| Operation                                                             | Existing ACL                                           | New execution requirement                                                                                                          |
| --------------------------------------------------------------------- | ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| TOTP/Passkey/recovery management                                      | Current User owns factor                               | Fresh proof; narrow bootstrap/recovery capabilities only for their exact setup stage                                               |
| Email/password change                                                 | Current User + existing password/mail ownership checks | Fresh proof under User lock                                                                                                        |
| Team invitations, revocation, membership/permission/create privileges | Selected workspace owner                               | Fresh proof after ACL and again under workspace/current membership locks                                                           |
| Client links / changes that revoke links                              | Existing manage_clients permission                     | Workspace/project locks, current membership/project assignment reads, fresh proof                                                  |
| Integration token/webhook UI mutations                                | Selected workspace owner                               | Workspace/current membership locks + fresh proof                                                                                   |
| Permanent project deletion / empty trash                              | Existing workspace owner and trash ACL                 | Fresh proof + explicit two-minute confirmation bound to workspace, operation and exact sorted project IDs; consume transactionally |
| Ordinary tasks/comments/files/status/export/preferences               | Existing permission model                              | No extra sensitive proof                                                                                                           |
| Stateless bearer `/api/v1/*`                                          | Existing token/scopes/tenant rules                     | Unchanged; no browser proof or automatic token revocation                                                                          |
| ProjectClient links/comments/files                                    | Existing personal client-link rules                    | Unchanged; no User factors or factor status disclosure                                                                             |

Current locked reads bypass a stale MariaDB repeatable-read snapshot and cached middleware authorization. Revocation races are checked at execution; confirmation never grants permissions. A resource-set change or expired/mismatched confirmation cannot purge any project. A rollback after mismatch does not broaden the token's original scope; successful consumption is one-use.

Direct sensitive requests without proof return 423 `security_step_up_required`, no-store. The existing dialog confirms an available factor and returns `security_retry_required` to the caller; the original mutation is never replayed automatically. Users review and submit explicitly. CSRF/timeouts also never replay. Destructive scope conflicts return 409; UI refreshes the list and asks again. Existing throttling and session blocking remain enabled.

## Team privacy and UI

Only the owner-only existing team endpoint projects `security: { passkey: boolean, totp: boolean }` for active members. Passkeys use batched withExists; TOTP uses the already-loaded confirmed factor fields. Response rows are explicit allowlists, no credential ID/QR/recovery/IP/security score. Pending invitation responses do not query or expose factor/account presence. Factor status never changes permissions, and the owner cannot toggle someone else's factors. Textual indicators are localized RU/EN/TR/ZH-CN and work on desktop/mobile and light/dark/system themes. Security actions use the existing toast service plus contextual blocking errors.

## Design references

Existing Laravel/Fortify and Passkeys actions remain the implementation boundary: [Laravel 13 Fortify](https://github.com/laravel/docs/blob/13.x/fortify.md). The scoped reauthentication and protected factor-change policy follows [OWASP authentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html#reauthentication-after-risk-events) and [OWASP MFA factor changes](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html#changing-mfa-factors). No new public Fortify/signup routes or authentication dependency were introduced.
