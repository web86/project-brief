# Dev2Done rebranding

Branch: `codex/rebrand-dev2done`. Base: production v0.9.11 and Static Demo v1, after Phase 1–2/UX polish. This work does not start Milestone 10.0 Phase 3.

The product name is **Dev2Done**. **D2D** is used for the PWA shortcut and compact mobile demo signature; small headers may show the mark alone. Harborline Digital Studio remains the fictional studio. Its demo logo/accent now use the same Dev2Done identity. In the client demo, Northstar's **Core experience** section and its six tasks come first, numbered `1.x`; Discovery & design follows. The same source data/order is used for Developer and Client so links and numbering agree. Project/task UUIDs, descriptions, statuses, people, permissions and credentials are retained.

The user-provided SVG is the master `src/assets/brand-mark.svg`. Gradients and path geometry remain unchanged. Browser Chromium rasterization produces native SVG gradient rendering in PNG/ICO; release `brand:check` uses committed source/derivative hashes and needs no browser. Generation uses the developer's existing Playwright (`PLAYWRIGHT_MODULE`), without changing npm dependencies. See `deployment/brand-assets.json`. Production runtime needs no new software.

## Identity and tokens

- Default light action `#5b4bd6`, hover `#4939bc`, link `#5141c6`, focus `#6858e8`.
- Default dark action `#beb5ff`, hover `#d0caff`, link `#beb5ff`, focus `#bdb4ff`, on-action `#241c4d`.
- Selected/navigation/badge/progress tints share the violet/indigo family. Success/warning/error retain their semantic colors. Existing custom tenant colors are preserved; the demo uses `#5b4bd6` and the existing contrast-correcting brand token adapter.

## Assets

Master and inline splash; `public/brand-mark-{light,dark}.svg`; `brand-logo-{light,dark}.svg`; `app-icon.svg`, `favicon.svg`, `favicon.ico`; 192/512 ordinary and maskable PNGs; Apple touch icon; Laravel fallback favicon; demo/export SVG and PNG resources. Global platform headers and the collapsed navigation rail also display the new mark. Maskable icons have an opaque full background and keep the full sign inside the safe circle. Icon URLs have a new brand revision. Logo images do not require external fonts or services at runtime.

## Deliberately retained technical names

`project-brief-app` runtime/upload layout; npm/Composer identifiers; routes, model/table names, migration history and Phase 1–2 logic; `projectbrief.*` localStorage/IndexedDB/cache/session keys; `X-ProjectBrief-*` API/webhook/PDF headers; SSO cookie and worker message types; `ProjectBrief API` legacy JSON service identifier; registered Telegram bot username/bridge host; existing domains; historical release filenames/reports. These are compatibility identities, not the current visible product brand. Existing custom company names, authored agreements and stored content are not blindly rewritten.

Legacy default APP_NAME/MAIL_FROM_NAME display as Dev2Done without editing existing env files or changing cookies/RP ID. No new migration/env key, subscription/authority setup or commercial enforcement transition is introduced.

Builds use the existing `./bin/build-release` for production and `npm run demo:build:static` for the backend-free demo. Final SHA/source commits/results are recorded in `ARTIFACTS.md`. Update/rollback: [PRODUCTION.md](PRODUCTION.md), [Static hosting](../../demo/static/HOSTING.md).
