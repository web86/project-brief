# UX polish and client privacy

Base: `codex/milestone-10-0-phase-2`, `3306015`, runtime v0.9.10. Work branch: `codex/ux-polish-demo`. No next enforcement phase, entitlement changes, production database access or deployment.

The existing Vue UI now provides a shared platform sidebar, a denser plan editor, a viewport-safe Workspace help popover and corrected optional security recommendations. The demo uses those same application views and components.

- Platform overview/workspaces/API usage/audit are deep-linked through `/platform?section=...`; existing `/platform/plans`, policies, transitions and version URLs remain unchanged. Browser back/forward updates the selected section. There are no duplicate horizontal menus. The desktop sidebar can collapse; mobile uses the existing native-dialog drawer utility, focus containment and Escape handling. Language/theme controls are reused. Plan navigation appears only after the existing access endpoint confirms PlanAuthority; server authorization is unchanged.
- Catalog cards summarize status, version, formatted display price, developer capacity and key limits. Primary edit actions remain visible; secondary actions use a compact disclosure. The editor groups short fields, presents RU/EN/TR/ZH-CN translation switches without clearing any values, categorizes entitlements and keeps planned/unavailable features explicit. The sticky action bar shows draft/saved/unsaved/read-only state. Developer seats remain the sole editable capacity source. Existing current revision, idempotency, preview/publication, MFA and unsaved-change guards remain in place.
- Workspace help uses a teleported native popover in the top layer, with placement clamped to `visualViewport` dimensions. Sidebar scroll overflow is preserved. Hover, focus, touch, outside dismissal and Escape work; a second Escape can close the surrounding drawer. Tooltip content is hoverable and does not enter the focus order.
- `AccountSecurity::recommendationsPending` no longer repeats optional setup when a suitable additional method already exists. Mandatory owner/platform TOTP and recovery acknowledgement remain separate enrollment gates. Passkeys remain optional. Profile management and step-up are unchanged.

## Client event contract

`ClientEventVisibility` is the server allowlist shared by task serialization and notification filtering. Client history admits ordinary creation, brief updates, public comments, approval/status, permitted attachments, section/order changes and restoration. Actor types are limited, structured internal/agent metadata is rejected, private-comment references are excluded and old/new values are constrained to public status/number values. Free-form internal audit text and agent metadata are never copied into client history JSON.

The public comment scope excludes team-only records, agent authors and records linked to agents, including historical records with an old public flag. Attachment scope excludes agent uploads and files attached to those private comments. Task/project discussions, unread counts, alternate read APIs and client export queries inherit the same scope. Client comment payloads omit agent-specific keys. Live and external notifications additionally apply visibility checks; unknown event types stay private. The Vue timeline has a matching defensive allowlist.

Developer resources explicitly retrieve the complete internal conversation and preserve agent collaboration/audit, including revoked/deleted assignments and historical comments. No migration rewrites visibility flags and no records are deleted. Human-reviewed publication remains a separate developer-authored comment/file copy; the client receives that copy and ordinary team history without provenance metadata.

Tests that previously required legacy public agent identity were updated to the owner's new privacy requirement. Migration tests still compare stored rows and use an unscoped internal read to verify that historical flags and token scopes remain unchanged.

## Verification

See [demo testing report](../demo/TESTS.md). Platform browser fixtures are separate temporary installations; public demo has no platform authority. Local screenshots reside in `artifacts/demo/` and `artifacts/milestone-10-0-phase-2/browser/` and are not committed. No live/Jino or production result is inferred from local tests.
