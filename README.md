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

Upload `api/`, the built dashboard assets and `src/content/` to the FTP host. The only writable directory is `api/cache/`.

SPDX-License-Identifier: MPL-2.0 · Copyright (c) 2026 Homahuki GmbH
