# Professional local demo

This is a separate synthetic installation of the real Dev2Done UI. It does not connect to Jino, read either repository `.env`, reuse an application key, or import existing accounts/data. Public demo routes are disabled by default. No commercial enforcement or real workspace access rules change.

## Start on this Mac

Prerequisites: Docker/OrbStack, Python 3, Node compatible with `package.json`, installed locked frontend and backend dependencies, and a PHP 8.4 CLI image with PDO MySQL/SQLite, GD/WebP, curl, mbstring, XML, intl and zip. The already installed `projectbrief-phase2-php84-gd:local` image was used for QA (PHP 8.4.26). A recipe for another machine is [Dockerfile](Dockerfile).

```sh
cd /Users/sonoma/Desktop/project-brief
npm run demo:build
bin/demo up
```

If the image is missing, build it first with `bin/demo build-image`. `DEMO_PHP_IMAGE` can select a compatible local image. Dependencies can be restored from the existing lockfiles with `npm ci` and Composer `install --no-scripts` in `api/`; the launcher intentionally does not install dependencies or run commands against the repository application's environment.

Verified local URLs:

- [Demo chooser](http://127.0.0.1:18080/demo)
- [Developer Demo](http://127.0.0.1:18080/demo/developer)
- [Client Demo](http://127.0.0.1:18080/demo/client)
- [Full local QA login](http://127.0.0.1:18081/admin/login)

Demo visitors need no registration. The developer persona is the synthetic studio owner, with workspace authority and **no platform/PlanAuthority privileges**. The client persona is assigned to Northstar Commerce only. The entry endpoints rotate a separate demo session; they do not create business records or access links. Language and theme are local browser preferences.

## Private full-QA access

Read `.tools/demo-runtime/api/storage/demo-access.private.json` locally. It is ignored by Git and mode `0600`. It contains a generated password, owner email and TOTP secret. Add that secret to an authenticator for the normal owner login; TOTP and recovery-code acknowledgement remain mandatory. No password or working API credential is committed.

The project administrator and developer listed in the report use the generated local password. Their project assignments demonstrate real permission differences. Use the owner for integrations, branding and administrator pages. Public view-only sessions cannot open account/security or platform administration.

The `18081` installation supports ordinary mutations for local QA. The launcher prevents outbound provider traffic for **both** installations. The view-only server on `18080` blocks mutations before controllers and uses a SQL user with only `SELECT` permission.

## Restore, refresh, inspect and stop

```sh
bin/demo reset    # guarded fresh migrations + deterministic synthetic seed; refresh servers
bin/demo audit    # all foreign keys, cross-project/workspace references, lifecycle, counts, hashes
bin/demo sync     # refresh source/build copies; preserve dataset
bin/demo down     # remove only labelled demo containers; retain the dedicated DB volume
```

After frontend edits run `npm run demo:build` before `bin/demo sync`. The demo build disables env-file loading and inherited `VITE_*` values; API calls remain same-origin. The normal `npm run build` retains its existing behaviour.

Only the explicitly marked `projectbrief_demo` database on loopback with `demo_writer`/`demo_reader` and storage under `demo-runtime` is accepted. Reset refuses production environments, reader mode, a missing/mismatched private installation marker, and unexpected non-demo account identities. The seeder also refuses a populated database. Never invoke generic destructive commands against another environment.

Files and generated secrets live under `.tools/demo-runtime/`; demo frontend output is `.tools/demo-build/`. MariaDB data lives in the named `projectbrief-professional-demo-data` volume. Existing source `.env`, databases, files and Docker volumes are not reused. `down` preserves the dataset; do not remove the volume unless intentionally discarding this demo.

## Dataset

Harborline Digital Studio is fictional. All content is English; all contact addresses and website/webhook destinations use reserved `.test` domains. No addresses, phone numbers, third-party logos or actual client information are included.

| Content                                          |      Count |
| ------------------------------------------------ | ---------: |
| Workspaces / human developers                    |      1 / 8 |
| Projects / tasks                                 |   10 / 150 |
| Client profiles                                  |         22 |
| Task history / comments                          |  850 / 414 |
| Client-visible handoff attachments               |         20 |
| Incoming requests / reviews                      |     14 / 8 |
| External AI agents / terminal runs               |      3 / 6 |
| API credentials / webhook endpoints / deliveries | 4 / 2 / 10 |
| Integration sources / recent operation records   |     3 / 14 |

Projects range from discovery to completed delivery. Evolve Fitness is inactive, using the real project lifecycle instead of inventing an unsupported archive state. Tasks use `new`, `clarification`, `approved`, `in_progress`, `review`, `done`; approved work has client approval history. Section positions are normalized through `ProjectStructure`. Dataset UUIDs and content/dates are stable. Password hashes, encryption and unknown token preimages intentionally vary across resets.

Morgan Ellis owns the studio. Avery Chen is project administrator; Jamie Brooks, Riley Bennett, Alex Rivera, Jordan Quinn, Taylor Reed and Casey Parker cover frontend, backend, design, QA, integrations and content. Other human accounts receive only their real assigned projects and effective permissions.

The agent workflow is synthetic database history, with internal messages, failed/cancelled runs, revised instructions, submitted reports and reviewed developer publications. No agent/provider executes code. Historical public agent comments are excluded from client responses under the current privacy contract, without altering stored records. Projects without agent activity retain the existing empty-panel suppression.

## Isolation and limitations

PHP and MariaDB share an internal Docker network without an Internet gateway. A fixed-upstream Nginx gateway exposes only loopback ports. Laravel HTTP requests, outgoing mail and background work are additionally blocked in demo; provider configuration is empty. No workers or external delivery processors run.

Stored API credentials have unknown preimages and expired dates. Webhook endpoints and SSO remain disabled in storage. `DemoIntegrationPreview` supplies clearly synthetic masked credentials and presentation states to the real integrations page; it never updates authentication/delivery configuration. Links to external origins are prevented in the demo browser.

The public server blocks all POST/PUT/PATCH/DELETE requests, bearer API routes, access-link and SSO redemption, security challenges, private exports, read markers and platform operations. A query boundary rejects database writes attempted by allowed GET handlers; MariaDB SELECT-only grants provide an independent boundary. Some existing action controls remain visible so visitors can inspect the actual application; attempting them reports “Demo · View only” and never reports a successful save.

This is a local QA installation, not a public hosting/deployment package. The private full-QA port must not be published. Enabling a future dedicated public demo requires an explicit operator decision and separate hosting review; normal production defaults remain off. No production or Jino verification is claimed.

## Tests and screenshots

```sh
bin/demo test
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs npm run test:demo
npm test
```

`bin/demo test` uses **different** disposable MariaDB schemas `projectbrief_agent_ux_full_qa` and `projectbrief_m10_upgrade_qa`, with a separate test user. Run one backend DB suite at a time. Do not run reset or full-QA mutations during the browser database-hash comparison.

Screenshots and the machine-readable walkthrough report are local under `artifacts/demo/`, ignored by Git. See [TESTS.md](TESTS.md) and [UX changes](../ux-polish/README.md).
