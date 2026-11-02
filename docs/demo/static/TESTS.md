# Verification

All data and test environments are local and synthetic. Jino/production was not accessed.

- Main backend: PHP 8.4.26 / MariaDB 11.8.9, **1109 tests / 16267 assertions**, passed after static exporter/adapter changes. Focused ProfessionalDemo safety tests also passed (5 tests / 64 assertions).
- Main frontend: **445 tests**, normal Vite production build, and focused production exclusion/static transport/schema tests passed. The normal bundle contains no Harborline dataset, static snapshot transport marker or DemoEntryView chunk.
- Ordinary Laravel security browser harness: **23 scenarios**, passed, including real virtual UV Passkey, TOTP/recovery, step-up, existing permissions/client session and Firefox engine compatibility (mock authenticator only for Firefox).
- Two independent exports produced identical manifest bytes, SHA-256 `f09cacdaf090c0e5e3cb1b62914d49bae7918a9fbcbc93678be68d4521111cf1`. Source DB hashes matched before/after export. This reviewed public projection fingerprint is pinned alongside 52 contract schemas.
- JSON validation covers 314 developer / 26 client paths, all ten projects, 150 tasks, fifteen scoped client tasks, UUID/section/date relations, hash references, public history allowlist, `.test` contacts and private-field/secret rejection.
- Static browser harness uses **only a Node HTTP file server**. Both roles, all project/task families, profile/security/agreement/branding/administrators/notifications, requests/reviews, API/Webhooks/SSO/Agents/recent operations, team/clients/discussions/structure/project settings/export, search/filters, history/direct reload, language/themes and 390/375 mobile widths are exercised. It records every browser request and fails on backend API HTTP, non-read methods, external connections, JavaScript/console errors, broken images and overflow. Revoke is attempted and must return view-only without false success; security/profile/agreement mutation controls are disabled.

The final ZIP is extracted and this same harness runs against its actual compiled assets/snapshots. Exact archive identity and final results are in `ARTIFACT.md`. Screenshots and machine-readable results stay in `artifacts/demo/static/`; local regression logs remain outside the upload ZIPs. Profile, security, agreement and client-task screenshots were visually inspected.

Not verified: physical mobile devices, hardware Touch ID, real Safari/WebKit, Jino Apache settings or deployment. Static UI tests use Chromium viewport emulation. No operational SSO/mail/AI/Push/Telegram integration is enabled or simulated as a network service.
