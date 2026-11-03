# Phase 4B.1 UX Polish — Registration, Security and Account Readiness

Base: clean local `ee6fa303b4adbe2f757a5f451fc4526e1fd5eb34`, following runtime `2190f00`. Branch: `codex/phase-4b1-ux-polish`. Production version0.9.16; separate Static Demo v4 because shared security components changed.

Registration now uses one compact card, consistent44px controls, local accessible field errors, autocomplete/inputmode and a short Trial note. Global request errors reuse reserved subtitle space. Closed registration renders an information state instead of a signup form. Providers appear only when the server marks them configured. Existing local Google/Yandex/GitHub SVGs are shared across entry and security screens; no CDN, SDK or UI framework was added.

Mandatory onboarding shows only the required current factor step: email → TOTP → recovery acknowledgment → existing required agreement, if applicable → account status. Active TOTP is not offered for replacement during onboarding. Reopening recovery requests existing codes explicitly and does not regenerate them. Optional Passkeys and social linking live in permanent settings.

Completion is a compact520px card with three confirmed-factor rows, a clear waiting-for-activation state and a real link to account security. It offers no workspace action or countdown. It is rendered only when server progress says complete. Returning users go straight to this state; setup success toasts are suppressed and prior success notices are dismissed on confirmed completion, leaving warnings/errors intact.

`/admin/account/security` is a personal page outside the workspace shell. A prepared owner can use existing protected account/factor APIs without the old notifications/workspace bootstrap. The onboarding middleware adds only this browser path for verified accounts; its business API restrictions remain unchanged. Unverified users and users requiring MFA still return to the appropriate mandatory step. Legacy/Compatibility owners retain full factor, password/email and business access under their existing rules.

The only progress-contract additions are `step`, `checks`, `agreementRequired` and `registrationEnabled`. Checks read confirmed email, TOTP, recovery acknowledgment, actual active provisioned membership and the existing WorkspaceAgreements predicate. These reads neither provision resources nor change claims, dates, roles, policies or permissions. OAuth linking returns to the personal security page; provider validation itself is untouched.

No schema/dependency/config requirement was added. Production `REGISTRATION_ENABLED=false` and `TRIAL_ACTIVATION_ENABLED=false` defaults and hard gates remain unchanged. Phase4B.2, commercial execution, policy publication, push, merge and deployment are outside this work.

See [QA](QA.md), [before/after](BEFORE-AFTER.md), [artifacts](ARTIFACTS.md) and [Jino update/rollback](PRODUCTION.md).
