# Minimal MODX 2 / external PHP integration

These examples are server code, not a MODX extra or a public approval endpoint. Copy the helper into `core/components/projectbrief/ProjectBriefRequests.php` and private configuration into `core/config/project-brief.private.php`; both must be outside the document root and readable only by the application account. PHP/cURL, HTTPS and a valid CA bundle are required. The helper uses PHP 7.4-compatible syntax; the standalone smoke uses PHP8+. Production target remains PHP8.4. Never disable TLS verification or expose bearer tokens in HTML/JavaScript, form values, URLs, logs or exception pages.

## Configure credentials

In Project Brief, the workspace **owner** creates a persistent source named `web86.site` while issuing the form token with **only** `requests:create`. Store it in `PROJECT_BRIEF_REQUEST_CREATE_TOKEN` on the MODX server. Use separate server credentials for `requests:read` and `requests:approve` in `PROJECT_BRIEF_REQUEST_READ_TOKEN` and `PROJECT_BRIEF_REQUEST_DECISION_TOKEN`. The decision scope permits provisioning via approval only; do not grant direct project provisioning to the public form integration. Replacement form tokens must select the same source UUID.

[private-config.example.php](modx/private-config.example.php) demonstrates configuration with environment variables. On hosting without environment support, a protected non-public PHP file can return these values; never commit the real file. Do not put configuration under `assets/`, public snippets' HTML or a client-downloadable JSON file.

## Receive the public form

1. Retain the site's existing FormIt validation, CSRF/session checks, spam controls and request rate limit. At minimum validate name, email, project name and optional http/https website before the hook. A server-issued submission nonce is for deduplication and does not replace CSRF or spam protection.
2. Render [submission-field.php](modx/submission-field.php) as an **uncached** MODX snippet inside the form. It returns a non-secret hidden `pbSubmissionId`, bound to the visitor's server session for24hours, with at most20 retained submissions. Reuse that same field on transport retries. Do not change externalId merely because a response was lost.
3. Register [formit-hook.php](modx/formit-hook.php) as a FormIt post-validation hook. Inputs: `name`, `email`, `company`, `website`, `project_name`, `description`, `pbSubmissionId`. The hook fixes language to `ru` for web86.site; another trusted server configuration may choose `en`, `tr`, `zh-CN`.
4. A successful hook supplies `projectBriefRequestId` to the server-side form context and returns true. Redirect to a simple neutral receipt: **«Заявка принята на рассмотрение»**. Do not tell visitors that an account/project already exists or give them an integration secret. Public form data does not verify a mailbox.
5. The studio owner reviews the request in Project Brief. Approval defaults to sending the existing 7-day invitation. The email's Open project button verifies the link and opens the permanent project URL immediately. A rejected submission grants no access. A deliberate new request uses a new submission ID; an identical repeat retains its original request/decision.

A changed payload under a retained ID returns409. Show a generic error and resolve the existing request from trusted server code before creating a new submission. After timeout/429/500, keep the same body/externalId/idempotency key. The helper never silently retries with a new identity.

## Trusted administrative actions

[list-requests.php](modx/list-requests.php) demonstrates a list handler. [admin-handler.php](modx/admin-handler.php) demonstrates approve/reject in a Manager processor/connector. Both check a real MODX `mgr` session and the privileged `access_permissions` permission. Adapt to an equally restrictive explicit administrative ACL; ordinary public users/page editors must never receive this capability. The mutation handler also checks POST and a constant-time session CSRF token. Generate its CSRF token on the authenticated server page (e.g.32 random bytes), and retain an operation key per decision across retries. These tokens are local CSRF/operation identifiers, **not API credentials**.

Do not mount either handler as a public form endpoint. Protect list/details too: requests include personal data and internal reasons. Administrative response output has `Cache-Control: no-store`. The protected request result contains projectId/clientId/projectUrl and invitation state. API or UI approval returns the same project and original decision author on repeat; an opposite final decision is409.

## External smoke without a MODX user

The CLI-only [smoke.php](modx/smoke.php) uses the same [cURL helper](modx/ProjectBriefRequests.php). Set `PB_ORIGIN` and `PB_TOKEN` through a protected environment, not command arguments/history. Do not paste a real token into the example command. Store the test payload in a protected local JSON file, or pipe synthetic data through stdin:

```sh
# Form credential: requests:create, source web86.site
php docs/milestone-8-1/modx/smoke.php create < create-input.json
# Read credential: requests:read
php docs/milestone-8-1/modx/smoke.php list < filters.json
php docs/milestone-8-1/modx/smoke.php get < request-id.json
# Decision credential: requests:approve, trusted administrator/server only
php docs/milestone-8-1/modx/smoke.php approve < approval-input.json
```

`create-input.json`:

```json
{
  "idempotencyKey": "web86-request-105",
  "body": {
    "externalId": "web86-request-105",
    "client": {
      "name": "Test Client",
      "email": "test@example.test",
      "company": "Example Ltd",
      "website": "https://example.test"
    },
    "project": { "name": "Test website", "description": "A service catalog", "language": "ru" }
  }
}
```

`filters.json`: `{"status":"pending","page":1,"perPage":25,"sort":"desc"}`.

`request-id.json`: `{"requestId":"actual-request-uuid"}`.

`approval-input.json`: `{"requestId":"actual-request-uuid","sendInvitation":true,"idempotencyKey":"approval-105"}`. Reject uses action `reject` and optional `reason` with its own stable operation key.

Default mode allows only HTTPS. `PB_ALLOW_LOCAL_HTTP=1` permits loopback HTTP **only in CLI** for the disposable QA harness; it is never needed for web86.site. The supplied browser suite launches two local PHP processes and a local SMTP sink, runs this real external CLI create/list/get/approve helper, drives owner UI approval, follows the invitation and checks client project access. No MODX account, plugin installation or live production call is required.

## Optional webhook status updates

Subscribe a trusted server endpoint to the three request events using `projectFilter:all`; deduplicate by event/delivery identity and verify the existing raw-body HMAC/timestamp with [the existing verifier](../milestone-5-0/verify-webhook.php). Store the initial requestId/externalId in the external order; an approved event supplies projectId/projectUrl. No contact data or request text is in the event. Alternatively call details/list with the read credential from trusted server code; do not add another polling loop inside Project Brief.
