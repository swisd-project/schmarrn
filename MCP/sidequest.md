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

[ ] Create a default landing page — until then, the public face is just the 404 page, since nothing else is there.
[ ] 404 page of `atelier` only covers half the DOM.
[ ] Template choice should happen in the admin page with preview. Every template needs a blanko hello-world page.
[ ] Deleting a template needs a warning (confirm dialog).
[ ] There must always be at least one template — the last one cannot be deleted.
[ ] Rework the Templates & Plugins page — when a ware is active/installed, its Vitrine shelf entry still shows (e.g. catflash after activating); the two views need a cleaner relationship.
[ ] The Updates button announces an available update, but the Updates tab's list is empty — the announcement and the list disagree.
[ ] A sidebar for the public pages — the core provides none today (`navHtml` is the only nav; themes place it themselves, catflash sits it in the header). Decide the home — core placeholder (`{{sidebarHtml}}`, like navHtml) vs theme-carried — then every template carries one. → RESOLVED 2026-11-07, user-designed: the sidebar is the ADMIN drawer — /_-prefixed admin mode on live pages, the drawer gains a retail band (plugin.json/theme.json "drawer" handles via GET /bridge/drawer), the overlay slims to tabs + close X, login lands on /_/<home>. The public-page sidebar ({{sidebarHtml}} vs theme-carried) remains an open fork for a future quest.
[ ] Every shipped CMS version carries a blanko page — seed content that can be overwritten, built on the Landing page type, so a fresh install never greets the world with a 404.
[ ] Page type Landing: if no index page exists, it creates an index page and prefills "index" in the slug modal.
[ ] The index page ships automatically as hello-world (the blanko seed).
[ ] Remove the Plugins card from the "Templates & Plugins" tab (2026-11-06) — with the Updates tab carrying all update verbs, the plugin inventory needs its own home.
