<!--
1. Relative path: MCP/FILETREE.md
2. Description: Sanity check — the tree as it should look. Diff after large changes.
3. Expects: `tree` or an honest human.
4. Provides: Expected shape of the Schmarrn repository (phase 0 scaffold).
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

08_Schmarrn/
├── .git/
├── .gitignore
├── README.md
├── package.json                  # SvelteKit root (dashboard)
├── package-lock.json
├── node_modules/                 # ignored
├── .svelte-kit/                  # ignored
├── svelte.config.js              # adapter-static, fallback SPA
├── vite.config.ts                # sveltekit() + /bridge dev proxy → :8080
├── tsconfig.json
├── build/                        # built dashboard (ignored)
├── index.php                     # root front controller: /bridge/* → core, /_ → 301 /_/ → dashboard SPA (host _/ dir or repo build/), else public site (also php -S router)
├── .htaccess                     # whitelist law: _/ (dashboard home, mod_dir index), bridge/templates/, bridge/assets/, dashboard/, bridge/plugins/<n>/assets/ + index.php
├── pagetypes/                    # page-type scaffolds: pre-made, fillable pages (blank/landing/gallery/article); :::image slots CLOSE with :::
├── scripts/
│   ├── build-fallback.mjs        # esbuild → bridge/assets/render.esm.js (committed)
│   ├── build-edit.mjs            # esbuild → bridge/assets/edit.esm.js (committed live-page editor)
│   ├── ship-release.sh           # packs the release ASSET zip: tag source tree + built build/ (the only way the dashboard rides an update)
│   ├── dev-serve.sh              # the dev server under the PORT LAW: knocks, never kicks; auto-steps to the next free port
│   └── (*.mts)                   # dev probes
├── bridge/                       # PHP core — front controller + libs, no framework; lives under /bridge (bit-scan prey no longer fed)
│   ├── .htaccess                 # legacy (root .htaccess is the law now)
│   ├── index.php                 # JSON routes: login/session, pages, drafts, publish(+html), pagetypes, templates (admin theme inventory), public API, media, plugins
│   ├── serve.php                 # public serving engine: dispatch, templates, cached HTML, fallback shell, SEO head, plugin fills, editor injection (data-sm-edit-root + edit.esm.js; nav ghost RETIRED 2026-11-04 — navHtml is plain core nav, same bytes for editors and visitors); styleOf: page frontmatter → GLOBAL site style → theme default
│   ├── site.php                  # brand settings load/save (template < site.local.php) + site assets (.assets/); `style` = GLOBAL template ('' | classical | slide, whitelist-validated)
│   ├── wares.php                 # Vitrine fetch + install (p6 slice 3): registry GET (TTL cache) + codeload packet zip → extract ONLY <path>/ (ZipArchive | PharData), manifest gate, version gate, disable-first upgrade w/ rollback; GET /bridge/wares + POST /bridge/wares/install (admin+CSRF); URLs override in config/wares.local.php (ignored)
│   ├── plugins.php               # p6 capability contract: scan/validate/load/dispatch, state in config/plugins.local.php
│   ├── router.php
│   ├── auth.php                  # Argon2id password, session, 24h
│   ├── accounts.php              # file-backed account store + invites
│   ├── mail.php                  # SMTP via vendored PHPMailer
│   ├── lock.php                  # write-path lock gates (acquire + assertFreeOrMine; TTL frees cold locks; HTTP lock endpoints retired with the canvas)
│   ├── store.php                 # content store: list/read/write (atomic), slug discipline
│   ├── cache.php                 # raw cache + rendered-HTML cache (storeHtml/htmlStale), mtime sentinels
│   ├── templates/                # the theme contract's dedicated home (was root-level themes/)
│   │   ├── bakery/               # default theme (classical-first; slide.html too)
│   │   │   ├── theme.json
│   │   │   ├── page.html             # {{placeholders}} incl. {{navHtml}} — str_replace only
│   │   │   ├── slide.html
│   │   │   └── assets/{bakery.css,deck.js}     # deck.js: dots nav + active-slide tracking + hash-following + mid-air guard (companion script, slide.html includes it)
│   │   └── atelier/              # artist presentation theme (slide-first, depth system)
│   │       ├── theme.json
│   │       ├── page.html
│   │       ├── slide.html
│   │       └── assets/{atelier.css,deck.js}   # same helper script, same law
│   ├── plugins/                  # plugin homes in the core (was root-level plugins/)
│   │   ├── hello/                # demo plugin (copy-me template): placeholder + public/user routes
│   │   │   ├── plugin.json
│   │   │   └── plugin.php
│   │   └── (*.local installs)    # monk + stop-motion live in the VITRINE repo now (base keeps none); install lands here via the wares slice
│   ├── assets/
│   │   ├── render.esm.js         # committed fallback render bundle (client-side mdms)
│   │   └── edit.esm.js           # committed live-page editor bundle (injected only for logged-in sessions; carries the nav ghost)
│   ├── config/
│   │   ├── auth.php              # TEMPLATE (hash of "changeme")
│   │   ├── accounts.php          # TEMPLATE
│   │   ├── mail.php              # TEMPLATE
│   │   ├── site.php              # TEMPLATE (brand settings)
│   │   └── (*.local.php)         # git-ignored real config
│   ├── lib/phpmailer/            # vendored
│   └── (cache/)                  # git-ignored writable cache (+ html/ subdir)
├── src/
│   ├── app.html
│   ├── app.d.ts
│   ├── package.json → moved to root (drag artifact, do not recreate)
│   ├── lib/
│   │   ├── api.ts                # typed fetcher for /api (dashboard-facing surface only — the live editor fetches /api directly)
│   │   ├── blocks.ts             # block model: splitBlocks/joinPage (exact source spans)
│   │   ├── modules.ts            # the module wall: directive allowlist → hast — image (+fillable empty slot), video, embed, slide (vertical deck: anchorId() + per-render usedIds registry, titles → anchors; themes ship assets/deck.js for measured heights + gliding anchor links), note (tip/warn), details, more (+fold); unknown → p.sm-unknown-module, hostile attrs dead at the stamper; safeUrls() URL gate after the wall (javascript:/data: schemes die, honest URLs live)
│   │   ├── render.ts             # THE one render pipeline (fallback + editor share it)
│   │   ├── schema.ts             # Zod: PageFrontmatter (blog left for plugin-land)
│   │   └── app.css               # tokens: crust/batter/raisin/jam
│   ├── public/
│   │   ├── fallback.ts           # client fallback renderer → render.esm.js
│   │   └── edit.ts               # live-page editor, canvas-only since 2026-11-04 (one-management-overlay law: click-to-edit float menu + modals + toast; the admin door at the right middle = white rect tab → expandable side menu (Backend / Log out), carrying ?back=<page>) → edit.esm.js
│   ├── routes/
│   │   ├── +layout.svelte        # imports ../lib/app.css (relative — $lib evasion, see GUIDE)
│   │   ├── +layout.ts            # prerender = true
│   │   └── +page.svelte          # login/setup + burger overlay (Contents | Settings | Plugins | Templates | Updates | Users tab bar — Plugins/Templates split 2026-11-07; Updates speaks its verdict, active only when core/template/plugin updates wait; Update all = core → templates → plugins) — the ONE management surface: Contents = the raw desk (full-height, DOM never scrolls: left = filetree of every md in use, folder-grouped, home page crowned (no delete — server lid), draft/edits badges + row verbs rename/settings/delete(open+unlock-gated)/open-live, foot = guarded Save draft (wakes only on real changes, Ctrl+S; the sheet LOCKS until an explicit Unlock, re-locks on save; the visual editor is the recommended house way) + New/Upload/Media (media in its own modal; Landing scaffold prefills 'index' when no index page exists); right = the sheet card, plain-text md editor riding getDraftRaw/putDraftRaw with the X-Base-Hash conflict guard); Settings = site form; Plugins = plugin inventory + Vitrine shelf (installable/update only); Templates = template inventory + Preview ↗ (/bridge/preview/<theme>/<type>/<slug> — the fitting room) + Vitrine shelf; picking ↗ walks to the live page
│   └── content/                  # install-local user data — GITIGNORED, never committed
│       ├── pages/                # content types (dirs = types, subdirs = slugs)
│       │   └── <slug>/index.md   # + .draft.md sidecar + page-local media files
│       └── .assets/              # site-level uploads (logo) — dot-dir, invisible to listAll
├── MCP/
│   ├── GOSPEL.md                 # the law
│   ├── BACKLOG.md                # the ledger
│   ├── GUIDE.md                  # the kinks
│   ├── guardrails.md             # the pipeline guardrails (wares + update cycle, flowcharts, audit)
│   └── FILETREE.md               # this sanity check
