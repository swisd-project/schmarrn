<!--
1. Relative path: MCP/sidequest.md
2. Description: Side quest todos for the main CMS — small user-visible gaps, gathered until someone saddles up.
3. Expects: Nothing.
4. Provides: The todo list. Marking law: BACKLOG.md `[x]` / `[~]` / `[o](remark)`.
5. SPDX-License-Identifier: MPL-2.0
6. Copyright (c) 2026 Homahuki GmbH
-->

Side quests (main CMS)
---

[x] Create a default landing page — DONE 2026-11-07: the blanko seed (below) — the front door never opens onto a 404.
[x] 404 page of `atelier` only covers half the DOM. — DONE 2026-11-07: `.sm-notfound` owns the stage in atelier.css (full-height, centered, huge); bakery got the matching dress.
[x] Template choice should happen in the admin page with preview. Every template needs a blanko hello-world page. — DONE 2026-11-07: themes ship `blanko.md` (bakery + atelier do; **catflash's own blanko.md is the user's Vitrine line** — one file away), `GET /bridge/preview/<theme>/<type>/<slug>` (admin, the shell door renders it — all three doors' law by construction), and the Templates tab's per-template "Preview ↗".
[x] Deleting a template needs a warning (confirm dialog). — DONE 2026-11-07: the confirm names the fallback law before the knife.
[x] There must always be at least one template — the last one cannot be deleted. — DONE 2026-11-07: the lid lives server-side (`Wares\removeTemplate` refuses 409), the `×` greys out when one template remains.
[x] Rework the Templates & Plugins page — when a ware is active/installed, its Vitrine shelf entry still shows; the two views need a cleaner relationship. — DONE 2026-11-07: the shelf shows only what you can GET (installable + update); the inventory above shows what you HAVE.
[x] The Updates button announces an available update, but the Updates tab's list is empty — the announcement and the list disagree. — DONE 2026-11-07: the verdict was a string inequality + the panel lived inside the wares block; semver compare + its own tab branch fixed both.
[ ] A sidebar for the public pages — the core provides none today (`navHtml` is the only nav; themes place it themselves, catflash sits it in the header). Decide the home — core placeholder (`{{sidebarHtml}}`, like navHtml) vs theme-carried — then every template carries one. → RESOLVED 2026-11-07, user-designed: the sidebar is the ADMIN drawer — /_-prefixed admin mode on live pages, the drawer gains a retail band (plugin.json/theme.json "drawer" handles via GET /bridge/drawer), the overlay slims to tabs + close X, login lands on /_/<home>. The public-page sidebar ({{sidebarHtml}} vs theme-carried) remains an open fork for a future quest.
[x] Every shipped CMS version carries a blanko page — seed content that can be overwritten, built on the Landing page type, so a fresh install never greets the world with a 404. — DONE 2026-11-07: the blanko seed (`Wares\seedBlanko`) plants the theme's `blanko.md` (Landing-shaped) as the overwritable `pages/index` — setup and activation-on-empty only; never surprise-plants a dressed site.
[x] Page type Landing: if no index page exists, it creates an index page and prefills "index" in the slug modal. — DONE 2026-11-07: picking Landing with no index page preaches `index` into the slug field.
[x] The index page ships automatically as hello-world (the blanko seed). — DONE 2026-11-07: the seed's slug is `index`; the login lands on `/_/index` — the admin meets their blanko front door first.
[x] Remove the Plugins card from the "Templates & Plugins" tab (2026-11-06) — DONE 2026-11-07: **Plugins** and **Templates** are sovereign tabs now (drawer items follow).
