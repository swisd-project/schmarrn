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
├── index.php                     # root front controller: /api/* → core, else public site (also php -S router)
├── .htaccess                     # deny sensitive dirs; real files pass; rewrite to index.php
├── themes/
│   └── bakery/                   # default theme (theme contract: manifest + template + assets)
│       ├── theme.json
│       ├── page.html             # {{placeholders}} — str_replace only, no code exec
│       └── assets/bakery.css
├── scripts/
│   ├── build-fallback.mjs        # esbuild → api/assets/render.esm.js (committed)
│   └── (*.mts)                   # dev probes
├── api/                          # PHP core — front controller + libs, no framework
│   ├── .htaccess                 # legacy (root .htaccess is the law now)
│   ├── index.php                 # JSON routes: login/session, pages, drafts, publish(+html), media, locks
│   ├── serve.php                 # public serving engine: dispatch, themes, cached HTML, fallback shell, SEO head
│   ├── site.php                  # brand settings load/save (template < site.local.php)
│   ├── router.php
│   ├── auth.php                  # Argon2id password, session, 24h
│   ├── accounts.php              # file-backed account store + invites
│   ├── mail.php                  # SMTP via vendored PHPMailer
│   ├── lock.php                  # edit locks
│   ├── store.php                 # content store: list/read/write (atomic), slug discipline
│   ├── cache.php                 # raw cache + rendered-HTML cache (storeHtml/htmlStale), mtime sentinels
│   ├── assets/
│   │   └── render.esm.js         # committed fallback render bundle (client-side mdms)
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
│   ├── hooks.server.ts
│   ├── package.json → moved to root (drag artifact, do not recreate)
│   ├── lib/
│   │   ├── api.ts                # typed fetcher for /api
│   │   ├── schema.ts             # Zod: PageFrontmatter (blog left for plugin-land)
│   │   └── app.css               # tokens: crust/batter/raisin/jam
│   ├── routes/
│   │   ├── +layout.svelte        # imports ../lib/app.css (relative — $lib evasion, see GUIDE)
│   │   ├── +layout.ts            # prerender = true
│   │   └── +page.svelte          # login wall + page tree (runes)
│   └── content/
│       └── pages/ha-for-you/index.md
├── MCP/
│   ├── GOSPEL.md                 # the law
│   ├── BACKLOG.md                # the ledger
│   ├── GUIDE.md                  # the kinks
│   └── FILETREE.md               # this sanity check
