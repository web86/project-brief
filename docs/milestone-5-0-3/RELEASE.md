# ProjectBrief 0.5.0.3

Read visible task/general comments directly by webhook commentId or via a filtered,
opaque cursor list with the new opt-in `comments:read` scope. Responses include text
and safe attachment metadata, with no names/contact fields, paths, public links or
internal notes. Legacy task comments retain access through their visible parent.

The Attachments Read API adds task/comment file lists and bearer-authenticated
streaming download. It requires `attachments:read` plus `tasks:read` for direct task
files or `comments:read` for comment files. Workspace and parent visibility are
checked; browser cookies alone grant no v1 access. Owner UI includes localized
privacy guidance because text and files may contain personal information.

Webhook cron is silent by default on idle/success/busy/backoff. Actual failures
emit sanitized stderr diagnostics and a nonzero exit while retaining history and
retry behavior. Explicit --verbose adds statistics. No webhook payload/event/HMAC
change, new migration, service, connector or comment/file write API is included.
Read PRODUCTION.md for Jino error-only logging, rotation, smoke checks and rollback.
This is a local release, without push/deploy or a live Jino/CRM E2E claim.
