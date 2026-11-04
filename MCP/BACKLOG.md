<!--
1. Relative path: MCP/BACKLOG.md
2. Description: The "don't you dare forget" ledger for Schmarrn.
3. Expects: Continuous updates as milestones are conquered and new layers defined.
4. Provides: Locked decisions and the todo list — 1-2 short sentences per item, nothing else (shrunk by user order 2026-11-04; the old session narratives live in git history).
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

Schmarrn BACKLOG
One ledger: locked decisions + the todos. Marking law: `[x]` done, `[~]` in progress, `[o](remark)` deferred/must-decide.

🔒 LOCKED DECISIONS
See GOSPEL.md — the law lives there. Locked here on 2026-10-28:
- Vision: open-source flat-file CMS inspired by Automad, for others to install (FTP one folder, no DB/composer/cron). First real site: artist presentation page.
- v1 page model: full-screen slide page as the standard; alternative classical style with menu + subpages.
- Editor: single-canvas wysiwyg on the live page (Markdown truth + preset module tags; edit via save-confirm modals; no two-column, no in-page menu editing — management lives in the `/_` overlay).
- Multi-editor: named accounts, roles admin/editor, file-backed store on plain FTP; SMTP mail (vendored lib) for invites/resets; edit-lock per page. Exactly ONE account (admin) until further user order.
- Media: uploads via modal (no drag-drop), immutable assets; media browser = lazy-loaded in the Contents tab.
- UI: no sidebar — the overlay with tab bar **Contents | Settings | Plugins & Templates | Users** (+ Log out last); ONE admin surface, the editor is canvas-only with the drawer door at the right edge (simple white rect, slides in/out, no choreography).
- Serving: clean URLs, editor-browser renders HTML at save, PHP caches (per-page hash + mtime sentinel), read-only public JSON API, SEO/link previews.
- Wares: plugins + templates are the Vitrine contract (`swisd-project/vitrine`); plugins carry placeholders, routes AND contentFilters (serve-time, fail open); the drunken-monk envelope (` ```drunken-monk ` fence + base64url JSON {kind: style|script|html}) is the machine-generated payload pattern.
- Docs: README install guide + docs on GitHub Pages with examples.

v1 — todos
---

## p1 multi-user core
[x] File-backed account store (Argon2id, roles), sessions + CSRF, file-cache login rate limiting, per-page edit locks.
[~] SMTP: vendored PHPMailer 6.9.3 with config template + invite/reset endpoints — real SMTP host still unconfigured (docs at ship-out).

## p2 visual editor
[x] Canvas editor on the live page: click block → float menu → save-confirm modal → markdown truth; module spec v1 (text/image/video/embed + slide/note/details/more) in mdms.
[x] Guardrails: sha256 draft fingerprints (409 conflict flow), per-module attribute allowlist + URL gate, atomic writes.
[~] Uploads into immutable assets (v1 stores originals); media browser lives in the overlay Contents tab.
[x] Draft/publish gate with autosave-to-draft; delete/rename hardened (no-op self-rename, client-checked tree form).
[~] Page settings ✑ in the overlay (title/serve-as/date/tags/draft, guarded draft save + publish) — shipped 2026-11-04; verify the draft-born toggle nuance below.

## p3 serving & public site
[x] Clean URLs + fallback shell, save-time render + HTML cache, public JSON API (CORS scoped), SEO tags, setup wizard, both themes live.
[ ] Titles in the public pages index — add per-page title to GET /bridge/pages so site navs render titles instead of slugs.

## p4 dashboard chrome & design system
[x] Design workbench at /design + settled token system (neumorphic depth, square corners, neons attention-only), Tailwind v4, overlay tab bar.
[user-to-apply] `Accounts\save()` still lacks the opcache_invalidate that Site\save() got — same one-line fix, left untouched as out of scope that day.

## p5 ship-out
[x] README install guide; v1.0.0 tagged and pushed 2026-11-03 (Pages deploy stays the user's manual hand).
[ ] Replace the admin password before any FTP deploy — it is the template default `changeme`.
[ ] Full smoke test on a real FTP host (PHP 8.4+, .htaccess honored, writable dirs).
[ ] Docs on GitHub Pages with examples (module cookbook, theme contract, API shape).
[ ] Origin-absolute media URLs option — decide save-time baked vs serve-time rewrite when building.

## p6 plugins
[~] Capability contract: slices 1 + 2 shipped (placeholders, routes, contentFilters, drunken-monk as the proof); REMAINING: editor-blocks wiring (manifest-declared blocks on the canvas).
[~] Vitrine wares install GUI — DONT IMPLEMENT YET (user capture order): local FTP scan vs the public vitrine repo; decide install mechanics + manifest-version gates at build.

## p7+ later phases
[ ] p7 media & packaging: gd derivatives + zip packaging/installer.
[ ] p8 multilingual page variants (design deferred).
[ ] p9 auth hardening: TOTP 2FA.
[o] Blog content type → plugin (removed from v1 core by user call).
[o](v2) cellai theme package; slide metadata in the public API (nice-to-have).

User-to-verify / housekeeping
---
[user-to-verify] Draft-born pages (frontmatter `draft: true`) publish their sidecar verbatim — "Saved and live" fires while the page stays hidden until the flag flips; decide who flips it.
[user-to-apply] Every anon request mints an empty session file under `bridge/cache/sessions/` (~1,089 piled) — a manual GC sweep at low probability in Auth boot is the candidate fix; not urgent.
[user-to-apply] Vitrine pushes need the `github-vitrine` ssh alias (per-repo deploy key; schmarrn's key is collar-bound to its own repo).

Deferred / graveyard
---
- Aliases: killed (no v1 use-case); composer: never (vendored single libs only); preview-full split: superseded.
- FTP hosting is the contract — every feature must survive dumb-host reality; cache pruning not planned (per-file TTL + overwrite-on-write).
- Order when building next: titles → origin-absolute media URLs → editor-blocks wiring → cellai.
