# Project Brief 0.8.1 — Client Entry UX

Base: `codex/milestone-8-0-integration-foundation`, commit `a533aca`. Work branch: `codex/milestone-8-1-client-entry-ux`. Local release; push, merge and deployment require separate authorization.

- [Invitation modes and unchanged API contract](INVITATION-MODES.md)
- [Testing and security report](TESTS.md)
- [Jino update and rollback](PRODUCTION.md)
- [Existing provisioning API / PHP example](../milestone-8-0/API.md)

The canonical project URL now has two separate states. Before submission it shows the email form; after an accepted request it shows a mail icon, “Check your email” and a generic conditional message. “Use another email” returns to the same form with focus on its email field, without sending another request. Errors retain the form; pending submissions are guarded against duplicates. Labels, themes and CSS tokens follow the existing RU/EN/TR/ZH-CN interface.

Initial invitations still expire after **7 days**. Public sign-in links still expire after **30 minutes** and preserve previously valid sessions. Their localized email subjects and messages are now separate; both buttons say **Open project**. Both use the existing `/access/{token}` verification and go directly to `/project/{uuid}` after successful verification. No additional email step or authentication method was introduced.

The integrations page offers two examples for the existing per-request `sendInvitation` boolean. It does not save a token default or add a workspace policy, database table, new permission, scheduler or background process. “After approval” and trusted entry remain out of scope.
