# Schmarrn

A flat-file, headless-ish CMS you dump onto any FTP host and it just runs. No database, no Node on the server, no build step on the host.

- **PHP core** (`bridge/`) — serves a strict JSON API under `/bridge`: raw `.md` read/write, auth, file-based cache. Renamed from `api/` in v1.0.0 (scanner bots hammer the generic prefix; the bridge door isn't on their map).
- **Svelte dashboard** (`src/`) — SvelteKit + `adapter-static` editor; preview and public rendering share one `parseMdms()` call.
- **Content** (`src/content/`) — one folder per page/post, `index.md` with strictly validated frontmatter.
- **Parser** — [`@swisd-project/mdms`](https://github.com/swisd-project/mdms), the sole Markdown→HTML transformer.

## Layout

```
bridge/          PHP core — JSON core under /bridge, plus its dedicated homes:
                 bridge/templates/ (themes) and bridge/plugins/ (plugins)
src/             SvelteKit dashboard + content tree
pagetypes/       page-type scaffolds (blank, landing, gallery, article)
docs/       theme-contract.md — what themes may assume, enforced by code
MCP/        GOSPEL (law), BACKLOG (ledger), GUIDE (kinks)
```

## Dev (PHP + Vite — one command, both servers)

From the repo root:

```sh
npm run dev
```

starts **one server only**: the PHP core (`bridge/`) on `:8080`. It serves the public site, the JSON API, the live-page editor, **and the dashboard at `/_`** (the built SPA in `build/` — run `npm run build` once after a fresh clone, or when dashboard code changed). One console, one origin, no confusion.

Use `npm run dev:app` **only** when working on the dashboard UI itself: starts the Vite dev server on `:5173` (proxies `/bridge/*` to `:8080`) for HMR. `npm run dev:api` is the same single PHP server, spelled differently for muscle memory.

Open **http://localhost:8080/_/** for the dashboard (`npm run build` provides it) and `http://localhost:8080` for the public site — PHP alone, one origin. First-run password is `changeme` (template hash; mint a real one, see below).

Requirements: PHP ≥ 8.4 (dev on 8.5; on Ubuntu/Zorin use `ppa:ondrej/php` → `sudo apt install php8.5-cli php8.5-mbstring php8.5-xml`). VS Code riders: the DEVSENSE "PHP" extension's built-in server is just `php -S` — a PHP binary is required; the repo ships a matching `.vscode/launch.json` profile (F5 → "schmarrn: API") and the `.vscode/tasks.json` task `Dev` (plus an opt-in `Dev: Dashboard UI` for Vite HMR).

## Reset the admin password

The hash lives in `bridge/config/auth.local.php` (git-ignored; falls back to `bridge/config/auth.php` template). Mint one, consciously:

```sh
php -r "echo password_hash('your-login-password', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]), PHP_EOL;"
```

Note: PHP `password_verify` does not verify hashes minted by Node's `argon2` package — generate with PHP.

## GitHub Pages (gated releases)

Dashboard shell deploys to `https://swisd-project.github.io/schmarrn/` **only via manual greenlight**: Actions → *Deploy to Pages (greenlit versions only)* → Run workflow → enter your version note. No push ever triggers a deploy.

Note: Pages is static — the `/api` endpoints don't exist there. It's the shell/preview layer; the living CMS always runs on your PHP host.

## Deploy (FTP — the real thing)

Requirements on the host: **PHP ≥ 8.4.27** (or ≥ 8.3.36 / ≥ 8.2.35 — see the security note below), Apache with `.htaccess` honored (`AllowOverride All`), and three writable paths: `bridge/config/`, `bridge/cache/`, `src/content/`. Nothing else — no database, no composer, no Node on the server.

> **Security note (PHP2Shell, October 2026):** CVE-2026-109618 / CVE-2026-109619 are unauthenticated remote-code-execution flaws in the PHP core itself (CVSS 9.2, in the CISA Known Exploited catalog). They affect every PHP-based site regardless of application code and are fixed in **PHP 8.4.27, 8.3.36, 8.2.35 and 8.5.12**. Do not deploy Schmarrn — or anything else — on a PHP version older than these. No application-level hardening can substitute for a patched interpreter.

### What to upload

```
index.php            front controller: /bridge/* → core, /_ → dashboard, else the public site
.htaccess            whitelist law (only _/, bridge/templates/, bridge/assets/, bridge/plugins/*/assets/ serve static)
bridge/                 the PHP core (bridge/assets/ ships the committed render/edit bundles)
bridge/templates/              your theme(s)
pagetypes/           the page-type scaffolds
src/content/         your content tree (the shipped seed: one hello-world page)
_/                   the CONTENTS of build/ — inside a host folder literally named _
```

Build the dashboard once, locally:

```sh
npm ci && npm run build
```

then upload the **contents of `build/` into a folder named `_`** on the host. Not loose at the root: the built `index.html` would shadow `index.php` via `DirectoryIndex` and put a login wall on your public face. (The editor bundles `bridge/assets/edit.esm.js` and `render.esm.js` are committed — you only need Node if you change `src/` yourself; then also run `npm run build:edit` / `npm run build:fallback`.)

### First run

1. Open `https://your-host.example/_/` — the setup wizard appears (site name, theme, admin account).
2. After setup you land on the public site at `/`. The public face never wears a login form.
3. Log in any time at `/_/`. Editing happens **on the live page**: click a block → floating menu → edit → save, live instantly — at the right middle sits the admin drawer: a thin white rect with a grey triangle (flips right when open); a click slides it wide, showing **the overlay's tabs as items** — Contents | Settings | Plugins & Templates | Users (admin-only tabs hidden for editors) — each opens the admin overlay pre-selected on that tab, plus **Log out**. At `/_` the ONE full-screen overlay **is** the authed face — it opens itself with the tab bar. **Close** (button, burger or Escape — the listener is window-level, keyboard trap impossible) walks back to *the exact carried page* (pure, unit-battery-ed law in `src/lib/back.ts`; a typed-URL visit falls back to the front door — never `history.back()`, which a restored tab can no-op). There is no separate dashboard page behind the overlay.
4. The **nav is a plain core nav** — published pages of `pages` as links, identical bytes for editors and visitors. In-page menu editing (the old ghost plus) retired 2026-11-04: menu management lives in the dashboard overlay, not on the live canvas.
5. The dashboard at `/_/` stays management-only: the overlay tabs above carry everything; picking a page in Contents walks you onto the live page.

`/_` canonicalizes to `/_/` — the trailing slash is load-bearing.

SPDX-License-Identifier: MPL-2.0 · Copyright (c) 2026 Homahuki GmbH
