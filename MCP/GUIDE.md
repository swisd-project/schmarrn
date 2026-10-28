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
- Runes rule enforced live: never write `$state` from inside `$derived` — docs forbid impurity; derive the object including its own error field and render from there.
- `$effect` page-load guard: track the key being loaded in a `loadedFor` pointer and skip refetch when a save just wrote the same file.
- Namespace resolution trap: inside `namespace Store`, unqualified `Auth\check()` resolves to `Store\Auth\check()` — always fully qualify cross-namespace calls (`\Auth\check()`).
- API contract: `GET /api/pages` is PUBLIC (anon gets drafts filtered; editors get the full tree with `draft` flags) — public rendering depends on it staying open.
- PHP never parses Markdown; it stores, serves and caches bytes. Parsing decision lives in TS/browser.
- Sanitization boundary: admin content trusted, everything else DOMPurify'd (dompurify via `parseMdms` output).
- Slug discipline: filename = slug = `^[a-z0-9-]+$`; PHP validates on save, Zod validates on read. If one strays — hard failure.
- PHP-land kinks made flesh on smoke test: (1) `self::`/`self::X()` is a fatal in plain namespaced functions — call bare names; (2) namespace constants need no leading `\` and can't take one; (3) list destructure `[$a,$b] = assoc()` only works on list-shaped keys; (4) PHP `password_verify` (sodium argon2id) does NOT verify hashes minted by Node's `argon2` package — always mint admin hashes with PHP (see api/config/auth.php header).
- Namespace-crossing lesson #5, now corporeal law: inside ANY lib namespace, unqualified `Router\refuse()` resolves to `ThisLib\Router\refuse()` — fataled in Auth, Accounts, Mail AND Lock before the pattern stuck. ALWAYS write `\Router\refuse()` in library namespaces. The `[schmarrn-500]` error-log line in index.php's catch makes these visible.
- Namespace-crossing lesson #6, the builtin-shadow edition: naming a lib function after a PHP builtin (`Store\rename()`) hijacks the builtin for the ENTIRE namespace — `write()`'s plain `rename()` call silently became `Store\rename($tmp, $path)` (ArgumentCountError). Never define namespace functions named like builtins; qualify builtin calls (`\rename()`) when a shadow exists.
- `npm run dev` now starts BOTH servers via `concurrently` (api = php -S :8080, app = vite); split scripts `dev:app` / `dev:api` remain for solo runs.
- Kit `alias: { $lib }` was REMOVED from svelte.config: `$lib` TS/JS imports resolve via Kit's built-in alias, but CSS imports evade it — CSS is imported relatively (`../lib/app.css`). Re-tested if Kit ≥ 2.22 changes.
- SvelteKit project root = 08_Schmarrn repo root (package.json at root, sources under `src/`) — NOT a nested dashboard folder; `.svelte-kit` and `node_modules` sit at root. `api/` stays PHP-only.
- SPA fallback of adapter-static: name it something other than `index.html` later (e.g. `dashboard.html`) — adapter warns when it overwrites the prerendered root.
