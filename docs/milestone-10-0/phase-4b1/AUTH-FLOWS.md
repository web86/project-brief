# Authentication flows

## Email registration

`GET /api/onboarding/readiness` returns public booleans only. `POST /api/onboarding/register` accepts email, a confirmed password of at least 12 characters and RU/EN/TR/ZH-CN locale. Existing and newly registered addresses receive the same 202 response. Registration creates neither a session, workspace, membership nor claim. Password hashing also happens for duplicate addresses. Public endpoints have IP/address throttles.

The email contains `/admin/verify-email#token=…`: 32 cryptographically random bytes, SHA-256 at rest, 60-minute expiry, one-time consumption and current email binding. Fragment proof is kept in page memory and removed from the URL immediately, including same-tab navigation. Confirming requires authentication as that same account. Generic resend has a 60-second per-account cooldown. Authenticated pending accounts can correct an address with their current password when configured; correction invalidates previous proof. Password recovery does not verify the registration email.

On success, User/onboarding locks, email verification, OwnerProvisioning, pending claim and mandatory audit commit atomically. A consumed proof can be replayed by its owner without repeating provisioning or changing dates. Owner membership changes the security policy fingerprint, so the existing primary proof is refreshed for TOTP setup; it grants no MFA confirmation. Existing TOTP, recovery acknowledgment, passkey and fresh-session policies apply.

`GET /api/onboarding/state` and `/api/session` provide only that account's progress. Pending accounts cannot access projects, files, exports, integrations, other workspaces or MFA enrollment before email proof. Verified owners can complete existing account security. Their final screen has no Trial clock, apply action or business access. Existing workspace agreements continue to govern invited administrators; this phase does not invent a new global agreement.

## Social authentication

`POST /api/auth/{google|yandex|github}/start` begins public login. All providers use code + session-bound state + PKCE S256. The ten-minute attempt stores state/session/nonce hashes and encrypted verifier. Callback `/auth/{provider}/callback` consumes the attempt before exchange; replay, wrong provider/session, expiry and cancellation cannot authenticate. Callback redirects use fixed application paths and safe reason codes.

Only provider + issuer + stable subject identifies a returning account. A matching email on another User never links accounts automatically. Missing/unusable email asks for a real address at `/register?providerEmail=1` and requires local email proof. An authenticated provider profile alone is not mailbox assurance. Google signed `email_verified` and GitHub verified email can supply assurance; Yandex `default_email` cannot.

Primary provider authentication hands off to AccountSecurity::passwordLogin, which preserves existing second-factor requirements. Existing bindings remain usable with registration closed when independently configured. Unknown identities cannot create a production account. Provider access/refresh tokens are not persisted.

## Connections, invitations and recovery

`POST /api/auth/{provider}/link` and `DELETE /api/auth/{provider}` require an authenticated strong, fresh MFA/passkey proof. Intent and actor are server-bound; the public start route cannot request linking. A subject belonging to another account cannot be reassigned. Unlinking the last usable method is refused; configured password, a passkey or another configured provider can provide fallback. Passwordless social owners can set a password through the existing protected account endpoint.

A valid developer invitation retains its existing session context through provider authentication. Invited users join that workspace through the existing acceptance/agreement flow and get no extra owner workspace or Trial. A pending self-registrant who accepts an invitation before provisioning has onboarding state retired as `invited_developer`. Legacy users, clients, external SSO and API keys retain their existing paths.
