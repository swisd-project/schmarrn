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
├── api/                          # PHP core — front controller + libs, no framework
│   ├── .htaccess                 # rewrite /api/* → index.php; library files denied
│   ├── index.php                 # routes: login/logout/session, pages, page/{type}/{slug}, cache
│   ├── router.php
│   ├── auth.php                  # Argon2id password, session, 24h
│   ├── store.php                 # content store: list/read/write (atomic), slug discipline
│   ├── cache.php                 # raw-body cache + mtime sentinel
│   ├── config/
│   │   ├── auth.php              # TEMPLATE (hash of "changeme")
│   │   └── (auth.local.php)      # git-ignored real hash
│   └── (cache/)                  # git-ignored writable cache
├── src/
│   ├── app.html
│   ├── app.d.ts
│   ├── hooks.server.ts
│   ├── package.json → moved to root (drag artifact, do not recreate)
│   ├── lib/
│   │   ├── api.ts                # typed fetcher for /api
│   │   ├── schema.ts             # Zod: PageFrontmatter + BlogFrontmatter
│   │   └── app.css               # tokens: crust/batter/raisin/jam
│   ├── routes/
│   │   ├── +layout.svelte        # imports ../lib/app.css (relative — $lib evasion, see GUIDE)
│   │   ├── +layout.ts            # prerender = true
│   │   └── +page.svelte          # login wall + page tree (runes)
│   └── content/
│       ├── pages/ha-for-you/index.md
│       └── blog/off-the-griddle/index.md   # <!-- more --> split demo
├── MCP/
│   ├── GOSPEL.md                 # the law
│   ├── BACKLOG.md                # the ledger
│   ├── GUIDE.md                  # the kinks
│   └── FILETREE.md               # this sanity check
