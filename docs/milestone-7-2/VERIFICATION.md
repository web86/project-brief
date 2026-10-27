# Milestone 7.2 verification — 27 October 2026

Branch: `codex/milestone-7-2-flexible-account-security`, based on actual local 0.7.1 HEAD `8c7e45e`, descendant of hotfix `22db2f3d2e67fcaff5e5fee80b23d877a7250c37`. Existing 0.7.1 archive is retained. All databases, accounts, codes and keys used here are disposable. No push, merge, GitHub issue mutation or deployment.

| Check                                                                                             | Observed result                                                                                                                                   |
| ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full PHP 8.5.10 suite / SQLite                                                                    | 744 passed, 9008 assertions                                                                                                                       |
| PHP 8.3.36 security + additive migration / SQLite                                                 | 52 passed, 751 assertions                                                                                                                         |
| PHP 8.4.26 security + additive migration / SQLite                                                 | 52 passed, 751 assertions                                                                                                                         |
| PHP 8.4.26 / MariaDB 11.8.9 security, flexible policy, access links, invitations                  | 66 passed, 845 assertions                                                                                                                         |
| Actual 0.7.1 schema -> new migration up/down, twice, PHP 8.4 / MariaDB                            | 1 passed, 21 assertions; encrypted factors, passkeys, project memberships and client tokens byte-preserved                                        |
| Frontend Node suite                                                                               | 274 passed; serializers, explicit resubmit, error codes and four-locale key parity                                                                |
| Local HTTPS Chromium / real Laravel database cookies and CSRF / resident UV virtual authenticator | 15 scenario groups passed; exact production origin/RP routed entirely to localhost                                                                |
| MariaDB concurrent workers                                                                        | 5 groups × 6 processes; one-use recovery/TOTP/WebAuthn, factor deletion/version revocation, execution ACL recheck after cached permission revoked |
| UI                                                                                                | Account security and team indicators: RU/EN/TR/ZH-CN, 1440 light + 390 dark, explicit HTML lang check, no overflow; system theme switching        |
| Formatting                                                                                        | Pint on changed PHP; Prettier on changed frontend/harness/docs; diff whitespace check                                                             |

The full native suite covers owner access without Passkey, all four developer factor combinations, password/TOTP enforcement, reset/invitation bypass, factor alternatives, final-key removal, owner TOTP non-disable, promotion/demotion/workspace selection, actual old strong session, role flip invalidation, five-minute/session/version binding, client/integration boundaries, one-use/replay/UV/origin/RP/counter rejection, authorization and scoped destructive confirmations. Business tests simulate already completed login; protocol tests use real factor endpoints and signed credentials.

Browser assertions cover original owner TOTP setup -> recovery acknowledgement -> full access without Passkey -> fresh recovery confirmation without password -> explicit retry -> real UV registration and later UV login. Cancellation and created-but-unsaved credential tests retain access and use a new challenge on explicit retry. Voluntary developer TOTP blocks password-only business access; a recovery code completes login, then fresh MFA plus current-password alternative permits disabling. Existing client link works afterwards. Firefox runs the actual serializer with a minimal mocked authenticator; it is not a native hardware ceremony.

Screenshots and sanitized JSON summaries are retained in this directory; runtime logs and failure screenshots are excluded. The ZIP builder copies only its production guide, never this QA material.

## Additional probe boundaries

A broader PHP 8.4/MariaDB probe ran 104 tests: 101 passed and three failed outside this change: image fixture requires GD absent in this test image; the unchanged historical project-trash rollback tries dropping an index needed by a MariaDB FK; the unchanged integration rollback fixture collides with later FK-dependent tables. These do not affect the new additive migration, tested separately on the actual old schema. Their full SQLite/native tests pass. They were not silently reported as a successful full MariaDB suite, and historical migrations were not rewritten to hide the findings. The final supported focused MariaDB suite above passes. The local PHP 8.4 image is not a full production runtime certification (no GD).

Physical Touch ID, native Safari/WebKit ceremony and live Jino filesystem/database/proxy/configuration were not tested. Bundled WebKit is incompatible with this host (`PushAPIEnabled` startup setting); run M7_TEST_WEBKIT=1 on a compatible host. Safari API shapes are covered by frontend serializer tests. The production origin test routes every request to local HTTPS; it never contacts Jino.

## Reproduction

```sh
cd api
php artisan test --compact
php artisan test --compact tests/Feature/AccountSecurityTest.php tests/Feature/FlexibleAccountSecurityTest.php tests/Feature/FlexibleSecurityMigrationTest.php
vendor/bin/pint --dirty --format agent
cd ..
npm test
npm run build
PLAYWRIGHT_MODULE=/absolute/path/to/playwright/index.mjs M7_TEST_PRODUCTION_ORIGIN=1 M7_EVIDENCE_DIRECTORY=/private/tmp/project-brief-m72-evidence node tests/account-security.browser.mjs
```

For races, use only a disposable MariaDB database named `projectbrief_m7_security_qa`, provide DB_CONNECTION=mysql and its connection variables, then `node tests/account-security.concurrent.mjs`. The script refuses other database names and creates/migrates test data. Migration testing can use M72_MIGRATION_MYSQL_DATABASE=projectbrief_m72_upgrade with disposable credentials allowed to create/drop its dedicated random database. Never run either against an installed site.

Canonical package verification is mandatory and will be recorded in RELEASE-ARTIFACT.json after the clean-tree builder and extracted-ZIP smoke finish. Deployment and rollback instructions are in PRODUCTION.md; do not infer live production readiness from local results.

The first extracted candidate also exposed a new CLI connection regression, now corrected and documented in AUDIT.md. A two-PDO feature test reproduced its duplicate-email symptom before the fix, then verified model-connection binding and atomic rollback after forced membership failure. Focused security/migration/workspace checks after correction: 60 passed, 820 assertions. Final full-suite/package results are recorded with the final artifact.
