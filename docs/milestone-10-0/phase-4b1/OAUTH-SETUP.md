# Operator setup — optional, server-side only

No provider credentials are required for upgrade or automated QA. Configure each provider independently in the retained private `.env`; unset credentials disable that provider. Never place secrets in Vite variables, plan JSON, release manifests or Static Demo.

```dotenv
REGISTRATION_ENABLED=false
TRIAL_ACTIVATION_ENABLED=false
ONBOARDING_SUBJECT_RETENTION_DAYS=
GOOGLE_LOGIN_CLIENT_ID=
GOOGLE_LOGIN_CLIENT_SECRET=
GOOGLE_LOGIN_CALLBACK=
YANDEX_LOGIN_CLIENT_ID=
YANDEX_LOGIN_CLIENT_SECRET=
YANDEX_LOGIN_CALLBACK=
GITHUB_LOGIN_CLIENT_ID=
GITHUB_LOGIN_CLIENT_SECRET=
GITHUB_LOGIN_CALLBACK=
```

Choose a separately approved Trial subject retention horizon before local/staging eligibility testing. Empty or invalid retention prevents verified claim eligibility; it does not adopt a retention policy automatically. Synthetic tests explicitly choose 365 days. No command automatically deletes durable aliases at the horizon.

Use one canonical origin from APP_URL, including the exact port when present. Callback scheme, host, port and path must match; credentials, query and fragment are prohibited. Production/staging callbacks require HTTPS. HTTP localhost/127.0.0.1 is allowed only in local/testing. No `return_url` is accepted. Serve the SPA and authentication callbacks on the same approved origin. Behind a proxy retain the existing trusted-proxy and secure session configuration.

| Provider app            | Callback for the example production origin      | Scope                    | Minimum setup                                                                                                |
| ----------------------- | ----------------------------------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------ |
| Google web OAuth client | `https://brief.web86.site/auth/google/callback` | `openid email profile`   | Configure consent/authorized test users as appropriate; register exact redirect URI; enable OIDC web client. |
| Yandex ID OAuth web app | `https://brief.web86.site/auth/yandex/callback` | `login:email login:info` | Register exact redirect URI and request only identity/email access.                                          |
| GitHub OAuth App        | `https://brief.web86.site/auth/github/callback` | `user:email`             | Set homepage and exact authorization callback; no repository permissions.                                    |

Google uses the official authorization/token endpoints and fixed HTTPS JWKS. RS256 signature, kid, issuer, audience/azp, integer expiry/issued-at, nonce and stable sub are checked. Yandex user information must match the configured client_id and stable id; local mailbox proof remains necessary. GitHub reads `/user` plus `/user/emails`, preferring a verified primary address and binding the numeric id rather than login name. Placeholder/noreply addresses cannot become account email.

Provider failures fail closed and permit email fallback. Google JWKS cache lasts five minutes; an unknown key invalidates the cache and rejects that attempt, allowing a fresh retry. No live OAuth was used in automated validation. Real consent-screen/app approval and actual SMTP delivery remain operator acceptance checks for the future rollout.

Official references: [Google OIDC](https://developers.google.com/identity/openid-connect/openid-connect), [Yandex code + PKCE](https://yandex.ru/dev/id/doc/ru/codes/code-url), [Yandex user information](https://yandex.ru/dev/id/doc/ru/user-information), [GitHub authorization + PKCE](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps), [GitHub email API](https://docs.github.com/en/rest/users/emails?apiVersion=2022-11-28).

Existing SMTP configuration remains authoritative. The application refuses the Laravel log mail transport for secret-bearing account emails. Configure sender/delivery in the existing mail settings, then verify readiness through primary-only Registration and Trial diagnostics. Do not expose mail credentials there.
