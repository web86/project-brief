# Milestone 7.2 audit and implementation boundaries

Baseline: local `8c7e45e`, descendant of hotfix `22db2f3d2e67fcaff5e5fee80b23d877a7250c37`; clean before branching to `codex/milestone-7-2-flexible-account-security`. Existing 0.7.1 branch/archive preserved. Issue: https://github.com/web86/projects-manager/issues/7. No push/merge/deploy.

Audited before implementation: AccountSecurity, account session/security middleware, all security controller methods, AdminAuthController, SessionController, DeveloperInvitationController/DeveloperInvitations, PasswordRecoveryController, AccountController/AccountProfile, User/WorkspaceMember, CurrentWorkspace/ProjectPermissions, team controller, AccessLinkController/ProjectClientController, IntegrationController, ProjectTrashController/ProjectLifecycle, web routes, security forms/API transport, existing test authentication fixtures and package smoke.

Findings:

- `enrolled()` requires Passkey; state/passwordLogin/password-reset factor checks apply only to owners. These must become owner TOTP+saved recovery requirements plus globally enforced voluntary User TOTP on all password paths.
- Invitations call Auth::login/session regeneration directly; existing-factor accounts must retain strong authentication and invalidate fresh proof, while new accounts get ordinary password assurance.
- Existing proof combines login assurance with ten-minute freshness, omits explicit session/role binding, and survives credential changes. Keep login assurance separately from a five-minute sensitive-operation proof, bound to session/user/version/current membership policy. Registration never creates new step-up proof. A narrow recovery-enrollment grant allows saving recovery codes after confirmed TOTP without reusing the same OTP.
- Sensitive patterns currently cover personal notifications but omit client link management and permanent project deletion. Move execution checks after ACL and recheck under existing mutation locks. Keep ordinary work/preferences and stateless v1 bearer routes outside browser step-up.
- Permanent project deletion currently accepts a reusable boolean confirmation. Bind a short-lived server confirmation to workspace, exact operation and exact resource/set, then recheck ACL and consume atomically at execution.
- Team index is already owner-only. Add only allowlisted booleans using a batched `withExists` projection; pending invitation payload remains independent of account existence/factors. Avoid adding factor queries per member.
- UI currently hides TOTP from developers, requires owner Passkey and disallows final owner Passkey removal. Preserve toast/localization and cryptographic checks while exposing the new optional policy and safe alternatives.
- Keep the exact vendor-runtime allowlist and required classes from 0.7.1. Canonical ZIP verification must still run signed enrollment against extracted no-dev dependencies.

Chosen release: 0.7.2. Only additive schema for a global, one-time recommendation acknowledgement; no factor/secret/link/token resets. Final verification and package evidence will be recorded separately.

## Regression discovered by the extracted production smoke

The first 0.7.2 candidate was rejected by the canonical builder: first-install -> optimize/config:cache -> app:create-admin failed with a duplicate email. ConfigCacheCommand boots a fresh application; Eloquent's resolver and a cached DB facade can then reference different PDOs to the same SQLite file. The new membership invalidation event wrote via the facade while membership wrote via the model. A write lock caused a retry after autocommitted User insertion, leading to UNIQUE users.email. A two-PDO regression test reproduced the same error before the correction; it passed afterwards.

Membership invalidation now uses the member's own connection/schema builder. OwnerProvisioning also starts its transaction on the User model connection, retaining atomic User/workspace/membership creation after CLI configuration bootstrap. The regression asserts an active model transaction and forces membership failure to prove no orphan User/workspace survives. It complements the real packaged first-install smoke; no factor or session validator was weakened. The failed candidate produced no final release ZIP.
