# Local verification — 2 November 2026

All data and credentials used here are synthetic. Production/Jino was not accessed. The local installation uses PHP 8.4.26 and MariaDB 11.8.9; frontend checks use the installed Node/Vite/Vue lockfiles and Chromium via Playwright.

| Check                                      | Result                                                                                                                          |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| Full backend regression, PHP 8.4 + MariaDB | 1107 passed, 16263 assertions, 0 failures/errors/skips                                                                          |
| Full frontend regression, `npm test`       | 441 passed, 0 failures/skips                                                                                                    |
| Normal production frontend build           | Passed; existing large-chunk advisory only                                                                                      |
| Separate env-free demo build               | Passed; same-origin API, no root env loading                                                                                    |
| Platform/plan browser regression           | 6 RU/EN × light/dark/system scenarios passed; no page errors                                                                    |
| Additional platform sidebar coverage       | 1440/1280/1024/390/375, browser back, active menu, ordinary platform-admin restrictions, mobile drawer/Escape passed            |
| Developer + Client Demo walkthrough        | Passed across 1440/1280/1024/390/375; light/dark/system; language switching; real task/project/detail/settings pages            |
| Demo browser privacy/network checks        | No private agent/client-cost fields in client JSON; no external browser requests; no console/page errors                        |
| Demo database immutability                 | Full database hash identical before/after the browser walkthrough                                                               |
| Independent MariaDB reader grant           | UPDATE rejected with SQL error 1142 outside HTTP middleware; `WHERE 1=0` ensured no rows could change                           |
| Workspace tooltip                          | Mouse/focus, Escape, native drawer, touch at 375 px, visualViewport scale 100/200/300% passed                                   |
| Normal owner login in full QA              | Real password → pending MFA → TOTP challenge → authenticated; recovery acknowledgement retained; optional recommendations false |
| Dataset integrity                          | 0 orphans and 0 cross-project/workspace references; all required counts and lifecycle checks passed                             |
| Fresh migration/seed/reset                 | Repeated real reset succeeded; counts and deterministic content hash matched                                                    |
| Formatting                                 | Prettier, Pint dirty files, `git diff --check` passed                                                                           |

## Privacy and security coverage

`ClientHistoryPrivacyTest` checks agent assignment/removal, requested/started/cancelled runs, reports, future unknown events, admin-authored internal events, legacy public agent comments/files, approved human publication, client history values, discussions, exports and live notifications. The client sees only the allowlisted public history and the separately published developer comment. The original internal records remain in the database and developer responses.

The existing agent API/chat/migration/privacy regressions now enforce the new rule for historical records too. They continue verifying that legacy flags, raw records, credential scopes, private collaboration and human publication are preserved. Existing client export tests cover server data allowlists and DOCX/PDF boundaries.

`ProfessionalDemoTest` exercises both personas against the real routes. It checks manual POST/PUT/PATCH/DELETE attempts, manually supplied bearer credentials, read-marker/export calls, security challenge GETs, SSO/access-link redemption, platform/account access, foreign client projects/tasks and developer endpoints from a client context. A future GET handler that tries to write is rejected by the database query boundary. Business/history/read-marker/credential/delivery/challenge snapshots remain unchanged during browsing.

Demo presentation adapters are checked separately from stored state: synthetic connection states and masked credentials do not unexpire any of the four credentials or enable actual endpoints/SSO. The original application outside demo remains covered by the complete regression suite.

The ordinary security matrix covers password-only, TOTP-only, passkey-only and combined developer accounts, plus mandatory owner enrollment and recovery acknowledgement. Optional recommendations are skipped for suitable protected accounts without granting step-up or relaxing enrollment.

## Reproduction

```sh
npm run demo:build
bin/demo up
bin/demo audit
bin/demo test
npm test
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/demo.browser.mjs
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/demo-qa.browser.mjs
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs node tests/platform-plans.browser.mjs
```

The full suite uses only `projectbrief_agent_ux_full_qa` and the existing migration fixture schema `projectbrief_m10_upgrade_qa` inside the dedicated MariaDB container. The public `demo_reader` has SELECT only on `projectbrief_demo` and no access to test schemas. Run backend suites serially. Public walkthrough hashes must not overlap reset or mutable QA actions.

Stable task-content hash after reset:

```text
9a39a84bb3111143a29505bacb254b15268393c08975d170d7e78240ee6af77c
```

Password hashes, encrypted MFA material and unknown credential preimages intentionally vary between resets; only before/after **browsing** requires the full database hash to stay identical.

## Screenshots reviewed

Local, Git-ignored files under `artifacts/demo/` include the chooser, studio/project/task, agent conversation, clients/team, discussions, requests/reviews, administrators, API credentials, Webhooks/Agents/SSO/recent operations, branding, notifications, client project/task/history/discussions, both roles at mobile widths and dark theme, and full-QA account profile. Actual images were visually reviewed for layout, legible branding and client privacy.

Platform screenshots are under `artifacts/milestone-10-0-phase-2/browser/`: catalog/sidebar at all five widths, published editor, policy/transition controls and RU/EN theme variants. The browser scenario intentionally produces a revision-conflict response to verify stale-edit protection; this expected 409 is not a browser JavaScript error.

## Limits

- These are local Chromium checks. Safari/iOS WebKit and Firefox were not part of this acceptance run. visualViewport scaling is emulated, not a manual OS/browser zoom session.
- The full Cartesian product of every screen width, theme and language was not run; the coverage above states the actual combinations.
- This work does not deploy, publish a public demo or change Jino/production. Only loopback URLs were verified.
- The standalone Dockerfile is a reproducibility recipe; the already installed, compatible PHP 8.4 image was used for these tests. An uncached build of that recipe was not required for this machine.
- API/agent/webhook/SSO activity is synthetic. No provider execution, real API credentials, live webhook delivery or external email/Telegram/Push is represented as a verified live integration.
- Existing mutation controls may remain visible in view-only screens. The API client reports the view-only restriction and the server/SQL boundaries prevent saving; no successful save is reported.
