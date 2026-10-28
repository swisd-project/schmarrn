<!--
1. Relative path: MCP/GOSPEL.md
2. Description: The unbreakable doctrine for Schmarrn — rules, environment, and architecture invariants.
3. Expects: Nothing. It is the law.
4. Provides: Locked decisions, structure, and environment for the Schmarrn flat-file CMS.
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

SCHMARRN GOSPEL

The flat-file CMS that you just dump onto any FTP server and it runs. No database, no Node on the server, no build step on the host.

Locked Decisions (Reference)

- Name: Schmarrn (one "m" in Schmarrn, exactly two "r"s — "Schmarrrn" with three is the pancake you eat, not the project you ship).
- Namespace: `@swisd-project/*` on npm (e.g. shared types). Core stays plain PHP.
- Architecture: PHP core (`api/`) serves a strict JSON API under `/api` — fetch raw `.md`, save, list, auth, file-based HTML cache. No Markdown parsing in PHP.
- Parsing core: `mdms` (`@swisd-project/mdms`) is the sole Markdown→HTML transformer. One renderer everywhere; no `marked`, no `gray-matter` duplicates.
- Content store: `/src/content/<type>/<slug>/index.md` with YAML frontmatter, validated by one strict Zod schema per content type. Slug-vs-filename mismatch = hard error.
- Admin dashboard: Svelte(5)/SvelteKit built with `adapter-static`; the built assets are committed/served by the PHP side as static files. Editing model: full-page visual editor — the public page IS the canvas (Automad-style click-to-edit); complex items are edited via save-confirm modals; no two-column textarea|preview layout.
- Trust boundary: `mdms` outputs raw HTML. Admin-authored content is trusted; anything untrusted MUST be sanitized (DOMPurify) before injection. Guardrail lives in the API layer, day one.
- Domain splitting (previewHtml/fullHtml): superseded by the module model — any preview/full split is decided per module spec, not page-model-wide.
- No CRDT.md: this project has no distributed math. Files on disk, one editor at a time, coarse last-write-wins.
- Multi-editor: named accounts (`admin`, `editor` roles), Argon2id hashes in a file-backed account store (git-ignored real file), server-side session records in the writable dir, CSRF tokens, login rate-limiting via the file cache. Plain FTP hosting is a supported target — cookies/sessions work under PHP+Apache.
- Mail: SMTP for invites/password-resets via one bundled "shoulders of giants" PHP mail library (e.g. PHPMailer vendored into the repo — no composer invocation). Deliberate bend of the no-third-party posture; SMTP credentials are admin config.
- Modules: Markdown stays the source of truth. A small closed set of preset module boundary tags (text, image, image+caption, video/html embed) reference page-local structured data; media references are by ID to immutable uploaded assets. mdms is extended to render modules — one parser law still holds everywhere.
- Module data location (frontmatter vs sidecar JSON): DECIDED ON THE GO — pinned in the module spec as work reveals it.
- Themes are installable without touching the codebase: theme contract = manifest + assets/templates; enabled from the dashboard. Global brand settings (logo, claim, name) are referencable across pages.
- Public JSON API: read-only, per page (title, body HTML, children, files) — Schmarrn doubles as a quick-deploy backend. CORS scoped for known consumers.
- Serving: clean URLs via front controller + `.htaccess`. Editors' browsers render HTML at save time; PHP stores/serves/caches cached HTML per page (per-page hash file + site-mtime sentinel, Automad pattern). SEO/link previews are requirements.
- Requirements phasing: `gd` + `zip` are v2 requirements (image processing, packaging). v1 uploads store originals untouched. PHP 8.4+ still stands for everything.
- MCP ledger discipline: BACKLOG.md = do-not-forget ledger; FILETREE.md = sanity check; GUIDE.md = kinks and necessities developed on the go; GOSPEL.md = this file. All decisions go through MCP entries before code.

Environment

- Local dev: PHP 8.5 built-in server (`php -S`) for `api/`, Vite dev server for `dashboard/`.
- Deploy: FTP upload of one folder (api + built dashboard + content). Writable dirs needed for: cache, per-host sessions, account/file-backed store, uploads (per-host reality, all under the install).
- Requirements: PHP 8.4+ on deploy (dev on 8.5 — 8.2's security support ends Dec 2026, don't build on it). gd+zip enter only in v2 (see Locked Decisions). No composer, no database, no cron.

Folders

- `api/`     — PHP core: `/api` JSON endpoints, auth, file cache, content store access.
- `src/`     — SvelteKit dashboard (source); builds to static assets consumed by `api/`.
- `src/content/` — the canonical `.md` content tree, one folder per page/post.
- `MCP/`     — governance files (GOSPEL, BACKLOG, GUIDE, FILETREE).
