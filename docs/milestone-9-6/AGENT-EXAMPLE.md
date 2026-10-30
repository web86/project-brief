# Demonstration external executor

See [example README](examples/README.md) and [standalone agent.mjs](examples/agent.mjs). Copy these two files to an independent directory/machine; only documented HTTP calls are used. There is no commercial LLM, npm dependency or application source import. It reads a permitted task, posts an agent-authored comment and report, and leaves the task workflow unchanged for a human reviewer.

The automated integration suite `tests/agent96.browser.mjs` exercises registration/token issue/team/task assignment using the real owner UI, launches this example in a separate Node process, checks human-visible authorship, real multipart file upload, concurrent idempotency, cross-project denial, unassignment/reassignment, rotation and disabled credentials against disposable MariaDB. It also runs against an extracted production ZIP when `M96_PACKAGE_ROOT` is set. Tests use synthetic secrets/fixtures and local servers only.

For webhook transport, `tests/agent96.webhook.mjs` runs the existing outbox processor against a disposable local TLS receiver, verifies HMAC and unique delivery headers, injects one HTTP 503, then verifies retry and cancellation after access revocation. The QA-only transport fixture pins the synthetic public hostname to the local receiver; production destination validation remains unchanged. Backend tests separately cover public-IP/SSRF restrictions.

Exact successful checks, environment and limitations are recorded in RELEASE.md after verification. No source-only test is treated as proof of external delivery or production behavior.
