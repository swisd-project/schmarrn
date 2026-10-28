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
├── vite.config.ts                # sveltekit() + /api dev proxy → :8080
├── tsconfig.json
├── build/                        # built dashboard (ignored)
├── index.php                     # root front controller: /api/* → core, /_ → 301 /_/ → dashboard SPA (host _/ dir or repo build/), else public site (also php -S router)
├── .htaccess                     # whitelist law: _/ (dashboard home, mod_dir index), themes/, api/assets/, dashboard/, plugins/<n>/assets/ + index.php
├── plugins/                      # p6 plugin contract — manifest + code, core-enforced lid
│   └── hello/                    # demo plugin (copy-me template): placeholder + public/user routes
│       ├── plugin.json
│       └── plugin.php
├── pagetypes/                    # page-type scaffolds: pre-made, fillable pages (blank/landing/gallery/article); :::image slots CLOSE with :::
├── themes/
│   ├── bakery/                   # default theme (classical-first; slide.html too)
│   │   ├── theme.json
│   │   ├── page.html             # {{placeholders}} incl. {{navHtml}} — str_replace only
│   │   ├── slide.html
│   │   └── assets/bakery.css
│   └── atelier/                  # artist presentation theme (slide-first, depth system)
│       ├── theme.json
│       ├── page.html
│       ├── slide.html
│       └── assets/atelier.css
├── scripts/
│   ├── build-fallback.mjs        # esbuild → api/assets/render.esm.js (committed)
│   ├── build-edit.mjs            # esbuild → api/assets/edit.esm.js (committed live-page editor)
│   └── (*.mts)                   # dev probes
├── api/                          # PHP core — front controller + libs, no framework
│   ├── .htaccess                 # legacy (root .htaccess is the law now)
│   ├── index.php                 # JSON routes: login/session, pages, drafts, publish(+html), pagetypes, public API, media, plugins
│   ├── serve.php                 # public serving engine: dispatch, themes, cached HTML, fallback shell, SEO head, plugin fills, editor injection; styleOf: page frontmatter → GLOBAL site style → theme default
│   ├── site.php                  # brand settings load/save (template < site.local.php) + site assets (.assets/); `style` = GLOBAL template ('' | classical | slide, whitelist-validated)
│   ├── plugins.php               # p6 capability contract: scan/validate/load/dispatch, state in config/plugins.local.php
│   ├── router.php
│   ├── auth.php                  # Argon2id password, session, 24h
│   ├── accounts.php              # file-backed account store + invites
│   ├── mail.php                  # SMTP via vendored PHPMailer
│   ├── lock.php                  # write-path lock gates (acquire + assertFreeOrMine; TTL frees cold locks; HTTP lock endpoints retired with the canvas)
│   ├── store.php                 # content store: list/read/write (atomic), slug discipline
│   ├── cache.php                 # raw cache + rendered-HTML cache (storeHtml/htmlStale), mtime sentinels
│   ├── assets/
│   │   ├── render.esm.js         # committed fallback render bundle (client-side mdms)
│   │   └── edit.esm.js           # committed live-page editor bundle (injected only for logged-in sessions)
│   │   └── design.html           # /design workbench — public style guide, user-steered
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
│   │   ├── modules.ts            # the module wall: directive allowlist → hast — image (+fillable empty slot), video, embed, slide (vertical deck: anchorId() + per-render usedIds registry, titles → anchors for in-page links), note (tip/warn), details, more (+fold); unknown → p.sm-unknown-module, hostile attrs dead at the stamper
│   │   ├── render.ts             # THE one render pipeline (fallback + editor share it)
│   │   ├── schema.ts             # Zod: PageFrontmatter (blog left for plugin-land)
│   │   └── app.css               # tokens: crust/batter/raisin/jam
│   ├── public/
│   │   ├── fallback.ts           # client fallback renderer → render.esm.js
│   │   └── edit.ts               # live-page editor (burger overlay, click-to-edit, new-page modal) → edit.esm.js
│   ├── routes/
│   │   ├── +layout.svelte        # imports ../lib/app.css (relative — $lib evasion, see GUIDE)
│   │   ├── +layout.ts            # prerender = true
│   │   └── +page.svelte          # login/setup + burger overlay (Pages/Site/Accounts/Media/Plugins) — MANAGEMENT-ONLY: picking a page navigates to the live page (canvas retired)
│   └── content/                  # install-local user data — GITIGNORED, never committed
│       ├── pages/                # content types (dirs = types, subdirs = slugs)
│       │   └── <slug>/index.md   # + .draft.md sidecar + page-local media files
│       └── .assets/              # site-level uploads (logo) — dot-dir, invisible to listAll
├── MCP/
│   ├── GOSPEL.md                 # the law
│   ├── BACKLOG.md                # the ledger
│   ├── GUIDE.md                  # the kinks
│   └── FILETREE.md               # this sanity check
