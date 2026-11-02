# Production release — UX polish

Patch v0.9.11 follows v0.9.10 / Milestone 10.0 Phase 2. The source baseline is `13e5a63`; the immutable source commit and artifact SHA are recorded in `ARTIFACT.md` after the standard builder succeeds. This directory concerns only the ordinary application release. Static Demo is prepared separately afterwards.

Build with the existing mechanism:

```sh
./bin/build-release
```

The builder requires a clean committed working tree, installs locked production vendor into staging, runs frontend/backend checks, creates `public/` + `private/project-brief-app/`, scans private material, builds checksums, tests and extracts the actual ZIP, verifies every checksum and runs production-vendor security, runtime, plan-domain and UX/privacy tests. No production connection or deployment is part of the build.

Optional packaged browser checks use `PLAYWRIGHT_MODULE`, `RELEASE_SECURITY_BROWSER_QA_DIRECTORY` and `RELEASE_BROWSER_QA_DIRECTORY`. Platform browser scenarios accept `UX_PACKAGE_ROOT=/absolute/path/to/extracted/package` and then use the packaged compiled frontend/runtime, not Vite source. QA-only factories are added exclusively to a disposable test copy; they are absent from the ZIP.

The production frontend omits local demo entry routes/chunks. The backend optional demo module is physically excluded, including provider, identity middleware, services, commands, route definitions and configuration. The normal runtime returns 404 for demo entry/API routes even if a caller/operator supplies demo flags. Test fixtures, Docker files, the local launcher, snapshots, synthetic business data and credentials are absent.

No migration or required environment-variable change is introduced relative to installed Phase 2 (`3306015`). No plan seed, authority reassignment, subscription initialization, rollout or enforcement transition is performed. The existing explicit `PlansFoundationSeeder` remains a runtime dependency for separate initial setup; ordinary upgrade never invokes it.

Use [PRODUCTION.md](PRODUCTION.md) for the operator update/rollback procedure. [TESTS.md](TESTS.md) records archive and regression evidence. The future static artifact has its own docs and rollback; neither archive depends on the other.
