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
- API contract: `GET /api/pages` is PUBLIC (anon gets drafts filtered; editors get the full tree with `draft` flags) — public rendering depends on it staying open.
- PHP never parses Markdown; it stores, serves and caches bytes. Parsing decision lives in TS/browser.
- Sanitization boundary: admin content trusted, everything else DOMPurify'd (dompurify via `parseMdms` output).
- Slug discipline: filename = slug = `^[a-z0-9-]+$`; PHP validates on save, Zod validates on read. If one strays — hard failure.
- PHP-land kinks made flesh on smoke test: (1) `self::`/`self::X()` is a fatal in plain namespaced functions — call bare names; (2) namespace constants need no leading `\` and can't take one; (3) list destructure `[$a,$b] = assoc()` only works on list-shaped keys; (4) PHP `password_verify` (sodium argon2id) does NOT verify hashes minted by Node's `argon2` package — always mint admin hashes with PHP (see api/config/auth.php header).
- Namespace-crossing law (lesson #5, generalized after FIVE fatalings — Auth, Accounts, Mail, Lock, and Serve all bled): inside ANY lib namespace, EVERY cross-namespace call must be fully qualified — `\Router\refuse()`, `\Store\read()`, `\Cache\html()`, `\Site\load()`. Unqualified `Store\read()` inside `namespace Serve` resolves to `Serve\Store\read()` — fatal. The trap fires when WRITING NEW FILES, not when editing old ones (old files already carry the backslashes), so the check belongs at file-creation time. Before the first smoke test of a new lib file, run:
  `grep -nE '(^|[^\\\\])(Store|Cache|Site|Auth|Lock|Accounts|Mail|Router|Serve)\\\\[a-z]' api/<newfile>.php`
  (unqualified cross-namespace refs; comment hits are noise). The `[schmarrn-500]` error-log line in index.php's catch makes survivors visible.
- Namespace-crossing lesson #6, the builtin-shadow edition: naming a lib function after a PHP builtin (`Store\rename()`) hijacks the builtin for the ENTIRE namespace — `write()`'s plain `rename()` call silently became `Store\rename($tmp, $path)` (ArgumentCountError). Never define namespace functions named like builtins; qualify builtin calls (`\rename()`) when a shadow exists.
- `pkill -f` self-match: a `pkill -f 'php -S 127.0.0.1:8081'` issued from a shell whose OWN command line contains that string kills the calling shell itself — everything chained after it silently never runs (a commit+push vanished exactly this way). The `[p]hp` bracket trick only protects when the string appears NOWHERE else in the same compound command — starting the server and pkill-ing it in ONE shell call defeats the trick (the start command itself contains the literal string). Law: start test servers and kill them in SEPARATE tool calls, or kill by saved PID (`echo $! > /tmp/opencode/pid`).
- `npm run dev` now starts BOTH servers via `concurrently` (api = php -S :8080, app = vite); split scripts `dev:app` / `dev:api` remain for solo runs.
- Kit `alias: { $lib }` was REMOVED from svelte.config: `$lib` TS/JS imports resolve via Kit's built-in alias, but CSS imports evade it — CSS is imported relatively (`../lib/app.css`). Re-tested if Kit ≥ 2.22 changes.
- SvelteKit project root = 08_Schmarrn repo root (package.json at root, sources under `src/`) — NOT a nested dashboard folder; `.svelte-kit` and `node_modules` sit at root. `api/` stays PHP-only.
- SPA fallback of adapter-static: name it something other than `index.html` later (e.g. `dashboard.html`) — adapter warns when it overwrites the prerendered root.
- opcache staleness on config writes: `Site\save()` writes `site.local.php`, but a request landing within `opcache.revalidate_freq` (default 2s) — or on hosts running `validate_timestamps=0` (common shared-host "optimization") — reads the PREVIOUS bytecode: theme switches appear to do nothing. Law: every lib function that writes a PHP config file must `opcache_invalidate(<file>, true)` right after the write (guarded by `function_exists`). Verified by instant PUT→GET theme flips on 8081.
- The HTML cache stores the BODY only; the theme wrap happens at SERVE time (`Serve\page()` fresh path wraps with `themeTemplate`+`fill`). Never echo cached HTML raw — a raw echo freezes the page in whatever theme/style was active at publish time.
- `git add -A` is forbidden: it sweeps user content (src/content/) into commits. Law: add explicit paths only, and READ the `git status --short` output before committing — untracked/added files under src/content/ are a red-flag stop, not a shrug. src/content/ is now gitignored (install-local data), which closes the hole mechanically; the discipline closes the rest.
