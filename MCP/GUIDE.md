<!--
1. Relative path: MCP/GUIDE.md
2. Description: Living collection of kinks, necessities, and lessons while building Schmarrn.
3. Expects: Entries appended as traps are found and defused.
4. Provides: On-the-go practical lore; complements the law in GOSPEL.md.
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

Schmarrn GUIDE

(Dogma, not anecdotes — full of kinks from the workshop floor.)

- SvelteKit = the dashboard, not the content surface: no `import.meta.glob` for content. Content is fetched from `/api`, never baked into the bundle (SwISD-site `loader.ts` lesson).
- One parser to rule them all: every rendered HTML in editor preview AND public routes must originate from one `parseMdms()` invocation — no second renderer sneaks in.
- `.md` files single-file write on save (frontmatter + body in one atomic PUT). No partial state, use temp+rename in the PHP layer.
- PHP never parses Markdown; it stores, serves and caches bytes. Parsing decision lives in TS/browser.
- Sanitization boundary: admin content trusted, everything else DOMPurify'd (dompurify via `parseMdms` output).
- Slug discipline: filename = slug = `^[a-z0-9-]+$`; PHP validates on save, Zod validates on read. If one strays — hard failure.
- Kit `alias: { $lib }` was REMOVED from svelte.config: `$lib` TS/JS imports resolve via Kit's built-in alias, but CSS imports evade it — CSS is imported relatively (`../lib/app.css`). Re-tested if Kit ≥ 2.22 changes.
- SvelteKit project root = 08_Schmarrn repo root (package.json at root, sources under `src/`) — NOT a nested dashboard folder; `.svelte-kit` and `node_modules` sit at root. `api/` stays PHP-only.
- SPA fallback of adapter-static: name it something other than `index.html` later (e.g. `dashboard.html`) — adapter warns when it overwrites the prerendered root.
