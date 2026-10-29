# Schmarrn

A flat-file, headless-ish CMS you dump onto any FTP host and it just runs. No database, no Node on the server, no build step on the host.

- **PHP core** (`api/`) — serves a strict JSON API: raw `.md` read/write, auth, file-based cache.
- **Svelte dashboard** (`src/`) — SvelteKit + `adapter-static` editor; preview and public rendering share one `parseMdms()` call.
- **Content** (`src/content/`) — one folder per page/post, `index.md` with strictly validated frontmatter.
- **Parser** — [`@swisd-project/mdms`](https://github.com/swisd-project/mdms), the sole Markdown→HTML transformer.

## Layout

```
api/        PHP core (front controller + /api endpoints)
src/        SvelteKit dashboard + content tree
MCP/        GOSPEL (law), BACKLOG (ledger), GUIDE (kinks)
```

## Dev (PHP + Vite — one command, both servers)

From the repo root:

```sh
npm run dev
```

starts both via [`concurrently`](https://www.npmjs.com/package/concurrently): the PHP API on `:8080` (blue) and the Vite dashboard on `:5173` (green). Ctrl-C stops both. Solo variants: `npm run dev:api` / `npm run dev:app`.

Open **http://localhost:5173** — Vite proxies `/api/*` to `:8080`, so everything lives on one origin. First-run password is `changeme` (template hash; mint a real one, see below).

Requirements: PHP ≥ 8.4 (dev on 8.5; on Ubuntu/Zorin use `ppa:ondrej/php` → `sudo apt install php8.5-cli php8.5-mbstring php8.5-xml`). VS Code riders: the DEVSENSE "PHP" extension's built-in server is just `php -S` — a PHP binary is required; the repo ships a matching `.vscode/launch.json` profile (F5 → "schmarrn: API") and `.vscode/tasks.json` task `Dev: BOTH`.

## Reset the admin password

The hash lives in `api/config/auth.local.php` (git-ignored; falls back to `api/config/auth.php` template). Mint one, consciously:

```sh
php -r "echo password_hash('your-login-password', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]), PHP_EOL;"
```

Note: PHP `password_verify` does not verify hashes minted by Node's `argon2` package — generate with PHP.

## GitHub Pages (gated releases)

Dashboard shell deploys to `https://swisd-project.github.io/schmarrn/` **only via manual greenlight**: Actions → *Deploy to Pages (greenlit versions only)* → Run workflow → enter your version note. No push ever triggers a deploy.

Note: Pages is static — the `/api` endpoints don't exist there. It's the shell/preview layer; the living CMS always runs on your PHP host.

## Deploy (FTP — the real thing)

Requirements on the host: PHP ≥ 8.4, Apache with `.htaccess` honored (`AllowOverride All`), and three writable paths: `api/config/`, `api/cache/`, `src/content/`. Nothing else — no database, no composer, no Node on the server.

### What to upload

```
index.php            front controller: /api/* → core, /_ → dashboard, else the public site
.htaccess            whitelist law (only _/, themes/, api/assets/, plugins/*/assets/ serve static)
api/                 the PHP core (api/assets/ ships the committed render/edit bundles)
themes/              your theme(s)
pagetypes/           the page-type scaffolds
src/content/         your content tree (start with the seeded pages)
_/                   the CONTENTS of build/ — inside a host folder literally named _
```

Build the dashboard once, locally:

```sh
npm ci && npm run build
```

then upload the **contents of `build/` into a folder named `_`** on the host. Not loose at the root: the built `index.html` would shadow `index.php` via `DirectoryIndex` and put a login wall on your public face. (The editor bundles `api/assets/edit.esm.js` and `render.esm.js` are committed — you only need Node if you change `src/` yourself; then also run `npm run build:edit` / `npm run build:fallback`.)

### First run

1. Open `https://your-host.example/_/` — the setup wizard appears (site name, theme, admin account).
2. After setup you land on the public site at `/`. The public face never wears a login form.
3. Log in any time at `/_/`. Editing happens **on the live page**: click a block → floating menu → edit → save, live instantly. The burger (bottom-right) opens the full-screen overlay: page settings, the site-wide template (admins), ＋ New page from a page-type scaffold, log out.
4. The dashboard at `/_/` stays management-only: page tree (rename/delete/upload), site settings, accounts, media, plugins.

`/_` canonicalizes to `/_/` — the trailing slash is load-bearing.

SPDX-License-Identifier: MPL-2.0 · Copyright (c) 2026 Homahuki GmbH
