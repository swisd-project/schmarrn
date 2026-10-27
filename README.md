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

## Dev

```sh
php -S localhost:8080 -t api          # API
cd src && npm run dev                 # dashboard
```

## Dev (two servers, one proxy)

1. **API** — `php -S localhost:8080 -t api` … or DEVSENSE's "PHP" extension: right-click `api/index.php` → *PHP: Serve Project* (it can download a PHP runtime itself if you have none; if it uses another port, change the proxy target in `vite.config.ts`).
2. **Dashboard** — `npm run dev` (or VS Code task **Dev: BOTH**) → open http://localhost:5173. Vite proxies `/api/*` to :8080, so everything lives on one origin.

## GitHub Pages (gated releases)

Dashboard shell deploys to `https://swisd-project.github.io/schmarrn/` **only via manual greenlight**: Actions → *Deploy to Pages (greenlit versions only)* → Run workflow → enter your version note. No push ever triggers a deploy.

Note: Pages is static — the `/api` endpoints don't exist there. It's the shell/preview layer; the living CMS always runs on your PHP host.

## Deploy (FTP — the real thing)

Upload `api/`, the built dashboard assets and `src/content/` to the FTP host. The only writable directory is `api/cache/`.

SPDX-License-Identifier: MPL-2.0 · Copyright (c) 2026 Homahuki GmbH
