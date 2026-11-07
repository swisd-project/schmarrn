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
- Media: uploads via modal (no drag-drop), immutable assets (immutable against OVERWRITE — names never reused; deletion is a named, CSRF-gated action, 2026-11-05 user-ordered for the stop-motion frame tray; page-local sub-folders via validated `?folder=` param), media browser = lazy-loaded in the Contents tab.
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
[~] Capability contract: slices 1 + 2 shipped (placeholders, routes, contentFilters, drunken-monk as the proof); slice 3 = Vitrine fetch + install (2026-11-05); slice 4 = editorBlocks WIRED 2026-11-05 — manifests may declare {name,label,template} (namespaced, underscored prefix law), GET /bridge/editor-blocks serves enabled plugins' defs, the canvas Add menu gains the "Plugins:" row, insert pre-fills the text modal with the template (stop-motion 0.1.1 carries its own fence template).
[x] Vitrine wares install — SHIPPED 2026-11-05 (user ordered the build): `bridge/wares.php` + `GET /bridge/wares`, `POST /bridge/wares/install`; dashboard "From the Vitrine" blocks on both wares tabs; base version ships monk/stop-motion uninstalled (monk kept in the Vitrine repo, hello remains the local demo).
[x] The Chain Law — SHIPPED 2026-11-07 (user ordered after the requires line died in an uncommitted rewrite and the shelf↔manifest drift answered "0.2.0 is already installed" for a 0.2.1 update): manifest-carried requires with version constraints (`bridge/chain.php`), derived effective state (`Plugins\deriveEffective`), gates at install/enable/activate/delete, serve-time fail-closed; the truth gate NAMES a shelf↔manifest drift; monk/stop-motion the proof pair (stop-motion requires monk in its manifest).

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

## THE drawer bug 2026-11-04 (user, thousandth telling: "how hard is it to create a simple drawer?")

> ### 2026-11-05 second body, same drawer: the orphaned canvas click wiring
> Discovered live: the visual editor rendered but never answered clicks. The drawer's Nov 4 rework declared `const root` INSIDE `mountChrome()` — shadowing the canvas root — so the block-click handler (`data-sm-block` → select + float menu, Oct 28) silently bound to THE DRAWER instead of the canvas. Playwright repro: zero selection/floats after a block click. FIX: the drawer's local is `door`, the canvas keeps `root`; block wiring lives again. Rebuilt `bridge/assets/edit.esm.js` and verified click → float menu in the browser.
>
> Plus, same session: (a) editor modals STACKED on reopen and the hidden layer ate clicks — the "crash" the user saw with ~10 frames; openModal now replaces (one modal at a time) and Escape dismisses the open layer. (b) The php -S asset serve path sent NO Cache-Control → browsers heuristic-cached `edit.esm.js` for hours → "I see a textfield with the settings" = a STALE editor bundle. Cache law mirrored from .htaccess: dashboard `_app/immutable` = 1y immutable, everything else no-cache/must-revalidate; theme assets 3600 (contract).

THE REAL PARASITE, finally with a body: the width measurement ran BEFORE document.body.append(root) and BEFORE the triangle append — a DETACHED element measures offsetWidth = 0, computed padding = NaN, tri = null → closedW/openW garbage → every style.width write was "0px"/"NaNpx" → INVALID → ignored → width stayed auto → the drawer rendered permanently WIDE and could never collapse. Not a design fight — a mounting-order bug wearing a design argument as a costume. FIX: measure only after mount + full population (append root → getComputedStyle → tri rect → auto → openW → closedW). Bundle rebuilt; front page battery green.

## The drawer carries the tabs 2026-11-04 (user: "add the tabs from the overlay as nav items")

The drawer's items ARE the overlay's tabs: Contents | Settings | Plugins & Templates | Users (admin-only tabs hidden for editors) + Log out last — "Backend" retired. Each item opens /_?back=<page>&tab=<tab>; the overlay's session boot reads ?tab and showTab()s pre-selected (role-guarded — editors pinned to Contents). back.ts grew the optional tab param; node battery 16/16 (tab + tab+hash cases added). Builds green, svelte-check 0/0.

## The drawer retained on the overlay 2026-11-04 (user: "retain the drawer menu on the overlay")

The dashboard gets the same door: +page.svelte renders .sm-admin-root in the authed view (z-45 — above the z-40 overlay, below the z-50 modals), CSS twin in app.css (the editor's door styles copied verbatim). Differences by surface: the drawer's tab items SWITCH TABS IN PLACE (showTab + drawer closes — no navigation, no ?back dance; the overlay is already home) and Log out = signOut() (SPA login wall). Same measured-width law (use:drawer action, sizes from real mounted layout), same outside-click collapse (svelte:document), same keyboard toggle. Builds green, svelte-check 0/0.

Deferred / graveyard
---
- Aliases: killed (no v1 use-case); composer: never (vendored single libs only); preview-full split: superseded.
- FTP hosting is the contract — every feature must survive dumb-host reality; cache pruning not planned (per-file TTL + overwrite-on-write).
- Order when building next: titles → origin-absolute media URLs → editor-blocks wiring → cellai.
