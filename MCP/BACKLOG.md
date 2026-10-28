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
[x] Module modals: open → edit single item → must save (text textarea modal; image file+caption; video url-or-file; embed url; page-settings frontmatter modal; add-block row in the floating menu)
[x] Guardrail — unstable internet: sha256 draft fingerprints, guarded autosave (X-Base-Hash), stale save → 409 + conflict banner (take server copy / keep mine), force-overwrite explicit; atomic tmp+rename writes
[x] Guardrail — code injection: per-module attribute allowlist (image: file,caption; video: url,file; embed: url), local file values locked to `[\w./-]`, URLs http(s)-only, unknown/failed directives de-fanged to inert `p.sm-unknown-module`, raw HTML dropped by remark-rehype
[~] Uploads via modal into immutable IDed assets (v1: originals stored untouched, no gd); media browser = lazy-loaded tab in settings overlay (upload lives inside the image/video modals now; global overlay tab comes with p4)
[x] Autosave-to-draft; draft/publish gate; draft preview link (`.draft.md` sidecar engine + editor autosave + publish/unpublish; public preview URL lands with p3 serving)
[x] Delete/rename page in UI; new page: (a) upload `.md` then edit on page, (b) new-page button in page-tree inside settings overlay (tree panel for now; overlay relocation comes with p4)
[o] Site menu editing (classical style) (deferred to p3 — needs the classical theme to exist first)

## p3 serving & public site
[x] Clean URLs: front controller routes + `.htaccess` rewrite rules; 404/fallback (root index.php dispatches /api/* vs public; php -S router mode; smoke-tested)
[x] Save-time render: dashboard client renders `parseMdms()` HTML → PHP stores + cache dir per-page hash files; site-mtime sentinel invalidation (publish route takes {html}; stale/missing → client fallback shell via committed api/assets/render.esm.js)
[x] Public theme rendering (classical style + full-page slide style), artist reference theme installable via theme contract (manifest, assets, settings) — bakery classical + slide; atelier artist theme (slide-first, settled depth system at portfolio scale); {{navHtml}} placeholder added to the contract (published pages, aria-current); theme switch proven live via PUT /api/site. Logo upload flow still open.
[~] Global brand settings (logo, claim, name) referenced across pages — Site\load/save + template placeholders done; admin "Site settings" panel in dashboard (GET/PUT /api/site, admin-only, theme/home validated); logo upload flow still open
[x] Read-only public JSON API per page (title, body HTML, children, files), CORS scoped — GET /api/public/{type}/{slug}; stale cache → bodyHtml null (consumer re-renders from raw); CORS: public routes answer `*` without credentials, dashboard routes keep reflected-origin + credentials
[x] SEO/link-preview basics (og tags, titles)
[x] First-visit setup wizard (admin account, site name, theme) — GET/POST /api/setup(+status), dashboard wizard on boot when no accounts exist, auto-login on found; template account store now ships EMPTY (no known credentials in the wild — the wizard is the door), accounts.local.php wins when present

## p4 dashboard chrome & design system
[~] Design workbench FIRST (user-ordered 2026-10-28): `/design` publicly served (Serve\design → api/assets/design.html, static, no login) — all UI primitives (color tokens incl. proposed neons, type scale, buttons, forms, badges, feedback, cards, overlay bits) then composed patterns (header, hero, text/card-grid/form sections, footer). User guides the system's evolution from this page; tokens in ONE :root block. NEXT: iterate on user feedback, then port the settled system to the dashboard (Tailwind) + themes.
[ ] Tailwind in (replace hand-rolled tokens); design tokens: palette + shapes + motion as pinned above
[ ] Burger menu → full-screen overlay (settings, global settings, media tab lazy, page tree)
[ ] Round elements, hover slide-up + paper-cast shadows, micro-animations; no ornament
[ ] SPA fallback renamed to `dashboard.html` (adapter root-overwrite warning)

## p5 v1 ship-out
[ ] Full smoke test on a real FTP host (PHP 8.4+, .htaccess honored, writable cache)
[ ] README install guide (FTP one folder, wizard walkthrough)
[ ] Docs on GitHub Pages with examples (module cookbook, theme manifest spec, API shape)
[ ] Tag v1.0.0

v2 — ideas (next era, post-v1)
---

## p6 plugins (own phase)
[ ] Capability contract: may register template functions, API routes, editor blocks — nothing else; scanned `plugins/` dir; admin installs like WP with tighter lid
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
