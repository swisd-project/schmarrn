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
├── svelte.config.js              # adapter-static, fallback SPA; kit.files.assets = src/static (the house pictures ride into the build)
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
│   ├── run-tests.sh                # THE TEST RUNNER: `npm test` — byte-identity + arch-lint + the five batteries, one verdict
│   ├── arch-lint.mjs               # the architecture law, enforced: domain purity, queries never write, commands unreachable from GET, every mutating route names a command (`npm run lint:arch`)
│   ├── repair-frontmatter.php      # the frontmatter repair service, CLI door — the same service POST /bridge/meta/repair rides; whole tree or one page, never silent
│   └── (*.mts)                   # dev probes
├── tests/                          # the batteries live in the REPO now (p8 STEP 1 — the /tmp ones died with the machine)
│   ├── harness.php                 # the shared verdict harness: check() = must-pass, xfail() = known defect (printed loudly, never blocking), verdict()
│   ├── snapshot.php                # the BYTE-IDENTITY GATE: 26 public URLs, sha256 each, --write captures the baseline (tests/fixtures/baseline.json)
│   ├── wares-battery.php           # the wares pipeline battery, rebuilt from the guardrails spec (24 cases) — sandboxed, local file:// shelf, no network
│   ├── boundary-battery.php        # the plugin × core wall, rebuilt (21 cases)
│   ├── lid-battery.php             # the code lid (14 cases, incl. the call-before-declaration repro)
│   ├── meta-battery.php            # the frontmatter contract's first battery (7 cases, pins Meta\peek's tags reader: bare YAML flow / JSON-quoted / mixed / escaped quotes / absent key) — born of the editor's bare-flow tags vs json_decode (see BACKLOG p8 findings)
│   ├── battery-bienenstich.php     # thin wrapper for the bienenstich wall — installed copy with battery first, else the sibling Vitrine pair; SKIP when absent (the 32 cases live in the Vitrine ware)
│   ├── ware-battery.php            # the ware-battery hook (2026-11-13): `php tests/ware-battery.php <ware-dir>` — hands rig + harness + a live admin session to a ware's own battery.php; cases live in the ware's repo
│   ├── refusal-battery.php         # every domain refusal pinned to its exact status+body (24 cases) — the STEP 2 proof
│   ├── core-update-battery.php     # the staging law + the FULL local ride (31 cases): isCore's word, a real packet through stageCore (rings carried, smuggles refused), then the knife on a rig — floor moved, rings survived, site still serves
│   └── rig.php                     # the rig builder: a throwaway Schmarrn from the current tree (admin account, content, the bienenstich ware enabled)
├── tests/fixtures/baseline.json    # the byte-identity baseline (committed — the gate's reference)
├── bridge/                       # PHP core — front controller + libs, no framework; lives under /bridge (bit-scan prey no longer fed)
│   ├── .htaccess                 # legacy (root .htaccess is the law now)
│   ├── index.php                 # JSON routes: login/session, pages, drafts, publish(+html), pagetypes, templates (admin theme inventory), public API, media, plugins; p8 STEP 4: the six page routes dispatch through the page COMMANDS (write) and QUERIES (read); POST /bridge/meta/repair rides here too
│   ├── serve.php                 # public serving engine: dispatch, templates, cached HTML, fallback shell, SEO head, plugin fills, editor injection (data-sm-edit-root + edit.esm.js; nav ghost RETIRED 2026-11-04 — navHtml is plain core nav, same bytes for editors and visitors); styleOf: page frontmatter → GLOBAL site style → theme default
│   ├── site.php                  # brand settings load/save (template < site.local.php) + site assets (.assets/); `style` = GLOBAL style ('' | a theme-declared style, whitelist-validated); `styles` = the theme handles (per-theme CSS overrides: ['<theme>' => ['--var' => 'value']], git-ignored)
│   ├── wares.php                 # Vitrine fetch + install (p6 slice 3): registry GET (TTL cache) + codeload packet zip → extract ONLY <path>/ (ZipArchive | PharData), manifest gate, version gate (hostSatisfies PARSES its constraint — >=, >, <=, <, = / == or a bare version; unreadable constraint fails CLOSED, 2026-11-13 fix), disable-first upgrade w/ rollback; GET /bridge/wares + POST /bridge/wares/install (admin+CSRF); removal too (Wares\removePlugin/removeTemplate — tomb rename, lock + switch hygiene); URLs override in config/wares.local.php (ignored)
│   ├── plugins.php               # p6 capability contract: scan/validate/load/dispatch, state in config/plugins.local.php; codeCheck runs TWO-PASS (2026-11-13 fix): declarations collected first, calls judged second — a legal call-before-declaration passes
│   ├── router.php
│   ├── auth.php                  # Argon2id password, session, 24h
│   ├── accounts.php              # file-backed account store + invites
│   ├── mail.php                  # SMTP via vendored PHPMailer
│   ├── vault.php                 # the house vault — secrets sealed via libsodium secretbox, master keyfile 0600
│   ├── lock.php                  # write-path lock gates (acquire + assertFreeOrMine; TTL frees cold locks; HTTP lock endpoints retired with the canvas)
│   ├── store.php                 # content store: list/read/write (atomic), slug discipline; the write path runs the frontmatter gate (Meta\gate on write); listAll takes the explicit view parameter (null = session decides)
│   ├── cache.php                 # raw cache + rendered-HTML cache (storeHtml/htmlStale), mtime sentinels
│   ├── templates/                # the theme contract's dedicated home (was root-level themes/)
│   │   ├── bakery/               # default theme (classical-first; slide.html too) — the BLUEPRINT
│   │   │   ├── theme.json        # styles + handles (the knobs the Settings tab turns)
│   │   │   ├── preview.png       # the shelf image NEXT TO theme.json (a real screenshot, SQUARE 640×640, cropped to the theme's face) — the shelf shows the theme
│   │   │   ├── page.html             # {{placeholders}} incl. {{navHtml}} — str_replace only
│   │   │   ├── frontpage.html        # the reserved frontpage style (the crown's face)
│   │   │   ├── sub-page-1.html       # the worked-example style
│   │   │   ├── slide.html
│   │   │   └── assets/{theme.css,deck.js}     # theme.css = the ONE stylesheet (every style lives here; the :root tokens are the handles); deck.js: dots nav + active-slide tracking + hash-following + mid-air guard (companion script, slide.html includes it)
│   │   └── atelier/              # artist presentation theme (slide-first, depth system)
│   │       ├── theme.json
│   │       ├── page.html
│   │       ├── slide.html
│   │       └── assets/{atelier.css,deck.js}   # same helper script, same law
│   ├── plugins/                  # plugin homes in the core (was root-level plugins/) — ONE preinstalled default (hello), deletable like any other (POST /bridge/plugin/delete)
│   │   ├── hello/                # the PREINSTALLED default (copy-me template): placeholder + public/user routes; also a Vitrine ware (the shelf copy — installable again after a deletion)
│   │   │   ├── plugin.json
│   │   │   ├── plugin.json
│   │   │   ├── plugin.php
│   │   │   └── (assets/)         # OPTIONAL per-plugin convention: preview.png|.jpg|.jpeg|.webp — the plugin's own shelf picture (the ONLY place the plugin home serves; Plugins\shelfShot())
│   │   └── (*.local installs)    # runtime-installed wares (monk + stop-motion live in the VITRINE repo now; the base keeps hello alone); install lands here via the wares slice, delete via Wares\removePlugin (POST /bridge/plugin/delete)
│   ├── assets/
│   │   ├── render.esm.js         # committed fallback render bundle (client-side mdms)
│   │   └── edit.esm.js           # committed live-page editor bundle (injected only for logged-in sessions; carries the nav ghost)
│   ├── config/
│   │   ├── auth.php              # TEMPLATE (hash of "changeme")
│   │   ├── accounts.php          # TEMPLATE
│   │   ├── mail.php              # TEMPLATE
│   │   ├── site.php              # TEMPLATE (brand settings)
│   │   ├── vault.local.php        # sealed envelopes (git-ignored)
│   │   ├── vault.key              # vault master key (0600, git-ignored)
│   │   └── (*.local.php)         # git-ignored real config
│   ├── lib/phpmailer/            # vendored
│   ├── domain/                     # the DOMAIN ring (p8): error.php (Domain\Error — the domain throws, the transport maps), leash.php (the editor leash), meta.php (the frontmatter contract: peek/gate/defects/repair — ONE owner, no YAML parser)
│   ├── application/                # the APPLICATION ring (p8): commands/ (page.php — the page commands own the cache drop; meta-repair.php — the on-demand frontmatter repair), queries/ (page.php — index/one/publicOne/search, the view rides in explicitly), projections/ (html.php + index.php — the two NAMED read models)
│   └── (cache/)                  # git-ignored writable cache (+ html/ subdir)
├── src/
│   ├── app.html
│   ├── app.d.ts
│   ├── static/                     # house pictures 01.jpeg–05.jpeg (+ robots.txt stub) — served at /_/<name> via kit.files.assets; the random-kept fallback when a theme/plugin ships no preview
│   ├── package.json → moved to root (drag artifact, do not recreate)
│   ├── lib/
│   │   ├── api.ts                # typed fetcher for /api (dashboard-facing surface only — the live editor fetches /api directly)
│   │   ├── blocks.ts             # block model: splitBlocks/joinPage (exact source spans)
│   │   ├── modules.ts            # the module wall: directive allowlist → hast — image (+fillable empty slot), video, embed, slide (vertical deck: anchorId() + per-render usedIds registry, titles → anchors; themes ship assets/deck.js for measured heights + gliding anchor links), note (tip/warn), details, more (+fold); unknown → p.sm-unknown-module, hostile attrs dead at the stamper; safeUrls() URL gate after the wall (javascript:/data: schemes die, honest URLs live)
│   │   ├── render.ts             # THE one render pipeline (fallback + editor share it)
│   │   ├── schema.ts             # Zod: PageFrontmatter (blog left for plugin-land)
│   │   ├── app.css               # tokens: crust/batter/raisin/jam; .card-shelf (the shelf card: square picture flush left, three lines) + .btn-static (the inert "active" label)
│   │   └── components/            # p8 STEP 6 dashboard split (2026-11-13), 17 components: ForgotModal GithubModal MailModal ConfirmModal UploadModal PageSettingsModal NewPageModal MediaModal PanelModal GithubShelf (shared by Plugins + Templates tabs) UsersTab UpdatesTab SettingsTab TemplatesTab PluginsTab ContentsTab — ContentsTab is `$bindable` on `index`; the tab sweeps/re-sweeps it itself
│   ├── public/
│   │   ├── fallback.ts           # client fallback renderer → render.esm.js
│   │   └── edit.ts               # live-page editor, canvas-only since 2026-11-04 (one-management-overlay law: click-to-edit float menu + modals + toast; the admin door at the right middle = white rect tab → expandable side menu (Backend / Log out), carrying ?back=<page>) → edit.esm.js
│   ├── routes/
│   │   ├── +layout.svelte        # imports ../lib/app.css (relative — $lib evasion, see GUIDE)
│   │   ├── +layout.ts            # prerender = true
│   │   ├── +page.svelte          # 932 lines (was 3,318 — p8 STEP 6 dashboard split, 2026-11-13): login/setup + auth shell + menu chrome + the `{#if menuOpen}` overlay with six one-line tab members + modal instances at root; tab bodies live in src/lib/components/; select() walks authed hands onto the editor URL `/_/<slug>` (or `/_/<type>/<slug>`), lost session rides the plain path (serve.php adminMode 302 = guest rail) — /_ walk law, 2026-11-13
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
