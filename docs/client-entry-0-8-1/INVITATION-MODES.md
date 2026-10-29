# Invitation modes in 0.8.1

The existing endpoint remains `POST /api/v1/projects/provision`. Its fields, dedicated `projects:provision` scope, token switch, idempotency and workspace isolation are unchanged. `sendInvitation` remains a **required boolean**.

| Integration choice             | Request value             | Client entry                                                                                                                                                      |
| ------------------------------ | ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Send an invitation immediately | `"sendInvitation": true`  | Existing invitation email, valid for 7 days; its Open project button verifies the link and goes directly to the project                                           |
| Client requests a sign-in link | `"sendInvitation": false` | No initial email or login token is issued; show the returned permanent `projectUrl` in the external account, then the client can request a 30-minute sign-in link |

In Integrations → API tokens → Client entry from an integration, choose a mode and copy the displayed JSON field into your server-side request. This is a configuration example for your external system, **not a saved token setting**. It makes no settings write; the request controls the actual behavior.

Example of self-service entry (the other mode changes only the existing boolean to `true`):

```json
{
  "externalId": "order-1042",
  "client": { "name": "Ada Client", "email": "ada@example.test" },
  "project": { "name": "Studio website", "language": "en" },
  "sendInvitation": false
}
```

Use the same existing bearer token and `Idempotency-Key` conventions. Save the returned `projectId` and `projectUrl` in the external order. Do not put an API token or Magic Link in the browser button URL.

Choose the mode for the initial operation. An identical retry preserves its result and does not resend the invitation; changing `sendInvitation` while reusing an existing idempotency key or external ID is a conflict (409), as before. To explicitly send another invitation to an existing member, use the current project Clients UI. This release adds neither delayed approval nor trusted/SSO entry.

The public email request always uses the existing generic accepted response for unknown emails/projects, inactive memberships and delivery failures. A success screen means the request was accepted, not that the address exists or inbox delivery was confirmed. Rate limits and membership checks continue to apply. Returning to the form itself sends nothing.
