<!--
1. Relative path: MCP/BACKLOG.md
2. Description: The "don't you dare forget" ledger for Schmarrn.
3. Expects: Continuous updates as milestones are conquered and new layers defined.
4. Provides: Locked decisions, roadmap (v1 todos → v2 ideas → deferred), and working info.
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

Schmarrn BACKLOG
The "don't you dare forget" ledger. One project, one app: PHP `api/` + SvelteKit dashboard.

Marking law: done = `[x]`, work in progress = `[~]`, deferred/must-decide-later = `[o](remark)` — remark appended to the todo item.

🔒 LOCKED DECISIONS
See GOSPEL.md — the law lives there. Locked here on 2026-10-28:
- Vision: open-source flat-file CMS inspired by Automad, for others to install (FTP one folder, no DB/composer/cron). First real site: artist presentation page.
- v1 page model: full-screen slide page first (inspiration: alvarotrigo.com/fullPage) as the standard; alternative classical style with menu + subpages selectable.
- Editor: single-canvas wysiwyg (Markdown truth + preset module tags; edit via save-confirm modals; two-column layout binned).
- Multi-editor: named accounts, roles admin/editor, file-backed store on plain FTP; SMTP mail (vendored lib) for invites/resets; edit-lock per page.
- Media: uploads via modal (no drag-drop), immutable assets, media browser = lazy-loaded tab in settings overlay.
- UI: no sidebar — burger menu → full-screen overlay (settings, global settings, media). Design system "warm hipster bakery": peach/cherry blossom/vanilla/baby-blue + strong neon accents, 50s Hofbäckerei fin-de-siècle ↔ neon, round elements w/ hover slide-up + paper shadow, Google-fresh compact spacing, micro-animations, no ornament, no AI-slop patterns. Tailwind, very wanted.
- Serving: clean URLs, editor-browser renders HTML at save, PHP caches (per-page hash + mtime sentinel), read-only public JSON API, SEO/link previews.
- Docs: README install guide + docs on GitHub Pages with examples.

v1 — Schmarrn "known to work"
---

## p1 multi-user core
[x] File-backed account store: `username → {argon2id hash, role}`, template + git-ignored real file
[x] Login/session per account, role attached to session; CSRF tokens on all mutating API calls
[x] File-cache-based login rate limiting; session records in writable dir (self-managed lifetime, 24h)
[~] SMTP: vendor PHPMailer (or equal, no composer); admin SMTP config; invite new editor + password-reset (self-reset for lost admin password via config file regeneration) (vendored 6.9.3; config template + endpoints done; real SMTP host still unconfigured — docs at p5)
[x] Edit-lock per page (coarse, heartbeat + takeover UX)

## p2 visual editor (the build's heart)
[x] Single-canvas editor: page rendered as-is → click block to select → floating button menu → modal → save writes the block back to markdown (user-pinned interaction model; block model in `src/lib/blocks.ts`; two-pane editor deleted)
[x] Module spec v1 + implement in mdms: text, image, image with caption, video/html embed — boundary syntax + data location decided on the go, pinned per module (remark-directive `:::name{attrs}` … `:::`; attrs inline; media files page-local; shipped via mdms v0.1.9 `mdastPlugins`; unknown module names de-fanged to inert marker)
[x] Module modals: open → edit single item → must save (text textarea modal; image file+caption; video url-or-file; embed url; page-settings FORM modal — title/slug/style/date/tags fields that REBUILD valid frontmatter, replacing the raw-YAML textarea (user call: no blame text fields); changed slug in the form renames the page; broken frontmatter is fixable from the form — the escape hatch out of validation dead ends); add-block row in the floating menu + an always-visible starter row on empty/broken pages (zero blocks used to be a dead end — no selection possible, no menu, no add)
[x] Guardrail — unstable internet: sha256 draft fingerprints, guarded autosave (X-Base-Hash), stale save → 409 + conflict banner (take server copy / keep mine), force-overwrite explicit; atomic tmp+rename writes
[x] Guardrail — code injection: per-module attribute allowlist (image: file,caption; video: url,file; embed: url), local file values locked to `[\w./-]`, URLs http(s)-only, unknown/failed directives de-fanged to inert `p.sm-unknown-module`, raw HTML dropped by remark-rehype
[~] Uploads via modal into immutable IDed assets (v1: originals stored untouched, no gd); media browser = lazy-loaded tab in settings overlay (upload lives inside the image/video modals now; global overlay tab comes with p4)
[x] Autosave-to-draft; draft/publish gate; draft preview link (`.draft.md` sidecar engine + editor autosave + publish/unpublish; public preview URL lands with p3 serving)
[x] Delete/rename page in UI; new page: (a) upload `.md` then edit on page, (b) new-page button in page-tree inside settings overlay (tree panel for now; overlay relocation comes with p4). Rename UX hardened (user call: disable buttons that cannot function, no blame errors): self-rename is a server-side no-op (was a confusing "Target exists"), tree form disables ✓ when unchanged or taken (client-side check against the index) with inline reasons, server conflict message is humane ("Another page already uses the name …")
[o] Site menu editing (classical style) (deferred to p3 — needs the classical theme to exist first)

## p3 serving & public site
[x] Clean URLs: front controller routes + `.htaccess` rewrite rules; 404/fallback (root index.php dispatches /api/* vs public; php -S router mode; smoke-tested)
[x] Save-time render: dashboard client renders `parseMdms()` HTML → PHP stores + cache dir per-page hash files; site-mtime sentinel invalidation (publish route takes {html}; stale/missing → client fallback shell via committed api/assets/render.esm.js)
[x] Public theme rendering (classical style + full-page slide style), artist reference theme installable via theme contract (manifest, assets, settings) — bakery classical + slide; atelier artist theme (slide-first, settled depth system at portfolio scale); {{navHtml}} placeholder added to the contract (published pages, aria-current); theme switch proven live via PUT /api/site. Logo upload flow still open.
[x] SPA fallback `dashboard.html` (was already done in svelte.config — row was stale; build emits prerendered index.html + dashboard.html fallback)
[~] Global brand settings — logo upload flow DONE: POST /api/site-assets (admin+CSRF, extension allowlist, immutable prefixed names) into src/content/.assets/ (dot-dir, invisible to the page tree), public GET /api/site-assets/<name> (mime+nosniff+cache, traversal refused); Site tab gains "Upload logo…" which fills the logo field (save still explicit). Remaining: none known.
[x] Read-only public JSON API per page (title, body HTML, children, files), CORS scoped — GET /api/public/{type}/{slug}; stale cache → bodyHtml null (consumer re-renders from raw); CORS: public routes answer `*` without credentials, dashboard routes keep reflected-origin + credentials
[x] SEO/link-preview basics (og tags, titles)
[x] First-visit setup wizard (admin account, site name, theme) — GET/POST /api/setup(+status), dashboard wizard on boot when no accounts exist, auto-login on found; template account store now ships EMPTY (no known credentials in the wild — the wizard is the door), accounts.local.php wins when present

## p4 dashboard chrome & design system
[~] Design workbench FIRST (user-ordered 2026-10-28): `/design` publicly served (Serve\design → api/assets/design.html, static, no login) — all UI primitives (color tokens incl. proposed neons, type scale, buttons, forms, badges, feedback, cards, overlay bits) then composed patterns (header, hero, text/card-grid/form sections, footer). User guides the system's evolution from this page; tokens in ONE :root block. System SETTLED by user steering (neumorphic depth bg→mg→fg, one top-left light, inputs press down, fg flat/hover-lift, square corners, neons attention-only, 3 button intents one size); ported to the dashboard (src/lib/app.css) — the workbench stays the steering tool for future tweaks.
[user-to-apply] `Accounts\save()` has the same opcache staleness race `Site\save()` had (writes accounts.local.php without opcache_invalidate) — a role change/removal can be invisible to requests landing inside the 2s revalidate window (or forever on hosts with validate_timestamps=0). Same one-line fix as Site\save(); left untouched as out of scope for the theme-bug instruction.
[x] Tailwind in (v4, @tailwindcss/vite, CSS-first @theme); design tokens ported verbatim from the workbench into src/lib/app.css (surfaces, accents, neons, ink, feedback, light/shadow vocabulary, type scale, square radii, motion) + system component classes (btn×3 intents, input press-dent, mg, card, badge, tag, alert, modal-card, float-menu, burger); dashboard + editor chrome restyled on it
[x] Burger menu → full-screen overlay (tabs: Pages / Site / Accounts / Media — Site+Accounts admin-only; selection leaves the overlay; Escape closes); media tab lazy-loads GET /api/media (new: global sweep over all pages' files, click copies the URL)
[x] Micro-animations subtle + prefers-reduced-motion respect; no ornament (the settled system's law — square corners, depth by light — supersedes this row's original "round elements" wording)
[ ] SPA fallback renamed to `dashboard.html` (adapter root-overwrite warning) — ALREADY DONE in svelte.config (fallback: 'dashboard.html', build emits both index.html prerender + dashboard.html fallback); row closed as stale

## p5 v1 ship-out
[x] README install guide — DEPLOY LAW CHANGED 2026-10-28 (user): public site at /, dashboard/login at /_; the SPA is BUILT with paths.base '/_' (svelte.config default; Pages overrides via BASE_PATH) — the dashboard's client router 404s anywhere else, and /_ canonicalizes to /_/; FTP deploy = index.php + .htaccess + themes/ + api/ + pagetypes/ + src/content/ + the contents of build/ INSIDE a host folder named _/ (NOT loose at the root — the built index.html would shadow index.php via DirectoryIndex and put the login wall on the public face); writable set = api/config/ + api/cache/ + src/content/; first-run wizard at /_/ then redirect to /; DONE 2026-10-28 (README "Deploy (FTP)" rewritten as the full install guide).
[ ] Full smoke test on a real FTP host (PHP 8.4+, .htaccess honored, writable cache) — needs the user's host
[ ] Docs on GitHub Pages with examples (module cookbook, theme manifest spec, API shape)
[ ] Tag v1.0.0 — red-flag action: needs the user's explicit, separate go

v2 — ideas (next era, post-v1)
---

## p6 plugins (own phase)
[~] Capability contract slice 1 DONE: plugins/<name>/ = plugin.json manifest + plugin.php; capabilities = template placeholders (namespaced `<name>_…`, enforced) + API routes ({path, auth: public|user|admin, csrf} — auth/CSRF applied by Plugins\dispatch() BEFORE the handler runs); THE LID ENFORCED BY CORE: only manifest-declared capabilities honored, PUT /api/plugins only toggles INSTALLED plugins, broken manifest/plugin degrades to an error row + log line, never a dead site; state in api/config/plugins.local.php (gitignored, opcache_invalidate on write); GET/PUT /api/plugins (admin), dispatch case for /api/plugin/<name>/<route>; Serve\fill() merges plugin placeholders last (trusted HTML) with $type ctx, serve-time wrap → enable/disable applies without re-publish; whitelists (index.php php -S + .htaccess) allow ONLY plugins/<name>/assets/ — plugin CODE never directly servable; demo plugin plugins/hello/ (placeholder hello_badge + public status route + user echo route) doubles as the copy-me template; dashboard admin Plugins tab (enable/disable toggles). Verified live: disabled route 404, anon 401, no-CSRF 403, ghost plugin kept out of state, broken plugin degrades, direct plugin.php hit → 404, assets serve. REMAINING (slice 2): editor blocks wiring (manifest-declared, canvas integration).
[~] UX pivot (user-ordered 2026-10-28): IN-CONTEXT EDITING on the live page — public site at / (never a login face; built index.html is Pages-only), dashboard/login at /_ (after login/setup the built SPA redirects to /); logged-in editors get src/public/edit.ts (built → api/assets/edit.esm.js) injected at serve time (Serve\page() — anon HTML byte-identical); interaction model = the pinned canvas model on the real page: click block → float menu (edit/↑/↓/delete + add row) → modal → save = draft PUT (X-Base-Hash conflict guard) + publish with rendered HTML, live instantly; slide templates gained {{navHtml}} (bakery slide-brand, atelier slide-mark) — slide pages were menu-less by omission. Verified live: injection anon-clean, bundle's API flow lands edits publicly, base-hash conflict guard, JS MIME.
    - Global template (2026-10-28): site setting `style` ('' = theme default | classical | slide, whitelist-validated in Site\save()); styleOf precedence = page frontmatter `style:` (the per-page dropdown KEPT for the page-type future) → GLOBAL site style → theme defaultStyle; dials in BOTH places: dashboard Site tab + the live editor's overlay (admin-only section, GET/PUT /api/site, reload on save). Verified live: global slide wraps anon pages, page-level classical override wins, reset to '' restores theme default, garbage value coerced to ''.
    - Burger overlay REWORKED (user-ordered): full-screen overlay houses page settings + (admin) site-template section + actions (＋ New page / Log out / Manage site); bg-click and Escape dismiss; the "weird side menu + backend trip" complaint is dead. ＋ New page = modal fetching GET /api/pagetypes → POST /api/page/pages/<slug> with the chosen scaffold → navigate to the new page.
    - Page-types (2026-10-28): pagetypes/{blank,landing,gallery,article}.md — pre-made, fillable scaffolds; layout is the page-type's domain, the user clicks empty elements and fills them; GET /api/pagetypes (auth) returns [name, title, body]. Empty `:::image{caption="…"}` (no file, MUST close with `:::` — unclosed containers swallow the rest of the document) renders a visible fillable placeholder (sm-empty-slot, dashed border, both themes) in the shared renderer (src/lib/modules.ts → render.esm.js via build:fallback); scaffolds seed these. Verified live: scaffolds list with bodies, gallery scaffold → 3 visible slots as anon (cached-HTML path; shell path renders client-side), temp page cleaned up.
    - Canvas editor RETIRED (2026-10-28, user-blessed): src/lib/components/CanvasEditor.svelte deleted, SelectedPage type dropped; the dashboard /_ is MANAGEMENT-ONLY — picking a page in the menu navigates to the live page (`/<slug>` or `/<type>/<slug>`) where the injected editor edits; new/upload page flows navigate there too; menu close button says "Close". One surface to keep warm. REMAINING: browser-level pass on the overlay + new-page UI.
    - Site structure reset (user-ordered 2026-10-28): ALL existing pages deleted (pages/test, pages/ha-for-you, pages/moopspoops, blog/hidden-pudding draft; empty pages/about/ and blog/ dirs removed). New structure: a) pages/home = small introduction, the regular landing page (site.home pinned to 'home'); b) the design workbench is a standing sub-page — navHtml() now always appends a Design link to /design after the store pages (core-served route, not store content). Landing published with client-rendered HTML (cached); old pages 404, caches dropped. Then (same day, user request): pages/style-guide — the markdown PENDANT to the workbench: every typable block (h1-h3, bold/italic/links/inline code, paragraph, blockquote, ul/ol, hr, fenced code) plus all three modules (empty image slot, CC0 video sample, OpenStreetMap embed), all verified rendering live; nav now Home · Style guide · Design. REMOVED: the three HTTP lock endpoints (PUT/GET/DELETE /api/lock/...) + Lock\peek()/release() — the canvas was their only client; the write-path gates (Lock\assertFreeOrMine + acquire on draft PUT/publish/page PUT) STAY and were verified live (foreign hot lock → 409, cold lock → free); api.ts orphan wrappers cut (writePage, readPage, readDraft, writeDraft, ConflictError, publishPage, unpublishPage, peekLock/acquireLock/releaseLock + LockStatus, listMedia/uploadMedia + MediaRef, patchAccount, requestResetToken/confirmReset + ResetDoors, changeOwnPassword, mediaUrlOf) — the live editor fetches /api directly, the dashboard only creates/deletes/renames/settings/accounts/plugins/media-sweep; src/hooks.server.ts deleted (literal no-op passthrough); legacy `dashboard/` whitelist entry removed from .htaccess (no such dir in any layout since the /_ pivot); local residue purged: cache files for deleted slugs (blog-off-the-griddle, blog-tasting-notes + metas) and every cold lock file (no heartbeat exists anymore). KEPT deliberately: PUT /api/page/<t>/<s> (the headless raw-write the README promises), the /design workbench (user-steered), the reset/password/account-patch PHP endpoints (self-service feature awaiting dashboard UI — typed wrappers return when that UI lands). user-to-apply: src/content/blog/hidden-pudding is a DRAFT in the blog type — invisible in the tree and 404 as anon; publish it or move it into pages/ if it should be live (blog-as-plugin future). HANDS OFF the content itself.
[o] Blog content type → plugin (removed from v1 core per user call 2026-10-28: schema register, seed demo; existing blog dirs fall back to the pages schema)
## p7 media & packaging
[ ] gd: image processing/derivatives on upload + on-the-fly resize; zip: theme/plugin packaging + installer
## p8 multilingual
[ ] Per-language page variants (design deferred until v1 shipped)
## p9 auth hardening
[ ] TOTP 2FA for accounts

Deferred / graveyard
---

- Aliases: KILLED (v1 use-case absent).
- `hasMore`/preview-full split: superseded by visual editor + module model (revisit only if blog needs it).
- Composer: never (vendored single libs are the only permitted exception, per GOSPEL mail line).

Working info
---
- FTP hosting is the contract; every feature must survive "dumb host" reality (writable dir + PHP + Apache assumed, nothing more).
- Cache pruning not planned: per-file TTL + overwrite-on-write (Automad pattern) suffices at our scale.
- The `@swisd-project/*` npm namespace still hosts shared types; mdms grows the module renderer as `@swisd-project/mdms`.
- GOSPEL amendments 2026-10-28: multi-editor, SMTP-bend, modules/themes/public API/serving/requirements-phasing lines added; dashboards edited-model line rewritten.
