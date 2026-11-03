# Dev2Done — Milestone 10.0 / Phase 4B.1

Local implementation based on code commit `ba216f663b0294c1ca4eb988380ac0c2b72fd63b`, with its existing artifact documentation retained. Branch: `codex/milestone-10-0-phase-4b1`. Release: v0.9.15.

Email/password self-registration, email proof, Google OIDC, Yandex ID and GitHub OAuth now use the existing User, session, MFA, recovery, invitations and OwnerProvisioning boundaries. Only newly created self-service accounts receive onboarding metadata. Existing unverified owners, developers and client MagicLink/SSO are not reclassified.

`REGISTRATION_ENABLED=false` is the default. Production registration is hard disabled even if the env value is true. Trial activation is hard disabled in every runtime environment; the requested activation env key cannot override this. Local/staging registration can prepare a verified owner and pending durable claim, but cannot open workspace business routes or start a countdown. The only period-writing onboarding adapter lives under tests, checks an isolated database and is excluded from production ZIPs.

No automatic policy publication, Compatibility conversion, expiry/fallback, renewal, suspension, quota enforcement, payment or GitHub repository integration is included. No push, merge, deployment, Jino access or production migration is authorized or performed.

- [Authentication flows](AUTH-FLOWS.md)
- [Provider setup](OAUTH-SETUP.md)
- [Trial identity and time contract](TRIAL-ELIGIBILITY.md)
- [Security and privacy](SECURITY.md)
- [Validation](QA.md)
- [Artifacts](ARTIFACTS.md)
- [Jino update and rollback](PRODUCTION.md)
- [Phase 4B.2 prerequisites](NEXT.md)
