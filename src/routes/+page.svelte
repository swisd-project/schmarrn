<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen. p4 chrome: no sidebar;
  // a burger opens the full-screen overlay (Contents / Settings / Plugins & Templates / Users).
  import { session, login, logout, pageCount, searchPages, createPage, deletePage, renamePage, listAccounts, addAccount, removeAccount, inviteAccount, setupStatus, runSetup, getSiteSettings, saveSiteSettings, setHomePage, listAllMedia, uploadSiteAsset, listPlugins, setPlugins, deletePlugin, savePluginSettings, fetchPluginPanel, pluginPanelAction, listTemplates, activateTemplate, deleteTemplate, coreStatus, uploadCorePacket, runCoreUpdate, listPagetypes, listWares, installWare, getDraftRaw, putDraftRaw, publishDraft, githubStatus, githubConnect, githubDisconnect, githubTest, githubInspect, githubInstall, mailStatus, mailTest, mailConnect, mailDisconnect, resetRequest, resetConfirm, type PageRef, type PluginRef, type TemplateRef, type PagetypeRef, type WareRef, type ThemeHandle, type GithubLook, type PluginPanel, type MailStatus } from '$lib/api';
  import { adminCloseTarget } from '$lib/back';
  import { base } from '$app/paths';
  import { schemaFor, withDraftFlag } from '$lib/schema';
  import { splitFrontmatter } from '$lib/blocks';
  import { renderMdms } from '$lib/render';
  import { parseMdms } from '@swisd-project/mdms';
  import type { PageIndex, AccountRef } from '$lib/types';
  import type { SiteMediaRef } from '$lib/api';

  let authenticated = $state<boolean | null>(null);
  let user = $state('');
  let role = $state('');
  let password = $state('');
  let error = $state('');
  let busy = $state(false);
  let index = $state<PageIndex | null>(null);

  // establish session state once — and check whether the bakery needs founding
  let setupNeeded = $state(false);
  let setupThemes = $state<readonly string[]>([]);
  $effect(() => {
    session()
      .then(async (s) => {
        authenticated = s.authenticated;
        user = s.user;
        role = s.role;
        if (s.authenticated) {
          const params = new URLSearchParams(window.location.search);
          // The overlay opens ONLY when the admin drawer sent you here (it
          // carries ?back=&tab=). A direct /_ visit while authed must NOT throw
          // you into the overlay — land on the admin-mode home page (visual
          // editor, drawer closed). /_ unauth is the login door.
          const drawerIntent = params.has('back') || params.has('tab');
          if (drawerIntent) {
            const wanted = params.get('tab') ?? '';
            const allowed: readonly Tab[] = s.role === 'admin'
              ? ['contents', 'settings', 'plugins', 'templates', 'updates', 'users']
              : ['contents'];
            if ((allowed as readonly string[]).includes(wanted)) showTab(wanted as Tab);
            openMenu();
            if (s.role === 'admin') {
              void loadCore();
              void loadWares();
            }
            return;
          }
          // Direct authed /_ → admin landing page (stay in admin mode). No pages
          // yet → keep the dashboard overlay open so there's somewhere to work.
          if (!import.meta.env.DEV) {
            const landing = await landingSlug();
            if (landing !== null) {
              window.location.href = `/_/${landing}`;
              return;
            }
          }
          openMenu();
          if (s.role === 'admin') {
            void loadCore();
            void loadWares();
          }
        }
      })
      .catch(() => (authenticated = false));
    setupStatus()
      .then((st) => {
        setupNeeded = st.needed;
        setupThemes = st.themes;
      })
      .catch(() => {});
  });

  // ——— first-visit setup wizard ———
  let setupUser = $state('');
  let setupPassword = $state('');
  let setupSiteName = $state('');
  let setupClaim = $state('');
  let setupTheme = $state('bakery');
  let setupBusy = $state(false);
  let setupError = $state('');

  async function foundBakery(event: SubmitEvent) {
    event.preventDefault();
    setupBusy = true;
    setupError = '';
    try {
      const s = await runSetup({
        user: setupUser.trim(),
        password: setupPassword,
        siteName: setupSiteName.trim(),
        claim: setupClaim.trim(),
        theme: setupTheme
      });
      setupNeeded = false;
      authenticated = true;
      user = s.user;
      role = s.role;
      setupPassword = '';
      // Fresh install: the bakery is founded, the baker lands on their shop —
      // the home page in admin mode (2026-11-07 law), editor and drawer awake.
      if (!import.meta.env.DEV) {
        const landing = await landingSlug();
        window.location.href = landing !== null ? `/_/${landing}` : '/_/';
        return;
      }
    } catch (e) {
      setupError = e instanceof Error ? e.message : String(e);
    } finally {
      setupBusy = false;
    }
  }

  // ——— brand settings (admin) ———
  let siteSettings = $state<{ name: string; claim: string; logo: string; theme: string; home: string; style: string; font: string; handles: readonly ThemeHandle[] } | null>(null);
  let siteBusy = $state(false);
  let siteError = $state('');
  let siteNote = $state('');

  // ——— the theme handles: the active template's declared CSS variables, as the
  // ——— pickers see them. The template's stylesheet keeps the layout; these
  // ——— knobs only turn the colors it declared. ———
  let handleValues = $state<Record<string, string>>({});
  let styleBusy = $state(false);
  let styleError = $state('');
  let styleNote = $state('');

  async function loadSiteSettings() {
    if (siteSettings !== null) return;
    try {
      const loaded = await getSiteSettings();
      siteSettings = { ...loaded };
      handleValues = Object.fromEntries(loaded.handles.map((h) => [h.var, h.value]));
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    }
  }

  async function saveSiteNow(event: SubmitEvent) {
    event.preventDefault();
    if (siteSettings === null) return;
    siteBusy = true;
    siteError = '';
    siteNote = '';
    try {
      // the home's crown lives in the page settings modal now (2026-11-09,
      // user order) — the settings form no longer owns it, and a stale
      // snapshot here must never uncrown the page the modal crowned.
      // style went the same road (2026-11-09, third telling): no settings
      // surface speaks for style any more.
      await saveSiteSettings({
        name: siteSettings.name,
        claim: siteSettings.claim,
        logo: siteSettings.logo,
        font: siteSettings.font
      });
      siteNote = 'Saved. The public face updates on the next serve.';
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** The handles save: every declared handle rides along — its value, or an
   *  EMPTY value when it sits on the template's default (that is the reset).
   *  The server patches the active theme's stored overrides with it, so a
   *  handle nobody touched keeps its standing. The brand form never sends
   *  handles and the handle form never sends the brand: each writer owns its
   *  keys (the crown's law, same shape). */
  /** What a color CHANGES, not what it's called (2026-11-12, user order):
   *  "Crust — panels & header" speaks as "panels & header"; a label without
   *  the dash keeps its whole words. */
  function shortHandleLabel(label: string): string {
    const dash = label.indexOf('—') !== -1 ? '—' : label.indexOf('–') !== -1 ? '–' : ' — ';
    const at = label.indexOf(dash);
    const short = at !== -1 ? label.slice(at + dash.length).trim() : label.trim();
    return short || label;
  }

  /** The font upload: same pantry as the logo — the stored URL lands in the
   *  font field; SAVE puts it on the public face. */
  async function onFontPick(event: Event) {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || siteSettings === null) return;
    siteBusy = true;
    siteError = '';
    siteNote = '';
    try {
      const stored = await uploadSiteAsset(file);
      siteSettings = { ...siteSettings, font: stored.url };
      siteNote = `Font uploaded as ${stored.name} — save to put it on the public face.`;
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  async function saveHandlesNow() {
    if (siteSettings === null) return;
    styleBusy = true;
    styleError = '';
    styleNote = '';
    try {
      const handles: Record<string, string> = {};
      for (const h of siteSettings.handles) {
        const value = handleValues[h.var] ?? h.default;
        handles[h.var] = value === h.default ? '' : value;
      }
      // the card's save speaks for the whole card (2026-11-12, user order):
      // the handles AND the font — the font field lives here now.
      await saveSiteSettings({ handles, font: siteSettings.font });
      styleNote = 'Saved. The public face updates on the next serve.';
    } catch (e) {
      styleError = e instanceof Error ? e.message : String(e);
    } finally {
      styleBusy = false;
    }
  }

  /** Back to the template's default: the handle leaves the map, the theme's
   *  own :root speaks again. */
  function resetHandle(name: string, fallback: string) {
    handleValues = { ...handleValues, [name]: fallback };
  }

  /** Logo upload: straight into site assets, the URL lands in the form (save still explicit). */
  /** The logo upload: the picture IS the field — pick, upload, and it lands
   *  on the public face immediately (saved at once; the site form's Save
   *  button no longer carries the logo). */
  async function onLogoPick(event: Event) {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || siteSettings === null) return;
    siteBusy = true;
    siteError = '';
    siteNote = '';
    try {
      const stored = await uploadSiteAsset(file);
      await saveSiteSettings({ logo: stored.url });
      siteSettings = { ...siteSettings, logo: stored.url };
      siteNote = `Logo uploaded as ${stored.name} — on the public face at once.`;
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** The logo lets go — cleared and saved in one stroke. */
  async function clearLogoNow() {
    if (siteSettings === null || siteBusy) return;
    siteBusy = true;
    siteError = '';
    siteNote = '';
    try {
      await saveSiteSettings({ logo: '' });
      siteSettings = { ...siteSettings, logo: '' };
      siteNote = 'The logo let go.';
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** The landing page after login (2026-11-07 law): the site's home in admin
   *  mode. Admins read the home slug off the site settings; editors take the
   *  first published page off the tree; no pages at all → the dashboard. */
  async function landingSlug(): Promise<string | null> {
    try {
      const s = await getSiteSettings();
      if (s.home !== '') return s.home;
    } catch { /* editors: the sugar safe stays shut */ }
    try {
      for (const p of (await pageCount())['pages'] ?? []) {
        if (!p.draft) return p.slug;
      }
    } catch { /* no tree yet — the dashboard catches them */ }
    return null;
  }

  async function signIn(event: SubmitEvent) {
    event.preventDefault();
    busy = true;
    error = '';
    try {
      const s = await login(user, password);
      authenticated = true;
      role = s.role;
      password = '';
      // The law (2026-11-07): login at /_, then the LANDING PAGE comes back up
      // in admin mode — /_/<home> — the editor and the drawer already awake.
      // Dev stays in the SPA (the vite origin's / is the dashboard itself).
      if (!import.meta.env.DEV) {
        const landing = await landingSlug();
        window.location.href = landing !== null ? `/_/${landing}` : '/_/';
        return;
      }
    } catch (e) {
      error = e instanceof Error ? e.message : 'Login failed';
    } finally {
      busy = false;
    }
  }

  async function signOut() {
    await logout();
    authenticated = false;
    menuOpen = false;
  }

  // load the page tree the moment we're in
  $effect(() => {
    if (authenticated === true && index === null) {
      pageCount().then((i) => (index = i)).catch((e) => (error = e.message));
    }
  });

  function refreshIndex() {
    pageCount()
      .then((i) => (index = i))
      .catch((e) => (error = e.message));
    if (searchQ.trim() !== '') onSearchInput(); // the needle re-runs — hits re-read
  }

  // ——— the desk's search + tag filter (2026-11-09, user order): the type
  // headlines retired — a needle and a tag do the finding. Text rides the
  // core's byte search (GET /bridge/search: authed, drafts included, no
  // Markdown parsing); tags ride the page index; the tag filter is local. ———
  let searchQ = $state('');
  let tagFilter = $state('');
  let searchBusy = $state(false);
  let searchHits = $state<{ q: string; types: PageIndex } | null>(null); // keyed by the query it answered
  let searchTimer: ReturnType<typeof setTimeout> | undefined;

  const activeQ = $derived(searchQ.trim());
  const allTags = $derived.by(() => {
    const tags = new Set<string>();
    for (const pages of Object.values(index ?? {})) {
      for (const p of pages) for (const t of p.tags ?? []) tags.add(t);
    }
    return [...tags].sort((a, b) => a.localeCompare(b));
  });
  const deskRows = $derived.by(() => {
    // the needle's own answers while they are current; the stale list holds
    // the fort while a newer one is in flight — no flicker, no blank flash
    const source: PageIndex =
      activeQ === '' ? (index ?? {}) : searchHits?.q === activeQ ? searchHits.types : (searchHits?.types ?? {});
    const rows: { key: string; type: string; page: PageRef }[] = [];
    for (const [type, pages] of Object.entries(source)) {
      for (const p of pages) {
        if (tagFilter !== '' && !(p.tags ?? []).includes(tagFilter)) continue;
        rows.push({ key: `${type}/${p.slug}`, type, page: p });
      }
    }
    return rows;
  });

  function onSearchInput() {
    clearTimeout(searchTimer);
    const q = searchQ.trim();
    if (q === '') {
      searchHits = null;
      searchBusy = false;
      return;
    }
    searchBusy = true;
    searchTimer = setTimeout(() => {
      searchPages(q)
        .then((types) => {
          if (searchQ.trim() === q) searchHits = { q, types }; // a stale answer never lands
        })
        .catch((e) => (deskError = e instanceof Error ? e.message : String(e)))
        .finally(() => {
          if (searchQ.trim() === q) searchBusy = false;
        });
    }, 250);
  }

  function select(type: string, slug: string) {
    // The canvas retired — editing lives on the LIVE page now (the injected editor).
    // The dashboard is management-only: picking a page walks you onto it.
    window.location.href = type === 'pages' ? `/${slug}` : `/${type}/${slug}`;
  }

  // ——— the raw desk (Contents): the md file, open on the sheet ———
  // The dashboard's own writing desk: the filetree picks, the sheet edits the
  // DRAFT (getDraftRaw/putDraftRaw), and the hash guard refuses to trample a
  // concurrent editor. Save only exists when the text actually changed.
  let sel = $state<null | { type: string; slug: string }>(null);
  let raw = $state('');
  let baseline = $state('');
  let baseHash = $state('');
  let deskBusy = $state(false);
  let deskError = $state('');
  let deskNote = $state('');
  let mediaModal = $state(false);
  // The sheet locks by law (2026-11-07): raw markdown is surgery — it needs an
  // explicit Unlock, and the lock returns the moment the page is saved. Every
  // freshly opened page arrives locked; switching pages locks too. The lock
  // gates NEW edits only — pending changes stay saveable (and dirty stays
  // truthful) even after a manual re-lock.
  let unlocked = $state(false);
  // ——— the surgery flag (2026-11-12, user order): while the raw sheet is
  // unlocked, the flag rides sessionStorage to the live page — the visual
  // editor there shows the disabled cursor on its content edit buttons and
  // stays deaf. Cleared on Lock/save (unlocked=false) and on the desk's next
  // boot; it survives the ride to /_page on purpose — the surgery is still open.
  $effect(() => {
    if (unlocked && sel !== null) {
      sessionStorage.setItem('sm-raw-surgery', `${sel.type}/${sel.slug}`);
    } else {
      sessionStorage.removeItem('sm-raw-surgery');
    }
  });
  const dirty = $derived(sel !== null && raw !== baseline);
  const selPath = $derived(sel === null ? '' : `${sel.type}/${sel.slug}/index.md`);

  async function openRaw(type: string, slug: string) {
    if (sel?.type === type && sel.slug === slug) return;
    if (dirty) {
      askConfirm('Unsaved changes', `Unsaved changes on ${selPath} — leave without saving?`, () => openRawGo(type, slug));
      return;
    }
    openRawGo(type, slug);
  }

  async function openRawGo(type: string, slug: string) {
    deskBusy = true;
    deskError = '';
    deskNote = '';
    unlocked = false; // every page arrives locked — surgery needs its own yes
    try {
      const d = await getDraftRaw(type, slug);
      sel = { type, slug };
      raw = d.raw;
      baseline = d.raw;
      baseHash = d.hash;
    } catch (e) {
      deskError = e instanceof Error ? e.message : String(e);
    } finally {
      deskBusy = false;
    }
  }

  async function saveRaw() {
    if (!dirty || deskBusy || sel === null) return;
    deskBusy = true;
    deskError = '';
    deskNote = '';
    try {
      baseHash = await putDraftRaw(sel.type, sel.slug, raw, baseHash);
      baseline = raw;
      unlocked = false; // the lock returns when the page is saved — the law
      deskNote = 'Draft saved — publish from ✑ when it is ready for the public face.';
      index = await pageCount(); // the tree's draft/edits badges re-read
      if (searchQ.trim() !== '') onSearchInput(); // the needle re-runs over the fresh bytes
    } catch (e) {
      deskError = e instanceof Error ? e.message : String(e);
    } finally {
      deskBusy = false;
    }
  }

  function deskKeydown(event: KeyboardEvent) {
    // Ctrl/Cmd+S saves the draft — the one reflex a text desk owes its writer.
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
      event.preventDefault();
      void saveRaw();
    }
  }

  // ——— the burger overlay: Pages / Site / Accounts / Media / Plugins ———
  type Tab = 'contents' | 'settings' | 'plugins' | 'templates' | 'updates' | 'users';
  let menuOpen = $state(false);
  let menuOpenedAt = 0;
  let allowClose = false; // blocks spurious untrusted closes (drawer-intent mount artifacts)
  let userClosing = false; // ONLY the X may trigger drawer-intent return
  let tab = $state<Tab>('contents');

  /** The dialog takes focus when it mounts (auto-open leaves nothing focused —
   *  and without it, keyboard flow starts nowhere). Pure desktop manners. */
  const dialogFocus = (node: HTMLElement) => node.focus();

  function openMenu() {
    menuOpen = true;
    menuOpenedAt = Date.now();
    allowClose = false;
    userClosing = false;
    setTimeout(() => (allowClose = true), 1000);
    if (role === 'admin') void loadSiteSettings(); // warm the site form while the overlay opens
  }

  async function closeMenu(e?: Event) {
    // Drawer-intent (/_?back=&tab=): ONLY an explicit X (userClosing)
    // may return to the carried admin page. Every spurious/programmatic close
    // is ignored — this is what made the overlay "vanish immediately".
    const intent = new URLSearchParams(window.location.search);
    const drawerIntent = intent.has('back') || intent.has('tab');
    if (drawerIntent && !userClosing) return;
    userClosing = false;
    if (!menuOpen && !drawerIntent) return;
    menuOpen = false;
    if (import.meta.env.DEV) return;
    const carried = adminCloseTarget(window.location.search);
    if (carried !== null && (carried === '/_' || carried.startsWith('/_/'))) {
      window.location.href = carried;
      return;
    }
    const landing = await landingSlug();
    if (landing !== null) {
      window.location.href = `/_/${landing}`;
      return;
    }
  }

  function showTab(next: Tab) {
    tab = next;
    // The drawer-intent URL carries the tab — keep it TRUE as the user walks
    // the tabs (a refresh or any URL reader must never resurrect a tab the
    // user already left). A plain /_ visit (no back, no tab) stays untouched:
    // its refresh law is the admin landing page, not the overlay.
    const params = new URLSearchParams(window.location.search);
    if (params.has('back') || params.has('tab')) {
      params.set('tab', next);
      window.history.replaceState(null, '', `${window.location.pathname}?${params.toString()}`);
    }
    if (next === 'contents') void loadAllMedia(); // the media pane pays for itself (lazy)
    if (next === 'users') void loadAccounts();
    if (next === 'plugins') {
      void loadPlugins();
      void loadWares(); // the Vitrine shelf carries the update verdicts
    }
    if (next === 'templates') {
      void loadTemplates();
      void loadWares();
    }
    if (next === 'updates') {
      void loadCore();
      void loadWares(); // the Vitrine shelf carries every installed-vs-registry verdict
    }
    if (next === 'settings' && core === null) void loadCore(); // the attribution card wears the release's news
    if (next === 'settings') {
      void loadGithub(); // the integrations card asks the doors who stand there
      void loadMail();
    }
  }

  // The dashboard's drawer is GONE (2026-11-07, user law): the overlay carries
  // the tabs and the close X itself — a second door here only buried the X
  // under a z-45 panel. The live page's drawer (edit.ts) is the one true door.

  // ——— plugins (admin, lazy, part of "Plugins & Templates") ———
  let plugins = $state<readonly PluginRef[]>([]);
  let pluginsBusy = $state(false);
  let pluginsError = $state('');
  let pluginsNote = $state('');

  async function loadPlugins() {
    if (pluginsBusy) return;
    pluginsBusy = true;
    pluginsError = '';
    try {
      plugins = await listPlugins();
    } catch (e) {
      pluginsError = e instanceof Error ? e.message : String(e);
    } finally {
      pluginsBusy = false;
    }
  }

  /** Delete a plugin — the folder, the lock line and the switch leave together.
   *  No plugin is undeletable: the preinstalled default (hello) goes like any
   *  other. The house-default law is a TEMPLATE law — a plugin is decoration. */
  function deletePluginNow(plugin: PluginRef) {
    askConfirm('Delete plugin', `Delete the ${plugin.title} plugin? Its placeholders and routes leave with it.`, () => deletePluginGo(plugin));
  }

  async function deletePluginGo(plugin: PluginRef) {
    if (pluginsBusy) return;
    pluginsBusy = true;
    pluginsError = '';
    pluginsNote = '';
    try {
      await deletePlugin(plugin.name);
      plugins = await listPlugins();
      pluginsNote = `${plugin.title} left the shelf — deleted.`;
    } catch (e) {
      pluginsError = e instanceof Error ? e.message : String(e);
    } finally {
      pluginsBusy = false;
    }
  }

  async function togglePlugin(plugin: PluginRef) {
    if (pluginsBusy) return;
    pluginsBusy = true;
    pluginsError = '';
    pluginsNote = '';
    try {
      // The map is rebuilt from the FRESH list — never from stale local state.
      const fresh = await listPlugins();
      const enabled: Record<string, boolean> = {};
      for (const p of fresh) enabled[p.name] = p.name === plugin.name ? !p.enabled : p.enabled;
      // The enable law, stated client-side before the server ever answers:
      // enabling a plugin while its dependency sleeps is refused with the diff.
      if (enabled[plugin.name] === true && (plugin.requires ?? []).length > 0) {
        const sleeping = (plugin.requires ?? []).filter((dep) => !fresh.some((p) => p.name === dep && p.enabled));
        if (sleeping.length > 0) {
          pluginsError = `${plugin.title} waits for ${sleeping.join(', ')} to be enabled first — a plugin does not run on a sleeping dependency.`;
          return;
        }
      }
      await setPlugins(enabled);
      plugins = await listPlugins();
      pluginsNote = `${plugin.title} ${plugins.find((p) => p.name === plugin.name)?.enabled ? 'enabled' : 'disabled'}.`;
    } catch (e) {
      pluginsError = e instanceof Error ? e.message : String(e);
    } finally {
      pluginsBusy = false;
    }
  }

  // ——— the Vitrine (public showcase wares, admin, lazy) ———
  let vitrine = $state<readonly WareRef[]>([]);
  let vitrineBusy = $state(false);
  let vitrineError = $state('');
  let vitrineNote = $state('');
  let vitrineDark = $state(''); // the registry's own unreachable note, empty when the shelf is lit

  async function loadWares(force = false) {
    if (vitrineBusy && !force) return;
    vitrineBusy = true;
    vitrineError = '';
    vitrineNote = '';
    try {
      const shelf = await listWares();
      vitrine = shelf.wares;
      vitrineDark = shelf.error ?? '';
    } catch (e) {
      vitrineError = e instanceof Error ? e.message : String(e);
    } finally {
      vitrineBusy = false;
    }
  }

  async function installWareByName(ware: WareRef) {
    if (vitrineBusy) return;
    vitrineBusy = true;
    vitrineError = '';
    vitrineNote = '';
    try {
      vitrineNote = await installOne(ware);
    } catch (e) {
      vitrineError = e instanceof Error ? e.message : String(e);
    } finally {
      vitrineBusy = false;
    }
  }

  /** One install-or-update ride + the list refreshes it owes. Returns the note. */
  async function installOne(ware: WareRef): Promise<string> {
    const done = await installWare(ware.name);
    if (ware.kind === 'plugin') {
      plugins = await listPlugins();
    } else if (ware.kind === 'template') {
      templates = await listTemplates();
    }
    await loadWares(true); // the shelf re-reads — an updated face stops announcing
    return `${ware.title} → v${done.version}`;
  }

  // ——— the Updates tab (admin): what needs updating, and the one-knife button ———
  let updatesBusy = $state(false);
  let updatesError = $state('');
  let updatesNote = $state('');

  /** ONE ware updated from the Updates tab — the same ride, its own notes. */
  async function updateWareNow(ware: WareRef) {
    if (updatesBusy) return;
    updatesBusy = true;
    updatesError = '';
    updatesNote = '';
    try {
      updatesNote = await installOne(ware);
    } catch (e) {
      updatesError = e instanceof Error ? e.message : String(e);
    } finally {
      updatesBusy = false;
    }
  }

  /**
   * UPDATE ALL — the house order: the core rides first when GitHub announces a
   * newer floor, then the templates, then the plugins. Every stop re-reads its
   * shelf; the first break answers and the rest stay for the next round.
   */
  async function updateAllNow() {
    if (updatesBusy) return;
    updatesBusy = true;
    updatesError = '';
    updatesNote = '';
    const done: string[] = [];
    let coreRide: Awaited<ReturnType<typeof runCoreUpdate>> | null = null; // captured before the ride flips the verdict
    try {
      if (coreUpdateAvailable && core !== null) {
        coreRide = await runCoreUpdate('hub');
        await loadCore();
        await loadWares(true); // the registry's host floor may have moved
        done.push(`core ${coreRide.from} → ${coreRide.to}`);
      }
      for (const name of templateUpdates.map((w) => w.name)) {
        const fresh = vitrine.find((w) => w.kind === 'template' && w.name === name && w.state === 'update');
        if (fresh) done.push(await installOne(fresh));
      }
      for (const name of pluginUpdates.map((w) => w.name)) {
        const fresh = vitrine.find((w) => w.kind === 'plugin' && w.name === name && w.state === 'update');
        if (fresh) done.push(await installOne(fresh));
      }
      updatesNote = (done.length > 0 ? done.join(' · ') : 'Nothing needed the knife.') + '.';
      if (coreRide !== null && !coreRide.dashboard) updatesNote += ' The dashboard did NOT move with the core packet — rebuild it (npm run build) or ride a release that carries its asset zip.';
      else if (coreRide !== null) updatesNote += ' Reload the page — the dashboard you are wearing predates the core move.';
    } catch (e) {
      updatesError = e instanceof Error ? e.message : String(e);
      if (done.length > 0) updatesNote = `Done before the break: ${done.join(' · ')}.`;
    } finally {
      updatesBusy = false;
    }
  }

  // ——— media tab (lazy) ———
  let overlayMedia = $state<readonly SiteMediaRef[]>([]);
  let mediaBusy = $state(false);
  let mediaError = $state('');
  let mediaNote = $state('');

  async function loadAllMedia() {
    if (mediaBusy) return;
    mediaBusy = true;
    mediaError = '';
    try {
      overlayMedia = await listAllMedia();
    } catch (e) {
      mediaError = e instanceof Error ? e.message : String(e);
    } finally {
      mediaBusy = false;
    }
  }

  // ——— templates (theme-contract wares, admin, lazy, part of "Plugins & Templates") ———
  let templates = $state<readonly TemplateRef[]>([]);
  let templatesBusy = $state(false);
  let templatesError = $state('');
  let templatesNote = $state('');

  async function loadTemplates() {
    if (templatesBusy) return;
    templatesBusy = true;
    templatesError = '';
    try {
      templates = await listTemplates();
    } catch (e) {
      templatesError = e instanceof Error ? e.message : String(e);
    } finally {
      templatesBusy = false;
    }
  }

  /** The shelf's face for one installed ware — undefined when the registry is dark. */
  function vitrineWare(kind: WareRef['kind'], name: string): WareRef | undefined {
    return vitrine.find((w) => w.kind === kind && w.name === name);
  }

  async function activateTemplateNow(template: TemplateRef) {
    if (templatesBusy) return;
    templatesBusy = true;
    templatesError = '';
    templatesNote = '';
    try {
      await activateTemplate(template.name);
      templates = await listTemplates();
      // The settings form caches its copy — the theme switch reaches it here.
      if (siteSettings !== null) siteSettings = { ...siteSettings, theme: template.name };
      templatesNote = `${template.title} is the live theme now.`;
    } catch (e) {
      templatesError = e instanceof Error ? e.message : String(e);
    } finally {
      templatesBusy = false;
    }
  }

  function deleteTemplateNow(template: TemplateRef) {
    askConfirm('Delete template', `Delete the ${template.title} template? Pages keep working — the site falls back to a house theme until another is activated.`, () => deleteTemplateGo(template));
  }

  async function deleteTemplateGo(template: TemplateRef) {
    if (templatesBusy) return;
    templatesBusy = true;
    templatesError = '';
    templatesNote = '';
    try {
      await deleteTemplate(template.name);
      templates = await listTemplates();
      await loadWares(true); // the shelf re-reads: a deleted update face becomes installable again
      templatesNote = `${template.title} left the shelf — deleted.`;
    } catch (e) {
      templatesError = e instanceof Error ? e.message : String(e);
    } finally {
      templatesBusy = false;
    }
  }

  // ——— the core's self-update (admin, part of "Plugins & Templates") ———
  let core = $state<{ current: string; latest: string | null; error: string; localVersion: string | null; updatable: boolean; dashboard: string | null; releaseName: string | null; published: string | null; contributors: readonly { login: string; url: string }[] } | null>(null);
  // ——— the GitHub door (Integrations card) ———
  let github = $state<{ connected: boolean; login: string | null } | null>(null);
  let githubToken = $state('');
  let githubRepo = $state('');
  let githubLook = $state<GithubLook | null>(null);
  let githubBusy = $state(false);
  let githubError = $state('');
  let githubNote = $state('');
  let githubModal = $state(false);
  let githubTested = $state<{ ok: boolean; login: string | null; error: string } | null>(null);

  // ——— the house confirm (2026-11-12, user order): no system popups, ever —
  // every "are you sure" is a modal wearing the house pattern.
  let confirmModal = $state<{ title: string; message: string; run: () => void } | null>(null);

  function askConfirm(title: string, message: string, run: () => void): void {
    confirmModal = { title, message, run };
  }

  function confirmNow(): void {
    const run = confirmModal?.run;
    confirmModal = null;
    run?.();
  }

  // ——— the SMTP oven (Integrations card, second square) ———
  let mail = $state<{ configured: boolean; host: string; port: number; secure: string; fromEmail: string; fromName: string } | null>(null);
  let mailModal = $state(false);
  let mailBusy = $state(false);
  let mailError = $state('');
  let mailNote = $state('');
  let mailTested = $state<{ ok: boolean; error: string } | null>(null);
  let mailHost = $state('');
  let mailPort = $state(587);
  let mailSecure = $state('tls');
  let mailUser = $state('');
  let mailPass = $state('');
  let mailFromEmail = $state('');
  let mailFromName = $state('Schmarrn');

  async function loadMail() {
    try {
      mail = await mailStatus();
    } catch {
      mail = null; // the card stays quiet when the oven door is out of reach
    }
  }

  function openMailModal() {
    mailModal = true;
    mailError = '';
    mailNote = '';
    mailTested = null;
    const m = mail;
    if (m?.configured) {
      mailHost = m.host;
      mailPort = m.port;
      mailSecure = m.secure;
      mailFromEmail = m.fromEmail;
      mailFromName = m.fromName;
      mailUser = '';
      mailPass = '';
    }
  }

  async function testMailNow() {
    if (mailBusy) return;
    mailBusy = true;
    mailError = '';
    mailNote = '';
    mailTested = null;
    try {
      mailTested = await mailTest({
        host: mailHost.trim(),
        port: Number(mailPort) || 587,
        user: mailUser.trim(),
        pass: mailPass,
        secure: mailSecure,
        fromEmail: mailFromEmail.trim(),
        fromName: mailFromName.trim() || 'Schmarrn'
      });
      if (!mailTested.ok) mailError = mailTested.error;
    } catch (e) {
      mailError = e instanceof Error ? e.message : String(e);
    } finally {
      mailBusy = false;
    }
  }

  async function connectMailNow() {
    if (mailBusy) return;
    mailBusy = true;
    mailError = '';
    mailNote = '';
    try {
      mail = await mailConnect({
        host: mailHost.trim(),
        port: Number(mailPort) || 587,
        user: mailUser.trim(),
        pass: mailPass,
        secure: mailSecure,
        fromEmail: mailFromEmail.trim(),
        fromName: mailFromName.trim() || 'Schmarrn'
      });
      mailPass = ''; // the password does not linger in the browser
      mailTested = null;
      mailModal = false;
      mailNote = 'The oven is plumbed in — invites and password resets can ride.';
    } catch (e) {
      mailError = e instanceof Error ? e.message : String(e);
    } finally {
      mailBusy = false;
    }
  }

  async function disconnectMailNow() {
    if (mailBusy) return;
    mailBusy = true;
    mailError = '';
    mailNote = '';
    try {
      mail = await mailDisconnect();
      mailTested = null;
      mailModal = false;
      mailNote = 'The oven let go — mail features are off until it is plumbed in again.';
    } catch (e) {
      mailError = e instanceof Error ? e.message : String(e);
    } finally {
      mailBusy = false;
    }
  }

  // ——— the forgot-password door (login screen; the backend's reset endpoints
  // had no face — 2026-11-12, user order) ———
  let forgotModal = $state(false);
  let forgotStep = $state(1); // 1 = ask for the token, 2 = token + new password
  let forgotBusy = $state(false);
  let forgotError = $state('');
  let forgotUser = $state('');
  let forgotToken = $state('');
  let forgotPass = $state('');
  let forgotDone = $state(false);
  let forgotNoteFromServer = $state('');

  function openForgot() {
    forgotModal = true;
    forgotStep = 1;
    forgotError = '';
    forgotUser = '';
    forgotToken = '';
    forgotPass = '';
    forgotDone = false;
  }

  async function requestResetNow() {
    if (forgotBusy || forgotUser.trim() === '') return;
    forgotBusy = true;
    forgotError = '';
    try {
      const res = await resetRequest(forgotUser.trim());
      // enumeration-proof: the answer is always the same shape — the step moves on faith
      forgotStep = 2;
      forgotNoteFromServer = res.message;
    } catch (e) {
      forgotError = e instanceof Error ? e.message : String(e);
    } finally {
      forgotBusy = false;
    }
  }

  async function confirmResetNow() {
    if (forgotBusy) return;
    forgotBusy = true;
    forgotError = '';
    try {
      await resetConfirm(forgotUser.trim(), forgotToken.trim(), forgotPass);
      forgotDone = true;
    } catch (e) {
      forgotError = e instanceof Error ? e.message : String(e);
    } finally {
      forgotBusy = false;
    }
  }

  // ——— the foldable sections (2026-11-12, user order) ———
  // The shelf sections fold away by default; the INSTALLED wares stay visible
  // (an enabled plugin and a sleeping one both have to be seen at a glance).
  // A section line carries its triangle at the very right: down = closed,
  // up = open.
  let openSections = $state<Record<string, boolean>>({});

  function toggleSection(id: string): void {
    openSections = { ...openSections, [id]: !openSections[id] };
  }

  async function loadGithub() {
    if (githubBusy) return;
    githubBusy = true;
    try {
      github = await githubStatus();
    } catch (e) {
      githubError = e instanceof Error ? e.message : String(e);
    } finally {
      githubBusy = false;
    }
  }

  async function testGithubNow() {
    if (githubBusy || githubToken.trim() === '') return;
    githubBusy = true;
    githubError = '';
    githubNote = '';
    githubTested = null;
    try {
      githubTested = await githubTest(githubToken.trim());
      if (!githubTested.ok) {
        githubError = githubTested.error;
      }
    } catch (e) {
      githubError = e instanceof Error ? e.message : String(e);
    } finally {
      githubBusy = false;
    }
  }

  async function connectGithubNow() {
    if (githubBusy || githubToken.trim() === '') return;
    githubBusy = true;
    githubError = '';
    githubNote = '';
    try {
      github = await githubConnect(githubToken.trim());
      githubToken = ''; // the token does not linger in the browser
      githubTested = null;
      githubModal = false;
      githubNote = `GitHub connected — ${github.login}.`;
    } catch (e) {
      githubError = e instanceof Error ? e.message : String(e);
    } finally {
      githubBusy = false;
    }
  }

  async function disconnectGithubNow() {
    if (githubBusy) return;
    githubBusy = true;
    githubError = '';
    githubNote = '';
    try {
      github = await githubDisconnect();
      githubTested = null;
      githubModal = false;
      githubNote = 'The account let go — private repos are out of reach again.';
    } catch (e) {
      githubError = e instanceof Error ? e.message : String(e);
    } finally {
      githubBusy = false;
    }
  }

  async function lookGithubNow() {
    if (githubBusy || githubRepo.trim() === '') return;
    githubBusy = true;
    githubError = '';
    githubNote = '';
    try {
      githubLook = await githubInspect(githubRepo.trim());
    } catch (e) {
      githubError = e instanceof Error ? e.message : String(e);
      githubLook = null;
    } finally {
      githubBusy = false;
    }
  }

  async function stageGithubNow(kind: 'plugin' | 'template', path = '') {
    if (githubBusy || githubRepo.trim() === '') return;
    githubBusy = true;
    githubError = '';
    githubNote = '';
    try {
      const res = await githubInstall(githubRepo.trim(), kind, path);
      githubNote = `${res.name} staged from ${res.repo} — disabled. The code does not run until you enable it.`;
      githubLook = null;
      githubRepo = '';
      if (kind === 'plugin') await loadPlugins();
      else await loadTemplates();
    } catch (e) {
      githubError = e instanceof Error ? e.message : String(e);
    } finally {
      githubBusy = false;
    }
  }

  let coreBusy = $state(false);
  let coreError = $state('');
  let coreNote = $state('');

  /** Semver-greater-than over dotted numbers — the verdict compares VERSIONS;
   *  a string inequality made the pill scream "Update available" forever
   *  while the server rightly refused (2026-11-07, seen live on 1.2.5/1.2.3). */
  function versionGreater(a: string, b: string): boolean {
    const pa = a.split('.').map((n) => parseInt(n, 10) || 0);
    const pb = b.split('.').map((n) => parseInt(n, 10) || 0);
    for (let i = 0; i < 3; i++) {
      const x = pa[i] ?? 0;
      const y = pb[i] ?? 0;
      if (x !== y) return x > y;
    }
    return false;
  }

  // ——— the updates verdicts (the SERVER's word — one law, one place: newer
  // floor, or equal floor with a lagging face — the client only displays it) ———
  const templateUpdates = $derived(vitrine.filter((w) => w.kind === 'template' && w.state === 'update' && w.missing.length === 0));
  const pluginUpdates = $derived(vitrine.filter((w) => w.kind === 'plugin' && w.state === 'update' && w.missing.length === 0));
  const coreUpdateAvailable = $derived(core !== null && core.updatable);
  const updatesAvailable = $derived(coreUpdateAvailable || templateUpdates.length > 0 || pluginUpdates.length > 0);
  // the preview's mannequin: the tree's first page wears whichever theme you fit it in
  const previewSlug = $derived((index?.['pages'] ?? [])[0]?.slug ?? '');

  // ——— the house's fallback pictures (src/static, served from the build):
  // ——— a theme or plugin that ships no preview image wears one of these at
  // ——— random — the shelf is never a wall of "no preview" notices. ———
  const HOUSE_SHOTS = ['01', '02', '03', '04', '05'];
  const housePicks: Record<string, string> = {};

  /** One of the house pictures, picked at random and KEPT (the card must not
   *  shuffle itself on every re-render). */
  function houseShot(key: string): string {
    if (!housePicks[key]) {
      housePicks[key] = `${base}/${HOUSE_SHOTS[Math.floor(Math.random() * HOUSE_SHOTS.length)]}.jpeg`;
    }
    return housePicks[key];
  }

  // ——— the templates tab: the 50/50 split ———
  // RIGHT = every theme you could pick (installed AND the Vitrine shelf, one
  // list); LEFT = the fitting room (the preview) + the details of the chosen
  // one. The chosen theme is a NAME — the row is derived, so a theme that
  // leaves the shelf cannot leave a stale selection behind.
  type ThemeRow = {
    name: string;
    title: string;
    version: string;
    description: string;
    styles: readonly string[];
    requires: readonly string[];
    missing: readonly string[];
    active: boolean;
    houseDefault: boolean;
    installed: boolean;
    state: 'active' | 'installed' | 'update' | 'installable';
    preview: string | null;
    template: TemplateRef | null;
    ware: WareRef | null;
  };

  let selectedTheme = $state('');

  // the shelf images that failed to load (a ware's preview may not be pushed
  // yet) — a broken picture falls back to the notice, never to a broken icon.
  let brokenPreviews = $state<Record<string, boolean>>({});

  const themeRows = $derived.by((): ThemeRow[] => {
    const rows: ThemeRow[] = templates.map((t) => {
      const ware = vitrineWare('template', t.name);
      return {
        name: t.name,
        title: t.title,
        version: t.version,
        description: t.description,
        styles: t.styles,
        requires: ware?.requires ?? [],
        missing: ware?.missing ?? [],
        active: t.active,
        houseDefault: t.default,
        installed: true,
        state: t.active ? 'active' : (ware?.state === 'update' ? 'update' : 'installed'),
        preview: t.preview ?? null,
        template: t,
        ware: ware ?? null
      };
    });
    for (const w of vitrine) {
      if (w.kind !== 'template' || w.state === 'installed') continue;
      if (rows.some((r) => r.name === w.name)) continue; // the installed row speaks for it
      rows.push({
        name: w.name,
        title: w.title,
        version: w.version,
        description: w.description,
        styles: w.styles ?? [],
        requires: w.requires,
        missing: w.missing,
        active: false,
        houseDefault: false,
        installed: false,
        state: w.state === 'update' ? 'update' : 'installable',
        preview: w.previewUrl && w.previewUrl !== '' ? w.previewUrl : null,
        template: null,
        ware: w
      });
    }
    return rows;
  });

  // the list, split: what this host HAS on top, the Vitrine's window below
  const installedRows = $derived(themeRows.filter((r) => r.installed));
  const shelfRows = $derived(themeRows.filter((r) => !r.installed));

  // the chosen theme: the picked one, else the live one, else the first on the shelf
  const selectedRow = $derived(
    themeRows.find((r) => r.name === selectedTheme) ?? themeRows.find((r) => r.active) ?? themeRows[0] ?? null
  );

  // ——— the plugins tab: the same 50/50 split ———
  // RIGHT = every plugin (installed AND the Vitrine shelf, one list); LEFT =
  // the chosen plugin: preview + title/version, description, tags, buttons,
  // then the documentation / dependencies / settings detail tabs.
  type PluginRow = {
    name: string;
    title: string;
    version: string;
    description: string;
    documentation: string;
    tags: readonly string[];
    requires: readonly string[];
    missing: readonly string[];
    enabled: boolean;
    installed: boolean;
    error?: string;
    state: 'enabled' | 'disabled' | 'update' | 'installable';
    preview: string | null;
    plugin: PluginRef | null;
    ware: WareRef | null;
  };

  let selectedPlugin = $state('');
  let pluginDetailTab = $state<'documentation' | 'dependencies' | 'settings'>('documentation');
  // Plugin settings (2026-11-11): the draft mirrors the stored values; the
  // $effect re-seats it whenever the selected plugin (or the shelf) changes.
  let pluginSettingsDraft = $state<Record<string, string | boolean>>({});
  let revealedSecrets = $state<Record<string, boolean>>({});
  let settingsBusy = $state(false);
  let settingsNote = $state('');

  // The plugin's own PANEL (2026-11-12): the core renders what the plugin
  // hands over and understands none of it — the words, the buttons, the list
  // and the one-time reveal are the plugin's own. The plugin answers its
  // declared panel route (GET = descriptor, POST = an action).
  let panelData = $state<PluginPanel | null>(null);
  let panelBusy = $state(false);
  let panelNote = $state('');
  let panelModal = $state<null | { id: string; label: string; type: string; placeholder: string }>(null);
  let panelValue = $state('');
  let panelShow = $state<{ label: string; value: string } | null>(null);

  async function loadPanel(plugin: PluginRef | null) {
    if (!plugin?.panel) {
      panelData = null;
      return;
    }
    try {
      panelData = await fetchPluginPanel(plugin.name, plugin.panel.route);
    } catch (e) {
      panelNote = e instanceof Error ? e.message : String(e);
    }
  }

  async function panelAction(action: { id: string; label: string }, rowId?: string) {
    const plugin = selectedPluginRow?.plugin;
    if (!plugin?.panel || panelBusy) return;
    panelBusy = true;
    panelNote = '';
    try {
      const data = await pluginPanelAction(plugin.name, plugin.panel.route, {
        action: action.id,
        id: rowId ?? '',
        value: panelValue
      });
      panelData = data;
      panelModal = null;
      panelValue = '';
      if (data.show) panelShow = { label: data.show.label, value: data.show.value };
    } catch (e) {
      panelNote = e instanceof Error ? e.message : String(e);
    } finally {
      panelBusy = false;
    }
  }

  async function copyPanelShow() {
    if (!panelShow) return;
    try {
      await navigator.clipboard.writeText(panelShow.value);
      panelNote = 'Copied.';
    } catch {
      panelNote = 'Copy failed — select it by hand.';
    }
  }



  $effect(() => {
    const row = selectedPluginRow;
    pluginSettingsDraft = { ...(row?.plugin?.settingsValues ?? {}) };
    revealedSecrets = {};
    settingsNote = '';
    panelShow = null;
    panelNote = '';
    void loadPanel(row?.plugin ?? null);
  });

  async function savePluginSettingsNow() {
    const row = selectedPluginRow;
    if (!row?.plugin || settingsBusy) return;
    settingsBusy = true;
    settingsNote = '';
    try {
      await savePluginSettings(row.name, { ...pluginSettingsDraft });
      plugins = await listPlugins(); // the shelf re-reads — the draft effect re-seats itself
      settingsNote = 'Saved.';
    } catch (e) {
      settingsNote = e instanceof Error ? e.message : String(e);
    } finally {
      settingsBusy = false;
    }
  }

  const pluginRows = $derived.by((): PluginRow[] => {
    const rows: PluginRow[] = plugins.map((p) => {
      const ware = vitrineWare('plugin', p.name);
      return {
        name: p.name,
        title: p.title,
        version: p.version,
        description: p.description,
        documentation: p.documentation ?? '',
        tags: p.tags ?? [],
        requires: ware?.requires ?? p.requires ?? [],
        missing: ware?.missing ?? [],
        enabled: p.enabled,
        installed: true,
        error: p.error,
        state: ware?.state === 'update' ? 'update' : p.enabled ? 'enabled' : 'disabled',
        preview: p.preview ?? null,
        plugin: p,
        ware: ware ?? null
      };
    });
    for (const w of vitrine) {
      if (w.kind !== 'plugin' || w.state === 'installed') continue;
      if (rows.some((r) => r.name === w.name)) continue; // the installed row speaks for it
      rows.push({
        name: w.name,
        title: w.title,
        version: w.version,
        description: w.description,
        documentation: w.documentation ?? '',
        tags: w.tags ?? [],
        requires: w.requires,
        missing: w.missing,
        enabled: false,
        installed: false,
        state: w.state === 'update' ? 'update' : 'installable',
        preview: w.previewUrl && w.previewUrl !== '' ? w.previewUrl : null,
        plugin: null,
        ware: w
      });
    }
    return rows;
  });
  const installedPluginRows = $derived(pluginRows.filter((r) => r.installed));
  const shelfPluginRows = $derived(pluginRows.filter((r) => !r.installed));

  const selectedPluginRow = $derived(
    pluginRows.find((r) => r.name === selectedPlugin) ?? pluginRows[0] ?? null
  );

  async function loadCore() {
    if (coreBusy) return;
    coreBusy = true;
    coreError = '';
    try {
      const st = await coreStatus();
      core = { current: st.current, latest: st.latest, error: st.error ?? '', localVersion: st.localVersion ?? null, updatable: st.updatable === true, dashboard: st.dashboard ?? null, releaseName: st.releaseName ?? null, published: st.published ?? null, contributors: st.contributors ?? [] };
    } catch (e) {
      coreError = e instanceof Error ? e.message : String(e);
    } finally {
      coreBusy = false;
    }
  }

  async function parkCorePacketNow(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || coreBusy) return;
    coreBusy = true;
    coreError = '';
    coreNote = '';
    try {
      const parked = await uploadCorePacket(file);
      coreNote = `Parked: a local packet announcing v${parked.version} — the knife can ride from the shelf now.`;
      await loadCore();
    } catch (e) {
      coreError = e instanceof Error ? e.message : String(e);
    } finally {
      coreBusy = false;
      input.value = ''; // the same file can be re-parked without a browser ruse
    }
  }

  async function updateCoreNow(source: 'hub' | 'local' = 'hub') {
    if (coreBusy) return;
    coreBusy = true;
    coreError = '';
    coreNote = '';
    try {
      const done = await runCoreUpdate(source);
      await loadCore();
      coreNote = `${done.note} Reload the page — the dashboard you are wearing is from ${done.from}.`;
      if (!done.dashboard) coreNote += ' The dashboard did NOT move with this packet — rebuild it (npm run build) or ride a release that carries its asset zip.';
    } catch (e) {
      coreError = e instanceof Error ? e.message : String(e);
    } finally {
      coreBusy = false;
    }
  }

  async function copyMediaUrl(item: SiteMediaRef) {
    try {
      await navigator.clipboard.writeText(item.url);
      mediaNote = `Copied: ${item.name}`;
    } catch {
      mediaError = 'Clipboard refused — copy the URL below by hand.';
      mediaNote = item.url;
    }
  }

  // ——— accounts (admin) ———
  let accounts = $state<readonly AccountRef[]>([]);
  let accountAddUser = $state('');
  let accountAddRole = $state<'admin' | 'editor'>('editor');
  let accountAddPassword = $state('');
  let accountAddEmail = $state('');
  let accountError = $state('');
  let accountNote = $state('');

  async function loadAccounts() {
    try {
      accounts = await listAccounts();
    } catch (e) {
      accountError = e instanceof Error ? e.message : String(e);
    }
  }

  async function addAccountNow(event: SubmitEvent) {
    event.preventDefault();
    accountError = '';
    try {
      await addAccount(accountAddUser, accountAddPassword, accountAddRole, accountAddEmail);
      accountAddUser = '';
      accountAddPassword = '';
      accountAddEmail = '';
      accounts = await listAccounts();
    } catch (e) {
      accountError = e instanceof Error ? e.message : String(e);
    }
  }

  async function deleteAccountNow(user: string) {
    try {
      await removeAccount(user);
      accounts = await listAccounts();
    } catch (e) {
      accountError = e instanceof Error ? e.message : String(e);
    }
  }

  async function inviteNow(user: string) {
    accountError = '';
    accountNote = '';
    try {
      await inviteAccount(user);
      accountNote = `Credentials mailed to ${user}.`;
    } catch (e) {
      accountError = e instanceof Error ? e.message : String(e);
    }
  }

  // ——— new page → modal: title, slug, style, a page-type scaffold, draft-vs-publish ———
  // The style is a creation-time choice (2026-11-09, user order): the options
  // are the ACTIVE THEME's declared styles, the frontpage among them — picking
  // it crowns the new page as the home (the frontpage style is derived from
  // the crown, never written to frontmatter). Styles load lazily with the
  // templates; no theme, no list, no selector.
  let pagetypes = $state<readonly PagetypeRef[]>([]);
  let newModal = $state<null | { title: string; slug: string; style: string; scaffold: string; asDraft: boolean }>(null);
  let creating = $state(false);
  let newError = $state('');

  const activeTheme = $derived(templates.find((t) => t.active) ?? null);
  const activeThemeStyles = $derived(activeTheme?.styles ?? []);
  const prettyStyle = (style: string): string =>
    style.split('-').filter(Boolean).map((w) => w[0].toUpperCase() + w.slice(1)).join(' ');

  function openNew() {
    newError = '';
    newModal = { title: '', slug: '', style: activeTheme?.defaultStyle ?? '', scaffold: 'article', asDraft: false };
    void loadPagetypes(); // scaffolds pay for themselves — lazy load on modal open
    if (activeThemeStyles.length === 0) void loadTemplates(); // the style list rides the theme
  }

  /** The Landing law (sidequest, 2026-11-07): the index page is the front door —
   *  picking the Landing scaffold while no index page exists preaches 'index'
   *  into the slug field, so the new page lands where the home law looks. */
  function pickScaffold(next: string) {
    if (newModal === null) return;
    if (next === 'landing' && newModal.slug === ''
      && !(index?.['pages'] ?? []).some((p) => p.slug === 'index')) {
      newModal.slug = 'index';
    }
  }

  async function loadPagetypes() {
    if (pagetypes.length > 0) return; // fetched once per visit, refreshed by reload
    try {
      pagetypes = await listPagetypes();
    } catch (e) {
      newError = e instanceof Error ? e.message : String(e);
    }
  }

  async function saveNew(event: SubmitEvent) {
    event.preventDefault();
    const m = newModal;
    if (m === null) return;
    creating = true;
    newError = '';
    try {
      const today = new Date().toISOString().slice(0, 10);
      // JSON.stringify quotes the title — YAML-hostile colons stay harmless.
      // The frontpage style is never written: the crown derives it; choosing
      // frontpage here crowns the new page the moment it exists.
      const styleLine = m.style !== '' && m.style !== 'frontpage' ? `\nstyle: ${m.style}` : '';
      const frontmatter = `---\ntitle: ${JSON.stringify(m.title)}\nslug: ${m.slug}\npublished: true\ndraft: ${m.asDraft}\ndate: ${today}\ntags: []${styleLine}\n---`;
      const scaffold = pagetypes.find((p) => p.name === m.scaffold);
      await createPage('pages', m.slug, `${frontmatter}\n\n${scaffold?.body ?? ''}`.trimEnd() + '\n');
      if (m.style === 'frontpage') await setHomePage(m.slug);
      refreshIndex();
      select('pages', m.slug); // onto the live page — fill it there
      newModal = null;
    } catch (e) {
      newError = e instanceof Error ? e.message : String(e);
    } finally {
      creating = false;
    }
  }

  // ——— upload .md → modal: validate frontmatter, fix slug/title, choose draft or publish ———
  let uploadError = $state('');
  let uploading = $state(false);
  let uploadModal = $state<null | {
    fileName: string;
    raw: string;
    slug: string;
    title: string;
    issues: string[];
    valid: boolean;
    asDraft: boolean;
  }>(null);

  const slugify = (name: string): string =>
    name.replace(/\.(md|markdown)$/i, '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '') || `uploaded-${Date.now()}`;

  async function onUploadPick(event: Event) {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    uploadError = '';
    const raw = await file.text();
    // Probe the frontmatter: capture the parsed object, then gate it through Zod.
    let data: Record<string, unknown> = {};
    let issues: string[] = [];
    try {
      parseMdms(raw, {
        validateFrontmatter: (d) => {
          data = d as Record<string, unknown>;
          return data;
        }
      });
      const result = schemaFor('pages').safeParse(data);
      if (!result.success) {
        issues = result.error.issues.map((i) => `${i.path.length > 0 ? i.path.join('.') : 'frontmatter'}: ${i.message}`);
      }
    } catch (e) {
      issues = [e instanceof Error ? e.message : String(e)];
    }
    uploadModal = {
      fileName: file.name,
      raw,
      slug: slugify(typeof data.slug === 'string' ? data.slug : file.name),
      title: typeof data.title === 'string' && data.title !== '' ? data.title : file.name.replace(/\.(md|markdown)$/i, ''),
      issues,
      valid: issues.length === 0,
      asDraft: false
    };
  }

  async function saveUpload(event: SubmitEvent) {
    event.preventDefault();
    const m = uploadModal;
    if (m === null) return;
    uploading = true;
    uploadError = '';
    try {
      const split = splitFrontmatter(m.raw);
      let rawToStore: string;
      if (m.valid) {
        rawToStore = m.asDraft ? withDraftFlag(m.raw, true) : m.raw;
      } else {
        // Broken frontmatter: rebuild it from the modal fields, keep the body verbatim.
        const today = new Date().toISOString().slice(0, 10);
        const frontmatter = `---\ntitle: ${JSON.stringify(m.title)}\nslug: ${m.slug}\npublished: true\ndraft: ${m.asDraft}\ndate: ${today}\ntags: []\n---`;
        rawToStore = `${frontmatter}\n\n${split.body}`;
      }
      await createPage('pages', m.slug, rawToStore);
      refreshIndex();
      select('pages', m.slug); // onto the live page — fill it there
      uploadModal = null;
    } catch (e) {
      uploadError = e instanceof Error ? e.message : String(e);
    } finally {
      uploading = false;
    }
  }

  // ——— delete + rename per page ———
  let renaming = $state<{ type: string; slug: string; value: string } | null>(null);
  let renameError = $state('');

  function removePage(type: string, slug: string) {
    askConfirm('Delete page', `Delete ${type}/${slug} for good? No undo, no tears.`, () => removePageGo(type, slug));
  }

  async function removePageGo(type: string, slug: string) {
    try {
      await deletePage(type, slug);
      // the sheet never haunts a deleted file — it lets go
      if (sel?.type === type && sel?.slug === slug) {
        sel = null;
        raw = '';
        baseline = '';
        baseHash = '';
        unlocked = false;
      }
      refreshIndex();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  function startRename(type: string, slug: string) {
    renameError = '';
    renaming = { type, slug, value: slug };
  }

  async function commitRename(event: SubmitEvent) {
    event.preventDefault();
    if (renaming === null) return;
    const { type, slug, value } = renaming;
    const to = value.trim().toLowerCase();
    try {
      const newSlug = await renamePage(type, slug, to);
      renaming = null;
      refreshIndex();
    } catch (e) {
      renameError = e instanceof Error ? e.message : String(e);
    }
  }

  // ——— page settings: per-page frontmatter, ported from the retired editor burger ———
  // The ONE overlay law (2026-11-04): the dashboard carries the page's title,
  // date, tags and draft state. published/published flags are preserved
  // verbatim (publish/unpublish toggles stay a deliberate act, not a side effect);
  // baking reuses the editor's own path — PUT draft under the X-Base-Hash guard +
  // publish with the client-rendered body. Slug changes stay with rename (✎), so
  // no URL surgery happens behind a settings form's back. The per-page style
  // selector left 2026-11-09 (user order, third telling): template/style
  // choice lives on the Templates tab alone.

  let pageSettings = $state<null | {
    type: string;
    slug: string;
    published: boolean;
    title: string;
    asDraft: boolean;
    date: string;
    tags: string;
    isHome: boolean;
    wasHome: boolean;
    baseRaw: string;
    hash: string;
    saving: boolean;
    error: string;
  }>(null);

  async function openPageSettings(type: string, slug: string) {
    try {
      const { raw, hash } = await getDraftRaw(type, slug);
      const { frontmatter } = splitFrontmatter(raw);
      const peek = (key: string): string =>
        new RegExp(`^${key}:\\s*(.*)$`, 'm').exec(frontmatter)?.[1]?.trim().replace(/^["']|["']$/g, '') ?? '';
      // the crown's face: the index carries the EFFECTIVE home (the pages
      // endpoint crowns site.home, or the first published page as the
      // fallback) — the same law the tree's badge and the delete lid ride
      const wasHome = (index?.[type] ?? []).some((p) => p.slug === slug && p.home === true);
      pageSettings = {
        type,
        slug,
        published: peek('published') !== 'false', // an absent published flag IS published
        title: peek('title'),
        asDraft: peek('draft') === 'true',
        date: peek('date'),
        tags: peek('tags').replace(/^\[|\]$/g, ''),
        isHome: wasHome,
        wasHome,
        baseRaw: raw,
        hash,
        saving: false,
        error: ''
      };
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  async function savePageSettings(event: SubmitEvent) {
    event.preventDefault();
    const s = pageSettings;
    if (s === null || s.saving) return;
    if (s.title.trim() === '') {
      s.error = 'The title cannot be empty.';
      return;
    }
    s.saving = true;
    s.error = '';
    try {
      const body = splitFrontmatter(s.baseRaw).body;
      const tagList = s.tags.split(',').map((t) => t.trim()).filter(Boolean);
      const lines = [
        '---',
        `title: ${JSON.stringify(s.title.trim())}`,
        `slug: ${s.slug}`,
        `published: ${s.published ? 'true' : 'false'}`,
        `draft: ${s.asDraft ? 'true' : 'false'}`,
        `date: ${s.date !== '' ? s.date : new Date().toISOString().slice(0, 10)}`,
        `tags: [${tagList.map((t) => JSON.stringify(t)).join(', ')}]`
      ];
      lines.push('---');
      const nextRaw = `${lines.join('\n')}\n\n${body}`.trimEnd() + '\n';
      await putDraftRaw(s.type, s.slug, nextRaw, s.hash);
      // Baking only makes sense for a live page — draft-born pages wait for the
      // regular publish path (frontmatter flags ride verbatim, store-side).
      if (s.published) {
        await publishDraft(s.type, s.slug, renderMdms(nextRaw, { type: s.type, slug: s.slug }).fullHtml);
      }
      // the crown moves only when the switch moved — one key of the settings
      // PUT, the server merges the rest; '' hands the crown to the fallback
      if (s.isHome !== s.wasHome) {
        await setHomePage(s.isHome ? s.slug : '');
        if (siteSettings !== null) siteSettings = { ...siteSettings, home: s.isHome ? s.slug : '' };
      }
      refreshIndex();
      pageSettings = null;
    } catch (e) {
      s.error = e instanceof Error ? e.message : String(e);
      s.saving = false;
    }
  }
</script>

<main class="min-h-screen">
  {#if authenticated === null}
    <p class="p-8 italic text-ink-soft" aria-live="polite">Reaching for the pantry…</p>
  {:else if setupNeeded}
    <div class="min-h-screen grid place-items-center p-6">
      <form onsubmit={foundBakery} class="mg w-full max-w-sm flex flex-col gap-3" aria-label="First-visit setup">
        <h1 class="m-0 font-extrabold text-2xl tracking-tight">Welcome to Schmarrn</h1>
        <p class="text-sm text-ink-soft m-0">First visit — found the bakery. One admin account, one name, one theme.</p>
        <label class="field"><span>Admin username</span><input class="input" type="text" bind:value={setupUser} autocomplete="username" required /></label>
        <label class="field"><span>Password</span><input class="input" type="password" bind:value={setupPassword} placeholder="min 8" minlength="8" autocomplete="new-password" required /></label>
        <label class="field"><span>Site name</span><input class="input" type="text" bind:value={setupSiteName} required /></label>
        <label class="field"><span>Claim <span class="hint">(optional)</span></span><input class="input" type="text" bind:value={setupClaim} /></label>
        <label class="field"><span>Theme</span>
          <select class="input" bind:value={setupTheme}>
            {#each setupThemes as theme (theme)}
              <option value={theme}>{theme}</option>
            {/each}
          </select>
        </label>
        <button type="submit" class="btn btn-primary" disabled={setupBusy}>{setupBusy ? 'Lighting the ovens…' : 'Found the bakery'}</button>
        {#if setupError}<p class="alert alert-err m-0" role="alert">{setupError}</p>{/if}
      </form>
    </div>
  {:else if authenticated === false}
    <div class="min-h-screen grid place-items-center p-6">
      <form onsubmit={signIn} class="mg w-full max-w-xs flex flex-col gap-3" aria-label="Login">
        <h1 class="m-0 font-extrabold text-2xl tracking-tight">Schmarrn</h1>
        <label class="field"><span>Who's kneading?</span><input class="input" type="text" bind:value={user} autocomplete="username" required /></label>
        <label class="field"><span>Kitchen password</span><input class="input" type="password" bind:value={password} autocomplete="current-password" required /></label>
        <button type="submit" class="btn btn-primary" disabled={busy}>{busy ? 'Plating…' : 'Schnitten!'}</button>
        <button type="button" class="btn-ghost self-center" onclick={openForgot}>Forgot password?</button>
        {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      </form>
    </div>
  {:else}
    <header class="flex items-center gap-3 px-4 py-3">
      <button class="burger" aria-label="Open menu" onclick={openMenu}>
        <span></span><span></span><span></span>
      </button>
      <span class="font-extrabold text-lg tracking-tight">Schmarrn</span>
      <span class="ml-auto text-sm text-ink-soft">{user}{#if role} · {role}{/if}</span>
      <button class="btn btn-secondary" onclick={signOut}>Log out</button>
    </header>

    <!-- the drawer is GONE from the dashboard (2026-11-07, user law): the overlay
         carries the tabs and the close X itself — a second door here just buried
         the X under a z-45 panel. Behind the overlay there is deliberately NO
         management view: the overlay IS the authed face of /_, and its X walks
         back to the exact page you came from. -->
  {/if}

  {#if error && authenticated !== false}
    <p class="alert alert-err" role="alert">{error}</p>
  {/if}
</main>

<!-- the plugin panel's modal: the plugin asks for one field (its own
     wording, its own type) — the core only carries it -->
{#if panelModal}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label={panelModal.label} tabindex="-1" onclick={() => (panelModal = null)}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form
      class="modal-card"
      onsubmit={(e) => {
        e.preventDefault();
        const act = panelData?.actions?.find((a) => a.id === panelModal?.id);
        if (act) panelAction(act);
      }}
      onclick={(e) => e.stopPropagation()}
    >
      <header class="modal-head">
        <h3>{panelModal.label}</h3>
        <button type="button" class="close-x" onclick={() => (panelModal = null)}>×</button>
      </header>
      <label class="field">
        <span>{panelModal.label}</span>
        <input
          class="input"
          type={panelModal.type}
          bind:value={panelValue}
          placeholder={panelModal.placeholder}
          required
          autocomplete="off"
        />
      </label>
      {#if panelNote}<p class="alert alert-err m-0" role="alert">{panelNote}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (panelModal = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={panelBusy}>Save</button>
      </footer>
    </form>
  </div>
{/if}

<!-- The ESC function is GONE (2026-11-09, user order): ESC left a modal open
     over a page that wasn't there. The X is the overlay's one door; the
     modals close by their X, Cancel, or a click on the dark. -->
{#if menuOpen}
  <!-- The dialog takes focus itself on mount; there is no Escape — the X is
       the door (2026-11-09, user order). -->
  <div class="fixed inset-0 z-40 flex flex-col overflow-hidden" role="dialog" aria-modal="true" aria-label="Menu" tabindex="-1"
    use:dialogFocus
    style="background: linear-gradient(145deg, #fffdf7 0%, #fff8ef 45%, #f9ecd9 100%);"
  >
    <!-- the overlay's chrome, slimmed by law (2026-11-07): tabs on top, the
         close X at the right edge where the live page wears its triangle,
         Log out lives in the drawer's foot — nothing else. -->
    <button class="sm-overlay-x" aria-label="Close the overlay — back to the page" title="Back to the page" onclick={(e) => { userClosing = true; closeMenu(e); }}>×</button>
    <nav class="flex gap-2 px-4 pb-4 flex-wrap" aria-label="Menu sections">
      <button class="btn {tab === 'contents' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('contents')}>Contents</button>
      {#if role === 'admin'}
        <button class="btn {tab === 'settings' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('settings')}>Settings</button>
        <button class="btn {tab === 'plugins' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('plugins')}>Plugins</button>
        <button class="btn {tab === 'templates' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('templates')}>Templates</button>
        <!-- the Updates door: silent (unavailable) until a newer core/template/plugin
             exists, then it speaks — the button itself carries the verdict -->
        <button
          class="btn {tab === 'updates' || updatesAvailable ? 'btn-primary' : 'btn-secondary'}"
          disabled={!updatesAvailable && tab !== 'updates'}
          onclick={() => showTab('updates')}
          title={updatesAvailable ? 'Updates are waiting' : 'Everything is current'}
        >
          {updatesAvailable ? 'Update available' : 'Up to date'}
        </button>
        <button class="btn {tab === 'users' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('users')}>Users</button>
      {/if}
    </nav>

    <div class="flex-1 min-h-0 px-4 mx-auto w-full {tab === 'contents' || tab === 'templates' || tab === 'plugins' || tab === 'settings' ? 'pb-4 max-w-none' : 'pb-12 max-w-4xl overflow-y-auto'}">
      {#snippet sectionLine(id: string, label: string)}
        <!-- the foldable section line: the triangle rides the very right —
             down while closed, up while open -->
        <button
          class="w-full flex items-center justify-between gap-3 py-1 my-1 shrink-0 cursor-pointer"
          onclick={() => toggleSection(id)}
          aria-expanded={openSections[id] === true}
        >
          <span class="text-xs font-bold uppercase tracking-widest text-ink-soft">{label}</span>
          <span class="text-xs text-ink-soft">{openSections[id] ? '▲' : '▼'}</span>
        </button>
      {/snippet}
      {#snippet githubShelf(kind: 'plugin' | 'template')}
        <!-- THE GITHUB SHELF (2026-11-12, user order): under the Vitrine, one
             row that takes a repo URL. The LOOK reads the manifest and tells
             what the repo holds; STAGE copies it onto the shelf — lided,
             DISABLED, its code UNRUN until the admin enables it. Without a
             connected account only public repos answer; with one, the
             account's private repos are in reach. -->
        {@render sectionLine(kind === 'plugin' ? 'plugins-github' : 'templates-github', 'GitHub')}
        {#if openSections[kind === 'plugin' ? 'plugins-github' : 'templates-github']}
        <div class="card p-3 flex flex-col gap-2 shrink-0">
          <label class="field">
            <span>Repository</span>
            <input class="input" bind:value={githubRepo} placeholder="owner/repo or a GitHub URL" />
          </label>
          <div class="flex items-center gap-2 flex-wrap">
            <button class="btn btn-secondary" disabled={githubBusy || githubRepo.trim() === ''} onclick={lookGithubNow}>
              {githubBusy ? 'Looking…' : 'Look'}
            </button>
          </div>
          {#if githubLook}
            {#if githubLook.error}
              <p class="m-0 text-xs text-error">{githubLook.error}</p>
            {:else}
              <p class="m-0 text-xs text-ink-soft">
                {githubLook.repo}
                {#if githubLook.private}<span class="badge">private</span>{/if}
              </p>
              <ul class="list-none p-0 m-0 flex flex-col gap-1">
                {#each githubLook.wares ?? [] as ware (ware.kind + ':' + ware.path)}
                  <li class="flex items-center justify-between gap-2 text-xs">
                    <span class="min-w-0 truncate">
                      <span class="badge">{ware.kind}</span>
                      {ware.name ?? '—'}
                      {#if ware.version}<span class="text-ink-soft">v{ware.version}</span>{/if}
                      {#if ware.path}<span class="text-ink-soft">({ware.path})</span>{/if}
                      {#if ware.error}<span class="text-error"> — {ware.error}</span>{/if}
                    </span>
                    {#if !ware.error && ware.kind === kind}
                      <button
                        class="btn btn-primary shrink-0"
                        disabled={githubBusy}
                        onclick={() => stageGithubNow(kind, ware.path)}
                      >Stage</button>
                    {/if}
                  </li>
                {/each}
              </ul>
            {/if}
          {/if}
          {#if githubError}<p class="alert alert-err m-0" role="alert">{githubError}</p>{/if}
          {#if githubNote}<p class="alert alert-ok m-0">{githubNote}</p>{/if}
          <p class="m-0 text-xs text-ink-soft">
            A staged ware lands DISABLED — its code does not run until you enable it with your own hand.
          </p>
        </div>
        {/if}
      {/snippet}
      {#if tab === 'contents'}
        <div class="sm-desk">
          <!-- the tree: every md file in use — no type headlines (2026-11-09,
               user order: redundant); a needle and a tag do the finding. The
               draft state rides as badges; the row verbs stay in the margin -->
          <aside class="sm-desk-tree">
            <div class="flex flex-col gap-1">
              <input
                class="input"
                type="search"
                placeholder="Search the pages…"
                aria-label="Search page text"
                bind:value={searchQ}
                oninput={onSearchInput}
              />
              <select class="input" aria-label="Filter by tag" bind:value={tagFilter}>
                <option value="">All tags</option>
                {#each allTags as t (t)}<option value={t}>{t}</option>{/each}
              </select>
            </div>
            <div class="sm-desk-tree-list">
              {#if index === null}
                <p class="italic text-ink-soft">Opening the drawer…</p>
              {:else if deskRows.length === 0}
                <p class="italic text-ink-soft">
                  {#if searchBusy}Searching…{:else}Nothing found{activeQ !== '' ? ` for “${activeQ}”` : ''}{tagFilter !== '' ? ` tagged “${tagFilter}”` : ''}.{/if}
                </p>
              {:else}
                <ul class="list-none p-0 m-0 flex flex-col gap-1">
                  {#each deskRows as row (row.key)}
                    <li>
                      <div class="flex items-center gap-1">
                        <button
                          class="btn btn-secondary flex-1 justify-start min-w-0"
                          class:btn-primary={sel?.type === row.type && sel?.slug === row.page.slug}
                          title={`${row.type}/${row.page.slug}/index.md`}
                          onclick={() => openRaw(row.type, row.page.slug)}
                        >
                          <span class="truncate">{row.page.slug}</span>
                          {#if row.page.home}<span class="badge">home</span>{/if}
                          {#if row.page.draft}<span class="badge badge-draft">draft</span>{/if}
                          {#if row.page.hasDraft}<span class="badge badge-live">edits</span>{/if}
                        </button>
                        <!-- while the sheet is unlocked (surgery), the row's
                             edit icons wear the disabled cursor (2026-11-12,
                             user order) — the eye reads surgery mode at a glance -->
                        <button class="btn-ghost" class:cursor-not-allowed={unlocked} title="Rename" onclick={() => startRename(row.type, row.page.slug)}>✎</button>
                        <button class="btn-ghost" class:cursor-not-allowed={unlocked} title="Page settings" onclick={() => openPageSettings(row.type, row.page.slug)}>✑</button>
                        {#if row.page.home}
                          <!-- the home page stands: no delete, ever (the lid is server-side too) -->
                        {:else}
                          <button
                            class="btn-ghost danger"
                            class:cursor-not-allowed={unlocked}
                            title={sel?.type === row.type && sel?.slug === row.page.slug && unlocked ? 'Delete page' : 'Open the page and press Unlock first — deletion follows the unlock'}
                            disabled={!(sel?.type === row.type && sel?.slug === row.page.slug && unlocked)}
                            onclick={() => removePage(row.type, row.page.slug)}
                          >×</button>
                        {/if}
                        <button class="btn-ghost" class:cursor-not-allowed={unlocked} title="Open the live page" onclick={() => select(row.type, row.page.slug)}>↗</button>
                      </div>
                      {#if renaming?.type === row.type && renaming?.slug === row.page.slug}
                        {@const to = renaming.value.trim().toLowerCase()}
                        {@const unchanged = to === renaming?.slug}
                        {@const taken = (index?.[row.type] ?? []).some((p) => p.slug === to && p.slug !== renaming?.slug)}
                        <form class="rename flex gap-1 flex-wrap items-center mt-1" onsubmit={commitRename}>
                          <input class="input max-w-40" bind:value={renaming.value} pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
                          <button type="submit" class="btn btn-primary" disabled={unchanged || taken}
                            title={unchanged ? 'Name unchanged' : taken ? 'Already taken' : 'Rename'}>✓</button>
                          <button type="button" class="btn btn-secondary" onclick={() => (renaming = null)}>×</button>
                          {#if unchanged}
                            <span class="text-xs text-ink-soft">Same name.</span>
                          {:else if taken}
                            <span class="text-xs text-error">Another page already uses “{to}”.</span>
                          {:else if renameError}
                            <span class="text-xs text-error">{renameError}</span>
                          {/if}
                        </form>
                      {/if}
                    </li>
                  {/each}
                </ul>
              {/if}
            </div>
            <div class="sm-desk-foot">
              {#if deskError}<p class="alert alert-err m-0" role="alert">{deskError}</p>{/if}
              {#if deskNote}<p class="alert alert-ok m-0">{deskNote}</p>{/if}
              <!-- the save button only wakes when the sheet actually differs -->
              <button
                class="btn btn-primary w-full justify-center"
                disabled={!dirty || deskBusy}
                onclick={() => saveRaw()}
                title="Save the draft (Ctrl+S)"
              >
                {#if deskBusy}Saving…{:else if dirty}Save draft{:else}Saved{/if}
              </button>
              <div class="flex gap-1">
                <button class="btn btn-secondary flex-1 justify-center" onclick={openNew}>+ New</button>
                <label class="btn btn-secondary flex-1 justify-center cursor-pointer">
                  <input class="hidden" type="file" accept=".md,.markdown,text/markdown" onchange={onUploadPick} />
                  Upload
                </label>
                <button class="btn btn-secondary flex-1 justify-center" onclick={() => { mediaModal = true; void loadAllMedia(); }}>Media</button>
              </div>
              {#if uploadError}<p class="alert alert-err m-0" role="alert">{uploadError}</p>{/if}
            </div>
          </aside>
          <!-- the sheet: the md file, plain text, the whole remaining width -->
          <div class="sm-desk-main">
            {#if sel === null}
              <div class="flex-1 grid place-items-center text-center p-6">
                <p class="m-0 italic text-ink-soft">Pick a file from the tree — its markdown opens here. The draft saves guarded; publish from ✑.</p>
              </div>
            {:else}
              <div class="flex items-center gap-2 flex-wrap">
                <p class="m-0 font-bold text-sm">{selPath}</p>
                {#if dirty}<span class="badge">unsaved</span>{/if}
                {#if !unlocked}<span class="badge">locked</span>{/if}
                <button
                  class="btn btn-secondary ml-auto"
                  disabled={deskBusy}
                  onclick={() => (unlocked ? (unlocked = false) : askConfirm('Unlock the sheet', 'Raw markdown is surgery — a stray keystroke can knead the page beyond recognition. The lock returns on save.', () => (unlocked = true)))}
                  title={unlocked ? 'Lock the sheet again' : 'Raw markdown is surgery — unlock to edit; the lock returns on save'}
                >
                  {unlocked ? 'Lock' : 'Unlock'}
                </button>
              </div>
              <p class="m-0 text-xs text-ink-soft">
                The visual editor is the house way — open the live page (↗) and click what you see.
                This sheet writes raw markdown: unlock only for surgery, and it locks again on save.
              </p>
              <textarea
                class="sm-desk-sheet"
                class:sm-desk-locked={!unlocked}
                bind:value={raw}
                onkeydown={deskKeydown}
                readonly={!unlocked}
                spellcheck="false"
                disabled={deskBusy}
                aria-label={`Markdown source of ${selPath}`}
              ></textarea>
            {/if}
          </div>
        </div>
      {:else if tab === 'settings' && role === 'admin'}
        <!-- THE BENTO (2026-11-11, user order): 5 columns x 2 rows — site
             settings 1x1, theme style 2x1; the empty cells wait for later
             freight. Every tile scrolls inside itself: the page never moves. -->
        {#if siteSettings}
          <div class="grid grid-cols-1 lg:grid-cols-5 gap-4 min-h-0 overflow-y-auto">
            <!-- THE ATTRIBUTION CARD (2026-11-12, user order): first in the
                 bento, 1x1. The release's own name + day, the people behind
                 it, the way to support it, the door back to GitHub. -->
            <section class="mg flex flex-col gap-2 lg:col-span-1">
              <h4 class="mg-h">Schmarrn {core?.releaseName ?? core?.current ?? ''}</h4>
              {#if core?.published}<p class="m-0 text-xs text-ink-soft">published {core.published}</p>{/if}
              <hr class="border-line" />
              <p class="m-0 text-sm">
                Attribution:
                {#each core?.contributors ?? [] as person (person.login)}
                  <a href={person.url} target="_blank" rel="noopener" class="underline">{person.login}</a>{' '}
                {/each}
                <span>Cornelia Diomis, Chinami Sato</span>
              </p>
              <hr class="border-line" />
              <p class="m-0 text-sm">Support the project via <span class="underline">Voxerl</span></p>
              <hr class="border-line" />
              <a
                class="btn btn-secondary self-start"
                href="https://github.com/swisd-project/schmarrn"
                target="_blank"
                rel="noopener"
              >GitHub ↗</a>
              <p class="m-0 text-xs text-ink-soft">
                © {new Date().getFullYear()}
                <a href="https://homahuki.eu" target="_blank" rel="noopener" class="underline">Homahuki</a>
                ·
                <a href="https://hideosnes.online" target="_blank" rel="noopener" class="underline">Hidéo Snes</a>
              </p>
            </section>
            <form class="mg flex flex-col gap-3 lg:col-span-1" onsubmit={saveSiteNow}>
              <h4 class="mg-h">Site settings</h4>
              <label class="field"><span>Site name</span><input class="input" bind:value={siteSettings.name} required /></label>
              <label class="field"><span>Claim</span><input class="input" bind:value={siteSettings.claim} /></label>
              <div class="field">
                <span>Logo</span>
                <!-- the logo face (2026-11-12, user order): no filepath, no
                     text field — the picture IS the field. Clicking it offers
                     the upload; the × lets it go. Both land saved at once. -->
                <div class="flex items-center gap-2">
                  <label
                    class="relative block w-16 h-16 border border-line rounded overflow-hidden bg-batter/40 hover:bg-batter/70 cursor-pointer grid place-items-center shrink-0"
                    title="Upload a logo"
                  >
                    <input class="hidden" type="file" accept="image/*" onchange={onLogoPick} disabled={siteBusy} />
                    {#if siteSettings.logo}
                      <img src={siteSettings.logo} alt="Logo" class="max-w-full max-h-full object-contain p-1" />
                    {:else}
                      <span class="text-xs text-ink-soft">{siteBusy ? '…' : 'upload'}</span>
                    {/if}
                  </label>
                  {#if siteSettings.logo}
                    <button type="button" class="btn-ghost text-xs shrink-0" title="Remove the logo" onclick={clearLogoNow} disabled={siteBusy}>×</button>
                  {/if}
                </div>
              </div>
              <button type="submit" class="btn btn-primary self-start" disabled={siteBusy}>{siteBusy ? 'Saving…' : 'Save site'}</button>
              {#if siteError}<p class="alert alert-err m-0" role="alert">{siteError}</p>{/if}
              {#if siteNote}<p class="alert alert-ok m-0">{siteNote}</p>{/if}
            </form>

            <section class="mg flex flex-col gap-3 lg:col-span-2">
              <h4 class="mg-h">Theme style</h4>
            {#if siteSettings.handles.length === 0}
              <p class="italic text-ink-soft m-0">The active template declares no handles — its stylesheet is the only way in.</p>
            {:else}
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                <!-- LEFT: the color stack (2026-11-12, user order) — squares as
                     tall as the text, stacked vertically, each with what it
                     CHANGES beside it. The theme's color NAMES are gone: a
                     label like "Crust — panels & header" speaks as "panels &
                     header". The hook returns the template's default. -->
                <div class="flex flex-col gap-1.5">
                  {#each siteSettings.handles.filter((h) => h.type === 'color') as handle (handle.var)}
                    <div class="flex items-center gap-2">
                      <input
                        type="color"
                        class="w-6 h-6 p-0 border border-line rounded cursor-pointer bg-transparent shrink-0"
                        bind:value={handleValues[handle.var]}
                        title={handle.label}
                        aria-label={handle.label}
                      />
                      <button
                        type="button"
                        class="btn-ghost text-xs shrink-0"
                        title="Back to the template's default ({handle.label})"
                        onclick={() => resetHandle(handle.var, handle.default)}
                      >↺</button>
                      <span class="text-sm text-ink-soft truncate">{shortHandleLabel(handle.label)}</span>
                    </div>
                  {/each}
                </div>
                <!-- RIGHT: the type side — the font moved here (2026-11-12,
                     user order) from the site form; text handles ride along. -->
                <div class="flex flex-col gap-3">
                  {#each siteSettings.handles.filter((h) => h.type !== 'color') as handle (handle.var)}
                    <div class="field">
                      <span>{handle.label}</span>
                      <div class="flex items-center gap-2">
                        <input class="input" bind:value={handleValues[handle.var]} aria-label={handle.label} />
                        <button type="button" class="btn-ghost" title="Back to the template's default" onclick={() => resetHandle(handle.var, handle.default)}>↺</button>
                      </div>
                    </div>
                  {/each}
                  <label class="field">
                    <span>Font <span class="hint">(Google name, https:// URL, or upload)</span></span>
                    <input class="input" bind:value={siteSettings.font} placeholder="e.g. Comic Neue — empty = theme default" />
                    <label class="btn btn-secondary self-start cursor-pointer">
                      <input class="hidden" type="file" accept=".woff2,.woff,.ttf,.otf" onchange={onFontPick} disabled={siteBusy} />
                      {siteBusy ? 'Uploading…' : 'Upload font…'}
                    </label>
                    <span class="hint">A plain name uses Google Fonts; a https:// link is used as-is; an uploaded file is served from the site itself.</span>
                  </label>
                </div>
              </div>
              <button type="button" class="btn btn-primary self-start" onclick={saveHandlesNow} disabled={styleBusy}>{styleBusy ? 'Saving…' : 'Save style'}</button>
              {#if styleError}<p class="alert alert-err m-0" role="alert">{styleError}</p>{/if}
              {#if styleNote}<p class="alert alert-ok m-0">{styleNote}</p>{/if}
            {/if}
            </section>

            <!-- THE INTEGRATIONS CARD (2026-11-12, user order): last in the
                 bento, 1x1. A rack of squares, two per row — GitHub is the
                 first; a live logo wears the primary colour. -->
            <section class="mg flex flex-col gap-3 lg:col-span-1">
              <h4 class="mg-h">Integrations</h4>
              <div class="grid grid-cols-2 gap-2">
                <button
                  class="flex flex-col items-center justify-center gap-2 aspect-square border border-line bg-batter/40 hover:bg-batter/70 transition-colors"
                  title={github?.connected ? `GitHub — connected as ${github.login}` : 'GitHub — connect an account'}
                  onclick={() => { githubModal = true; githubTested = null; githubError = ''; }}
                >
                  <svg
                    viewBox="0 0 24 24"
                    class="w-12 h-12 {github?.connected ? 'text-jam' : 'text-ink-soft'}"
                    fill="currentColor"
                    aria-hidden="true"
                  >
                    <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
                  </svg>
                  <span class="text-xs font-bold">GitHub</span>
                </button>
                <!-- the SMTP oven, second in the rack (2026-11-12, user order):
                     invites and password resets ride it; plumbed in, the mark
                     wears the primary colour -->
                <button
                  class="flex flex-col items-center justify-center gap-2 aspect-square border border-line bg-batter/40 hover:bg-batter/70 transition-colors"
                  title={mail?.configured ? `Mail — plumbed in via ${mail.host}` : 'Mail — connect an SMTP server'}
                  onclick={openMailModal}
                >
                  <svg
                    viewBox="0 0 24 24"
                    class="w-12 h-12 {mail?.configured ? 'text-jam' : 'text-ink-soft'}"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    aria-hidden="true"
                  >
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="M3 7l9 6 9-6" />
                  </svg>
                  <span class="text-xs font-bold">Mail</span>
                </button>
              </div>
              {#if github?.connected}
                <p class="m-0 text-xs text-ink-soft">connected as <b>{github.login}</b></p>
              {/if}
              {#if mail?.configured}
                <p class="m-0 text-xs text-ink-soft">plumbed in via <b>{mail.host}</b></p>
              {/if}
              {#if githubError}<p class="alert alert-err m-0" role="alert">{githubError}</p>{/if}
              {#if githubNote}<p class="alert alert-ok m-0">{githubNote}</p>{/if}
            </section>
          </div>
        {:else}
          <p class="italic text-ink-soft">Reaching for the settings…</p>
        {/if}
      {:else if tab === 'users' && role === 'admin'}
        <section class="mg mb-6">
          <h4 class="mg-h">Accounts</h4>
          <ul class="list-none p-0 m-0 flex flex-col gap-1">
            {#each accounts as account (account.user)}
              <li class="flex items-center justify-between gap-2 text-sm">
                <span>{account.user} · {account.role}{#if account.email} · {account.email}{/if}</span>
                {#if account.user !== user}
                  <span class="flex gap-2">
                    {#if account.email}
                      <button class="btn-ghost" title="Mail freshly issued credentials" onclick={() => inviteNow(account.user)}>✉</button>
                    {/if}
                    <button class="btn-ghost danger" aria-label={`Remove account ${account.user}`} onclick={() => deleteAccountNow(account.user)}>×</button>
                  </span>
                {/if}
              </li>
            {/each}
          </ul>
          {#if accountError}<p class="alert alert-err" role="alert">{accountError}</p>{/if}
          {#if accountNote}<p class="alert alert-ok">{accountNote}</p>{/if}
        </section>
        <form class="mg flex flex-col gap-3 max-w-md" onsubmit={addAccountNow}>
          <h4 class="mg-h">Add account</h4>
          <label class="field"><span>Username</span><input class="input" bind:value={accountAddUser} required /></label>
          <label class="field"><span>Password</span><input class="input" type="password" bind:value={accountAddPassword} placeholder="min 8" minlength="8" required /></label>
          <label class="field"><span>Email <span class="hint">(for invites)</span></span><input class="input" type="email" bind:value={accountAddEmail} /></label>
          <label class="field"><span>Role</span>
            <select class="input" bind:value={accountAddRole}>
              <option value="editor">editor</option>
              <option value="admin">admin</option>
            </select>
          </label>
          <button type="submit" class="btn btn-primary self-start">Add account</button>
        </form>
      {:else if tab === 'plugins' && role === 'admin'}
        <!-- THE 50/50 SPLIT (2026-11-11, user order): LEFT = the chosen plugin
             (preview + title/version, description, tags, buttons, then the
             documentation / dependencies / settings detail tabs); RIGHT = every
             plugin you could pick — installed AND the Vitrine shelf, one list. -->
        <div class="grid grid-cols-1 lg:grid-cols-2 grid-rows-1 gap-4 h-full min-h-0">
          <section class="mg flex flex-col gap-3 min-h-0">
            {#if selectedPluginRow}
              <div class="flex-none flex gap-3 items-start">
                {#if selectedPluginRow.preview && !brokenPreviews[selectedPluginRow.name]}
                  <img
                    src={selectedPluginRow.preview}
                    alt="Preview of {selectedPluginRow.title}"
                    class="w-20 h-20 object-cover border border-line"
                    onerror={() => (brokenPreviews = { ...brokenPreviews, [selectedPluginRow.name]: true })}
                  />
                {:else}
                  <img src={houseShot(selectedPluginRow.name)} alt="Preview of {selectedPluginRow.title}" class="w-20 h-20 object-cover border border-line" />
                {/if}
                <div class="min-w-0">
                  <p class="m-0 font-bold">
                    {selectedPluginRow.title} <span class="text-xs text-ink-soft">v{selectedPluginRow.version}</span>
                    {#if selectedPluginRow.error}<span class="badge">broken</span>{/if}
                    {#if selectedPluginRow.state === 'update'}<span class="badge ml-2">update</span>{/if}
                    {#if selectedPluginRow.installed && selectedPluginRow.enabled}<span class="badge badge-live ml-2">enabled</span>{/if}
                  </p>
                  {#if selectedPluginRow.description}<p class="m-0 text-sm text-ink-soft leading-snug line-clamp-5">{selectedPluginRow.description}</p>{/if}
                </div>
              </div>
              {#if selectedPluginRow.tags.length > 0}
                <p class="m-0 text-xs text-ink-soft">
                  {#each selectedPluginRow.tags as tag (tag)}<span class="tag mr-1">{tag}</span>{/each}
                </p>
              {/if}
              <div class="flex-none flex items-center gap-2">
                {#if selectedPluginRow.installed && !selectedPluginRow.error}
                  <button
                    class="btn {selectedPluginRow.enabled ? 'btn-secondary' : 'btn-primary'}"
                    disabled={pluginsBusy}
                    onclick={() => selectedPluginRow.plugin && togglePlugin(selectedPluginRow.plugin)}
                  >
                    {#if pluginsBusy}…{:else if selectedPluginRow.enabled}Disable{:else}Enable{/if}
                  </button>
                {:else if !selectedPluginRow.installed && selectedPluginRow.ware}
                  <button
                    class="btn btn-primary"
                    disabled={vitrineBusy || selectedPluginRow.missing.length > 0}
                    onclick={() => selectedPluginRow.ware && installWareByName(selectedPluginRow.ware)}
                  >
                    {#if vitrineBusy}…{:else}Install{/if}
                  </button>
                {:else if selectedPluginRow.state === 'update'}
                  <span class="text-xs text-ink-soft">update waiting — see Updates</span>
                {/if}
                {#if selectedPluginRow.installed}
                  <button
                    class="btn-ghost danger"
                    title="Delete plugin"
                    disabled={pluginsBusy}
                    onclick={() => selectedPluginRow.plugin && deletePluginNow(selectedPluginRow.plugin)}
                  >×</button>
                {/if}
              </div>
              {#if selectedPluginRow.error}
                <p class="m-0 text-sm text-error">{selectedPluginRow.error}</p>
              {/if}
              {#if pluginsError && !selectedPluginRow.error}<p class="alert alert-err m-0" role="alert">{pluginsError}</p>{/if}
              <div class="flex-none flex flex-col gap-2 border-t border-line pt-3">
                <div class="flex gap-2">
                  {#each ['documentation', 'dependencies', 'settings'] as detail (detail)}
                    <button
                      class="btn {pluginDetailTab === detail ? 'btn-primary' : 'btn-secondary'}"
                      onclick={() => (pluginDetailTab = detail as typeof pluginDetailTab)}
                    >{detail}</button>
                  {/each}
                </div>
                {#if pluginDetailTab === 'documentation'}
                  {#if selectedPluginRow.documentation}
                    <p class="m-0 text-sm text-ink-soft whitespace-pre-line">{selectedPluginRow.documentation}</p>
                  {:else}
                    <p class="m-0 italic text-ink-soft">The plugin ships no documentation.</p>
                  {/if}
                {:else if pluginDetailTab === 'dependencies'}
                  {#if selectedPluginRow.requires.length === 0}
                    <p class="m-0 italic text-ink-soft">No dependencies — the plugin stands alone.</p>
                  {:else if !selectedPluginRow.installed && selectedPluginRow.missing.length > 0}
                    <p class="m-0 text-xs text-error">
                      requires {selectedPluginRow.requires.join(', ')} — missing: {selectedPluginRow.missing.join(', ')}. The dependency must be installed first.
                    </p>
                  {:else if !selectedPluginRow.installed}
                    <p class="m-0 text-xs text-ink-soft">requires: {selectedPluginRow.requires.join(', ')} (all on this host ✓)</p>
                  {:else}
                    <ul class="list-none p-0 m-0 flex flex-col gap-1">
                      {#each selectedPluginRow.requires as dep (dep)}
                        <li class="text-sm {plugins.some((p) => p.name === dep && p.enabled) ? 'text-ink-soft' : 'text-error'}">
                          {dep} {plugins.some((p) => p.name === dep && p.enabled) ? '✓ enabled' : '— sleeping'}
                        </li>
                      {/each}
                    </ul>
                  {/if}
                {:else if !selectedPluginRow.installed || selectedPluginRow.error}
                  <p class="m-0 italic text-ink-soft">Install the plugin (enabled, unbroken) to set it up.</p>
                {:else}
                  <div class="flex flex-col gap-3">
                    {#if selectedPluginRow.plugin?.panel}
                      {@const panel = panelData}
                      <div class="flex flex-col gap-2">
                        {#if panel?.title}
                          <h4 class="m-0 text-sm font-semibold">{panel.title}</h4>
                        {/if}
                        {#if panel?.note}
                          <p class="m-0 text-xs text-ink-soft">{panel.note}</p>
                        {/if}
                        {#if panel?.status}
                          <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm font-semibold">{panel.status.label}</span>
                            <span class="badge">{panel.status.value}</span>
                          </div>
                        {/if}
                        {#if (panel?.actions?.length ?? 0) > 0}
                          <div class="flex items-center gap-2 flex-wrap">
                            {#each panel?.actions ?? [] as a, ai (ai)}
                              {#if a.modal}
                                {@const modal = a.modal}
                                <button
                                  class="btn btn-secondary"
                                  disabled={panelBusy}
                                  onclick={() => {
                                    panelValue = '';
                                    panelModal = {
                                      id: a.id,
                                      label: modal.label,
                                      type: modal.type ?? 'text',
                                      placeholder: modal.placeholder ?? ''
                                    };
                                  }}
                                >{a.label}</button>
                              {:else}
                                <button
                                  class="btn btn-secondary"
                                  disabled={panelBusy}
                                  onclick={() => panelAction(a)}
                                >{a.label}</button>
                              {/if}
                            {/each}
                          </div>
                        {/if}

                        {#if panelShow}
                          <div class="border border-line p-2 flex flex-col gap-1">
                            <p class="m-0 text-xs text-ink-soft">{panelShow.label}</p>
                            <code class="text-xs break-all select-all">{panelShow.value}</code>
                            <div class="flex gap-2">
                              <button class="btn btn-secondary" onclick={copyPanelShow}>Copy</button>
                              <button class="btn-ghost" onclick={() => (panelShow = null)}>Dismiss</button>
                            </div>
                          </div>
                        {/if}

                        {#if panel?.list}
                          <div class="flex items-center gap-2 text-xs uppercase tracking-wide text-ink-soft">
                            <span class="h-px flex-1 bg-line"></span>
                            <span>{panel.list.label ?? ''}</span>
                            <span class="h-px flex-1 bg-line"></span>
                          </div>
                          {#if (panel.list.rows?.length ?? 0) === 0}
                            <p class="m-0 italic text-ink-soft">Nothing here yet.</p>
                          {:else}
                            <ul class="list-none p-0 m-0 flex flex-col gap-1">
                              {#each panel.list.rows ?? [] as row, i (row.id ?? i)}
                                <li class="flex items-center gap-2 text-sm flex-wrap">
                                  {#each panel.list.columns ?? [] as col (col.key)}
                                    <span>{row.cells?.[col.key] ?? ''}</span>
                                  {/each}
                                  {#if (row.actions?.length ?? 0) > 0}
                                    <span class="flex gap-1 ml-auto">
                                      {#each row.actions ?? [] as ra, ri (ri)}
                                        <button
                                          class="btn-ghost"
                                          disabled={panelBusy}
                                          onclick={() => panelAction(ra, row.id)}
                                        >{ra.label}</button>
                                      {/each}
                                    </span>
                                  {/if}
                                </li>
                              {/each}
                            </ul>
                          {/if}
                        {/if}
                        {#if panelNote}<p class="m-0 text-xs text-ink-soft">{panelNote}</p>{/if}
                      </div>
                    {/if}

                    {#if (selectedPluginRow.plugin?.settings?.length ?? 0) > 0}
                      <form
                        class="flex flex-col gap-2"
                        onsubmit={(e) => {
                          e.preventDefault();
                          savePluginSettingsNow();
                        }}
                      >
                        {#each selectedPluginRow.plugin!.settings as s (s.key)}
                          {#if s.type === 'toggle'}
                            <label class="flex items-center gap-2 text-sm">
                              <input
                                type="checkbox"
                                checked={pluginSettingsDraft[s.key] === true}
                                onchange={(e) => (pluginSettingsDraft[s.key] = e.currentTarget.checked)}
                              />
                              <span>{s.label}</span>
                            </label>
                          {:else}
                            <label class="flex flex-col gap-1 text-sm">
                              <span>{s.label}</span>
                              <span class="flex gap-1">
                                <input
                                  class="input flex-1"
                                  type={s.type === 'secret' && !revealedSecrets[s.key] ? 'password' : 'text'}
                                  bind:value={pluginSettingsDraft[s.key]}
                                  autocomplete="off"
                                />
                                {#if s.type === 'secret'}
                                  <button
                                    type="button"
                                    class="btn-ghost"
                                    title={revealedSecrets[s.key] ? 'Hide' : 'Show'}
                                    onclick={() => (revealedSecrets[s.key] = !revealedSecrets[s.key])}
                                  >{revealedSecrets[s.key] ? 'hide' : 'show'}</button>
                                {/if}
                              </span>
                            </label>
                          {/if}
                        {/each}
                        <div class="flex items-center gap-2">
                          <button class="btn btn-primary" type="submit" disabled={settingsBusy}>
                            {settingsBusy ? 'Saving…' : 'Save settings'}
                          </button>
                          {#if settingsNote}<span class="text-xs text-ink-soft">{settingsNote}</span>{/if}
                        </div>
                      </form>
                    {:else if !selectedPluginRow.plugin?.panel}
                      <p class="m-0 italic text-ink-soft">This plugin declares no settings.</p>
                    {/if}
                  </div>
                {/if}
              </div>
            {:else}
              <p class="italic text-ink-soft">No plugins on the shelf yet.</p>
            {/if}
          </section>
          <section class="mg flex flex-col gap-2 min-h-0">
            {#if vitrineDark}
              <p class="alert alert-warn">{vitrineDark}</p>
            {/if}
            {#if pluginsBusy && plugins.length === 0}
              <p class="italic text-ink-soft">Opening the pantry…</p>
            {:else if pluginsError && plugins.length === 0}
              <p class="alert alert-err" role="alert">{pluginsError}</p>
            {:else}
              {#snippet pluginRow(row: PluginRow)}
                <!-- a11y: keyboard activation of the card is unwired (X = the one door law); the row's actions stay buttons -->
                <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_noninteractive_element_interactions -->
                <li class="card card-shelf h-36 cursor-pointer" onclick={() => (selectedPlugin = row.name)} title="Show {row.title} in the pantry">
                  {#if row.preview && !brokenPreviews[row.name]}
                    <img
                      src={row.preview}
                      alt=""
                      class="shelf-shot"
                      loading="lazy"
                      onerror={() => (brokenPreviews = { ...brokenPreviews, [row.name]: true })}
                    />
                  {:else}
                    <img src={houseShot(row.name)} alt="" class="shelf-shot" loading="lazy" />
                  {/if}
                  <div class="flex-1 min-w-0 py-2 pr-3 flex flex-col gap-1 overflow-hidden">
                    <div class="flex items-start justify-between gap-2">
                      <span class="block font-bold truncate {row.name === selectedPluginRow?.name ? 'text-jam' : ''}">
                        {row.title} <span class="text-xs text-ink-soft">v{row.version}</span>
                        {#if row.error}<span class="badge">broken</span>{/if}
                      </span>
                      {#if row.installed}
                        <button
                          class="btn-ghost danger shrink-0"
                          title="Delete plugin"
                          disabled={pluginsBusy}
                          onclick={(e) => { e.stopPropagation(); row.plugin && deletePluginNow(row.plugin); }}
                        >×</button>
                      {/if}
                    </div>
                    {#if row.description}
                      <p class="m-0 text-sm text-ink-soft leading-snug line-clamp-3">{row.description}</p>
                    {/if}
                    <div class="flex items-center gap-2 mt-auto">
                      {#if row.installed && !row.error}
                        <button
                          class="btn {row.enabled ? 'btn-secondary' : 'btn-primary'} shrink-0"
                          disabled={pluginsBusy}
                          onclick={(e) => { e.stopPropagation(); row.plugin && togglePlugin(row.plugin); }}
                        >
                          {#if pluginsBusy}…{:else if row.enabled}Disable{:else}Enable{/if}
                        </button>
                      {:else if !row.installed && row.ware}
                        <button
                          class="btn btn-primary shrink-0"
                          disabled={vitrineBusy || row.missing.length > 0}
                          onclick={(e) => { e.stopPropagation(); row.ware && installWareByName(row.ware); }}
                        >
                          {#if vitrineBusy}…{:else}Install{/if}
                        </button>
                      {:else if row.state === 'update'}
                        <span class="text-xs text-ink-soft shrink-0">update waiting — see Updates</span>
                      {/if}
                      <span class="flex-1 min-w-0 truncate text-right">
                        {#each row.tags as tag (tag)}
                          <span class="tag mr-1">{tag}</span>
                        {/each}
                      </span>
                    </div>
                  </div>
                </li>
              {/snippet}
              <div class="flex-1 min-h-0 overflow-y-auto flex flex-col gap-2">
                <ul class="list-none p-0 m-0 flex flex-col gap-2">
                  {#each installedPluginRows as row (row.name)}
                    {@render pluginRow(row)}
                  {/each}
                </ul>
                {#if shelfPluginRows.length > 0}
                  {@render sectionLine('plugins-vitrine', 'Vitrine')}
                  {#if openSections['plugins-vitrine']}
                    <ul class="list-none p-0 m-0 flex flex-col gap-2">
                      {#each shelfPluginRows as row (row.name)}
                        {@render pluginRow(row)}
                      {/each}
                    </ul>
                  {/if}
                {/if}
                {@render githubShelf('plugin')}
              </div>
            {/if}
            {#if vitrineError && role === 'admin'}<p class="alert alert-err m-0" role="alert">{vitrineError}</p>{/if}
          </section>
        </div>
      {:else if tab === 'templates' && role === 'admin'}
        <!-- THE 50/50 SPLIT (2026-11-10, user order): LEFT = the fitting room
             (the preview) with the theme's details at its bottom; RIGHT = every
             theme you could pick — installed AND the Vitrine shelf, one list. -->
        <div class="grid grid-cols-1 lg:grid-cols-2 grid-rows-1 gap-4 h-full min-h-0">
          <section class="mg flex flex-col gap-3 min-h-0">
            {#if selectedRow}
              <div class="flex-1 min-h-0 grid grid-rows-1 place-items-center">
                {#if selectedRow.preview && !brokenPreviews[selectedRow.name]}
                  <img
                    src={selectedRow.preview}
                    alt="Preview of {selectedRow.title}"
                    class="w-full h-full object-cover"
                    onerror={() => (brokenPreviews = { ...brokenPreviews, [selectedRow.name]: true })}
                  />
                {:else}
                  <img src={houseShot(selectedRow.name)} alt="Preview of {selectedRow.title}" class="w-full h-full object-cover" />
                {/if}
              </div>
              <div class="flex-none flex flex-col gap-2 border-t border-line pt-3">
                <p class="m-0 font-bold">
                  {selectedRow.title} <span class="text-xs text-ink-soft">v{selectedRow.version}</span>
                  {#if selectedRow.active}<span class="badge badge-live ml-2">active</span>{/if}
                  {#if selectedRow.houseDefault}<span class="badge ml-2">house default</span>{/if}
                </p>
                {#if selectedRow.description}<p class="m-0 text-sm text-ink-soft">{selectedRow.description}</p>{/if}
                {#if selectedRow.styles.length > 0}
                  <p class="m-0 text-xs text-ink-soft">
                    {#each selectedRow.styles as style (style)}<span class="tag mr-1">{style}</span>{/each}
                  </p>
                {/if}
                {#if selectedRow.missing.length > 0}
                  <p class="m-0 text-xs text-error">
                    requires {selectedRow.requires.join(', ')} — missing: {selectedRow.missing.join(', ')}. The dependency must be installed first.
                  </p>
                {/if}
                <div class="flex items-center gap-2">
                  {#if selectedRow.installed}
                    {#if selectedRow.active}
                      <span class="btn btn-secondary btn-static">active</span>
                    {:else}
                      <button
                        class="btn btn-primary"
                        disabled={templatesBusy}
                        onclick={() => selectedRow.template && activateTemplateNow(selectedRow.template)}
                      >
                        {#if templatesBusy}…{:else}Activate{/if}
                      </button>
                      {#if !selectedRow.houseDefault}
                        <button
                          class="btn-ghost danger"
                          title="Delete template"
                          disabled={templatesBusy}
                          onclick={() => selectedRow.template && deleteTemplateNow(selectedRow.template)}
                        >×</button>
                      {/if}
                      {#if previewSlug !== ''}
                        <a
                          class="btn btn-secondary"
                          href={`/bridge/preview/${selectedRow.name}/pages/${previewSlug}`}
                          target="_blank"
                          rel="noopener"
                          title="Open the fitting room in a new tab"
                        >Preview ↗</a>
                      {/if}
                    {/if}
                  {:else if selectedRow.ware}
                    <button
                      class="btn btn-primary"
                      disabled={vitrineBusy || selectedRow.missing.length > 0}
                      onclick={() => selectedRow.ware && installWareByName(selectedRow.ware)}
                    >
                      {#if vitrineBusy}…{:else}Install{/if}
                    </button>
                  {:else if selectedRow.state === 'update'}
                    <span class="text-xs text-ink-soft">update waiting — see Updates</span>
                  {/if}
                </div>
                {#if templatesError}<p class="alert alert-err m-0" role="alert">{templatesError}</p>{/if}
                {#if templatesNote}<p class="alert alert-ok m-0">{templatesNote}</p>{/if}
              </div>
            {:else}
              <p class="italic text-ink-soft">No themes on the shelf yet.</p>
            {/if}
          </section>
          <section class="mg flex flex-col gap-2 min-h-0">
            {#if vitrineDark}
              <p class="alert alert-warn">{vitrineDark}</p>
            {/if}
            {#if templatesBusy && templates.length === 0}
              <p class="italic text-ink-soft">Reading the bake-shop wall…</p>
            {:else if templatesError && templates.length === 0}
              <p class="alert alert-err" role="alert">{templatesError}</p>
            {:else}
              {#snippet themeRow(row: ThemeRow)}
                <!-- a11y: keyboard activation of the card is unwired (X = the one door law); the row's actions stay buttons -->
                <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_noninteractive_element_interactions -->
                <li class="card card-shelf h-36 cursor-pointer" onclick={() => (selectedTheme = row.name)} title="Show {row.title} in the fitting room">
                  {#if row.preview && !brokenPreviews[row.name]}
                    <img
                      src={row.preview}
                      alt=""
                      class="shelf-shot"
                      loading="lazy"
                      onerror={() => (brokenPreviews = { ...brokenPreviews, [row.name]: true })}
                    />
                  {:else}
                    <img src={houseShot(row.name)} alt="" class="shelf-shot" loading="lazy" />
                  {/if}
                  <div class="flex-1 min-w-0 py-2 pr-3 flex flex-col gap-1 overflow-hidden">
                    <div class="flex items-start justify-between gap-2">
                      <span class="block font-bold truncate {row.name === selectedRow?.name ? 'text-jam' : ''}">
                        {row.title} <span class="text-xs text-ink-soft">v{row.version}</span>
                      </span>
                      {#if row.installed && !row.active && !row.houseDefault}
                        <button
                          class="btn-ghost danger shrink-0"
                          title="Delete template"
                          disabled={templatesBusy}
                          onclick={(e) => { e.stopPropagation(); row.template && deleteTemplateNow(row.template); }}
                        >×</button>
                      {/if}
                    </div>
                    {#if row.description}
                      <p class="m-0 text-sm text-ink-soft leading-snug line-clamp-2">{row.description}</p>
                    {/if}
                    <div class="flex items-center gap-2 mt-auto">
                      {#if row.active}
                        <span class="btn btn-secondary btn-static shrink-0">active</span>
                      {:else if row.installed}
                        <button
                          class="btn btn-primary shrink-0"
                          disabled={templatesBusy}
                          onclick={(e) => { e.stopPropagation(); row.template && activateTemplateNow(row.template); }}
                        >
                          {#if templatesBusy}…{:else}Activate{/if}
                        </button>
                      {:else if row.ware}
                        <button
                          class="btn btn-primary shrink-0"
                          disabled={vitrineBusy || row.missing.length > 0}
                          onclick={(e) => { e.stopPropagation(); row.ware && installWareByName(row.ware); }}
                        >
                          {#if vitrineBusy}…{:else}Install{/if}
                        </button>
                      {:else if row.state === 'update'}
                        <span class="text-xs text-ink-soft shrink-0">update waiting — see Updates</span>
                      {/if}
                      <span class="flex-1 min-w-0 truncate text-right">
                        {#each row.styles as style (style)}
                          <span class="tag mr-1">{style}</span>
                        {/each}
                      </span>
                    </div>
                  </div>
                </li>
              {/snippet}
              <div class="flex-1 min-h-0 overflow-y-auto flex flex-col gap-2">
                <ul class="list-none p-0 m-0 flex flex-col gap-2">
                  {#each installedRows as row (row.name)}
                    {@render themeRow(row)}
                  {/each}
                </ul>
                {#if shelfRows.length > 0}
                  {@render sectionLine('templates-vitrine', 'Vitrine')}
                  {#if openSections['templates-vitrine']}
                    <ul class="list-none p-0 m-0 flex flex-col gap-2">
                      {#each shelfRows as row (row.name)}
                        {@render themeRow(row)}
                      {/each}
                    </ul>
                  {/if}
                {/if}
                {@render githubShelf('template')}
              </div>
            {/if}
            {#if vitrineError && role === 'admin'}<p class="alert alert-err m-0" role="alert">{vitrineError}</p>{/if}
            {#if vitrineNote}<p class="alert alert-ok m-0">{vitrineNote}</p>{/if}
          </section>
        </div>
      {:else if tab === 'updates' && role === 'admin'}
        <section class="mg flex flex-col gap-2">
          <h4 class="mg-h">Updates</h4>
          {#if vitrineDark}
            <p class="alert alert-warn">{vitrineDark}</p>
          {/if}
          {#if updatesAvailable}
            <div class="flex items-center justify-between gap-3">
              <p class="m-0 text-sm text-ink-soft">
                {#if coreUpdateAvailable}Core{/if}{#if coreUpdateAvailable && (templateUpdates.length > 0 || pluginUpdates.length > 0)} · {/if}{#if templateUpdates.length > 0}{templateUpdates.length} template{templateUpdates.length === 1 ? '' : 's'}{/if}{#if templateUpdates.length > 0 && pluginUpdates.length > 0} · {/if}{#if pluginUpdates.length > 0}{pluginUpdates.length} plugin{pluginUpdates.length === 1 ? '' : 's'}{/if}
                are waiting — the house order is core, then templates, then plugins.
              </p>
              <button class="btn btn-primary shrink-0" disabled={updatesBusy} onclick={() => updateAllNow()}>
                {#if updatesBusy}Under the knives…{:else}Update all{/if}
              </button>
            </div>
          {/if}
          {#if core !== null && (coreUpdateAvailable || core.localVersion)}
            <div class="card flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="m-0 font-bold">
                  Schmarrn core <span class="text-xs text-ink-soft">
                    {#if coreUpdateAvailable && core.latest !== null && core.latest !== core.current}v{core.current} → v{core.latest}{:else if coreUpdateAvailable}v{core.current} — the dashboard lags the floor: repair ride{:else if core.localVersion}v{core.current} — packet v{core.localVersion} parked{:else}v{core.current}{/if}
                  </span>
                </p>
                <p class="m-0 text-xs text-ink-soft">Core files only — plugins, templates, dressed pagetypes and your settings never move. Visitors see a blank page while the knife is out.</p>
              </div>
              {#if coreUpdateAvailable}
                <button class="btn btn-secondary shrink-0" disabled={updatesBusy || coreBusy} onclick={() => updateCoreNow('hub')}>
                  {#if updatesBusy || coreBusy}…{:else if core.latest !== null && core.latest !== core.current}Core update → {core.latest}{:else}Repair the dashboard{/if}
                </button>
              {/if}
              {#if core.localVersion}
                <button class="btn btn-secondary shrink-0" disabled={updatesBusy || coreBusy} onclick={() => updateCoreNow('local')}>
                  {#if updatesBusy || coreBusy}…{:else}Locally → v{core.localVersion}{/if}
                </button>
              {/if}
            </div>
          {/if}
          {#each templateUpdates as ware (ware.name)}
            <div class="card flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="m-0 font-bold">{ware.title} <span class="text-xs text-ink-soft">v{ware.installedVersion} → v{ware.version}</span></p>
                <p class="m-0 text-xs text-ink-soft">template</p>
              </div>
              <button class="btn btn-secondary shrink-0" disabled={updatesBusy} onclick={() => updateWareNow(ware)}>
                {#if updatesBusy}…{:else}Update{/if}
              </button>
            </div>
          {/each}
          {#each pluginUpdates as ware (ware.name)}
            <div class="card flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="m-0 font-bold">{ware.title} <span class="text-xs text-ink-soft">v{ware.installedVersion} → v{ware.version}</span></p>
                <p class="m-0 text-xs text-ink-soft">plugin</p>
              </div>
              <button class="btn btn-secondary shrink-0" disabled={updatesBusy} onclick={() => updateWareNow(ware)}>
                {#if updatesBusy}…{:else}Update{/if}
              </button>
            </div>
          {/each}
          {#if !updatesAvailable && core !== null && !core.localVersion}
            <p class="m-0 italic text-ink-soft">Nothing to update — every shelf is current.</p>
          {/if}
          <label class="btn btn-secondary self-start cursor-pointer">
            <input class="hidden" type="file" accept=".zip" onchange={parkCorePacketNow} disabled={coreBusy || updatesBusy} />
            {#if coreBusy}Parking…{:else}Park a local update packet (.zip){/if}
          </label>
          <p class="m-0 text-xs text-ink-soft">
            No hub answering? Download the release zip on any machine, park it here, and ride the knife from the shelf — an equal version counts as a repair.
          </p>
          {#if coreError}<p class="alert alert-err" role="alert">{coreError}</p>{/if}
          {#if coreNote}<p class="alert alert-ok">{coreNote}</p>{/if}
          {#if updatesError}<p class="alert alert-err" role="alert">{updatesError}</p>{/if}
          {#if updatesNote}<p class="alert alert-ok">{updatesNote}</p>{/if}
        </section>
      {/if}
    </div>
  </div>
{/if}

{#if confirmModal}
  <!-- the house confirm: no system popups — every "are you sure" wears the house pattern -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label={confirmModal.title} tabindex="-1" onclick={() => (confirmModal = null)}>
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>{confirmModal.title}</h3>
        <button type="button" class="close-x" onclick={() => (confirmModal = null)}>×</button>
      </header>
      <p class="m-0 text-sm">{confirmModal.message}</p>
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (confirmModal = null)}>Cancel</button>
        <button type="button" class="btn btn-primary" onclick={confirmNow}>Confirm</button>
      </footer>
    </div>
  </div>
{/if}

{#if forgotModal}
  <!-- the forgot-password door: two steps — ask for a token (enumeration-proof:
       the answer never says whether the account exists), then spend it -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Forgot password" tabindex="-1" onclick={() => (forgotModal = false)}>
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Forgot password</h3>
        <button type="button" class="close-x" onclick={() => (forgotModal = false)}>×</button>
      </header>
      {#if forgotDone}
        <p class="alert alert-ok m-0">The lock is turned — log in with the new password.</p>
        <footer class="modal-foot">
          <button type="button" class="btn btn-primary" onclick={() => (forgotModal = false)}>Back to login</button>
        </footer>
      {:else if forgotStep === 1}
        <label class="field"><span>Account name</span><input class="input" bind:value={forgotUser} autocomplete="username" required /></label>
        <p class="m-0 text-xs text-ink-soft">
          If the account exists, has an email on file and the SMTP oven is plumbed in, a one-time reset token rides the mail — valid two hours.
        </p>
        {#if forgotError}<p class="alert alert-err m-0" role="alert">{forgotError}</p>{/if}
        <footer class="modal-foot">
          <button type="button" class="btn btn-secondary" onclick={() => (forgotModal = false)}>Cancel</button>
          <button type="button" class="btn btn-primary" onclick={requestResetNow} disabled={forgotBusy || forgotUser.trim() === ''}>
            {forgotBusy ? 'Sending…' : 'Send token'}
          </button>
        </footer>
      {:else}
        <p class="m-0 text-xs text-ink-soft">{forgotNoteFromServer}</p>
        <label class="field"><span>Reset token <span class="hint">(from the mail)</span></span><input class="input" bind:value={forgotToken} autocomplete="off" /></label>
        <label class="field"><span>New password</span><input class="input" type="password" bind:value={forgotPass} placeholder="min 8" minlength="8" autocomplete="new-password" required /></label>
        {#if forgotError}<p class="alert alert-err m-0" role="alert">{forgotError}</p>{/if}
        <footer class="modal-foot">
          <button type="button" class="btn btn-secondary" onclick={() => (forgotModal = false)}>Cancel</button>
          <button type="button" class="btn btn-primary" onclick={confirmResetNow} disabled={forgotBusy || forgotToken.trim() === '' || forgotPass.length < 8}>
            {forgotBusy ? 'Turning…' : 'Set password'}
          </button>
        </footer>
      {/if}
    </div>
  </div>
{/if}

{#if mailModal}
  <!-- the SMTP oven: the line is dialed with CANDIDATE credentials first —
       nothing is written until the test answers green -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Mail connection" tabindex="-1" onclick={() => (mailModal = false)}>
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Mail (SMTP)</h3>
        <button type="button" class="close-x" onclick={() => (mailModal = false)}>×</button>
      </header>
      {#if mail?.configured}
        <p class="m-0 text-xs text-ink-soft">
          Plumbed in via <b>{mail.host}</b>:<b>{mail.port}</b> ({mail.secure}), sending as <b>{mail.fromEmail || mail.host}</b>. The password is never shown again — reconnect to change it.
        </p>
      {/if}
      <label class="field"><span>SMTP host</span><input class="input" bind:value={mailHost} placeholder="mail.example.com" /></label>
      <div class="flex gap-2">
        <label class="field flex-1"><span>Port</span><input class="input" type="number" bind:value={mailPort} min="1" max="65535" /></label>
        <label class="field flex-1"><span>Encryption</span>
          <select class="input" bind:value={mailSecure}>
            <option value="tls">STARTTLS (port 587)</option>
            <option value="ssl">Implicit SSL (port 465)</option>
            <option value="none">None (port 25)</option>
          </select>
        </label>
      </div>
      <label class="field"><span>Username <span class="hint">(empty = no auth)</span></span><input class="input" bind:value={mailUser} autocomplete="off" /></label>
      <label class="field"><span>Password</span><input class="input" type="password" bind:value={mailPass} autocomplete="new-password" /></label>
      <div class="flex gap-2">
        <label class="field flex-1"><span>From address</span><input class="input" type="email" bind:value={mailFromEmail} placeholder="schmarrn@example.com" /></label>
        <label class="field flex-1"><span>From name</span><input class="input" bind:value={mailFromName} /></label>
      </div>
      <p class="m-0 text-xs text-ink-soft">
        This oven carries the house's letters — account invites and password resets. The password is sealed into the house vault (encrypted at rest, master key in a 0600 keyfile) and never shown again.
      </p>
      {#if mailTested?.ok}
        <p class="alert alert-ok m-0">The line answers and the key turns — the oven is reachable.</p>
      {/if}
      {#if mailError}<p class="alert alert-err m-0" role="alert">{mailError}</p>{/if}
      <footer class="modal-foot">
        {#if mail?.configured}
          <button type="button" class="btn btn-secondary" onclick={disconnectMailNow} disabled={mailBusy}>
            {mailBusy ? '…' : 'Disconnect'}
          </button>
        {:else}
          <button type="button" class="btn btn-secondary" onclick={testMailNow} disabled={mailBusy || mailHost.trim() === ''}>
            {mailBusy ? 'Dialing…' : 'Test connection'}
          </button>
          <button type="button" class="btn btn-primary" onclick={connectMailNow} disabled={mailBusy || !mailTested?.ok}>
            {mailBusy ? 'Plumbing…' : 'Connect'}
          </button>
        {/if}
      </footer>
    </div>
  </div>
{/if}

{#if githubModal}
  <!-- the dark closes the modal (house law, 2026-11-09) -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="GitHub connection" tabindex="-1" onclick={() => (githubModal = false)}>
    <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions, a11y_no_noninteractive_element_interactions -->
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>GitHub</h3>
        <button type="button" class="close-x" onclick={() => (githubModal = false)}>×</button>
      </header>
      <label class="field">
        <span>Personal Access Token</span>
        <input
          class="input"
          type="password"
          bind:value={githubToken}
          placeholder="ghp_… or github_pat_…"
          autocomplete="off"
        />
      </label>
      <p class="m-0 text-xs text-ink-soft">
        A token with repo read scope. Public repos work without one — the token only widens the reach to this account's private repos. It rests sealed in the house vault (encrypted at rest), never shown again.
      </p>
      {#if githubTested?.ok}
        <p class="alert alert-ok m-0">The token answers — it belongs to <b>{githubTested.login}</b>.</p>
      {/if}
      {#if githubError}<p class="alert alert-err m-0" role="alert">{githubError}</p>{/if}
      <footer class="modal-foot">
        {#if github?.connected}
          <button type="button" class="btn btn-secondary" onclick={disconnectGithubNow} disabled={githubBusy}>
            {githubBusy ? '…' : 'Disconnect'}
          </button>
        {:else}
          <button type="button" class="btn btn-secondary" onclick={testGithubNow} disabled={githubBusy || githubToken.trim() === ''}>
            {githubBusy ? 'Asking…' : 'Test connection'}
          </button>
          <button type="button" class="btn btn-primary" onclick={connectGithubNow} disabled={githubBusy || !githubTested?.ok}>
            {githubBusy ? 'Opening…' : 'Connect'}
          </button>
        {/if}
      </footer>
    </div>
  </div>
{/if}

{#if mediaModal}
  <!-- the dark closes the modal (2026-11-09, user order); ESC is gone by the same word — the keyboard path is deliberate absence, not an oversight -->
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Media" tabindex="-1" onclick={() => (mediaModal = false)}>
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="modal-card" style="width: min(60rem, 94vw)" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Media</h3>
        <button type="button" class="close-x" onclick={() => (mediaModal = false)}>×</button>
      </header>
      {#if mediaBusy}
        <p class="italic text-ink-soft">Sweeping the pantry…</p>
      {:else if mediaError}
        <p class="alert alert-err m-0" role="alert">{mediaError}</p>
      {:else if overlayMedia.length === 0}
        <p class="m-0 italic text-ink-soft">No media yet — upload lives inside the image and video modals.</p>
      {:else}
        {#if mediaNote}<p class="alert alert-ok m-0">{mediaNote}</p>{/if}
        <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(10rem,1fr))]">
          {#each overlayMedia as item (item.type + '/' + item.slug + '/' + item.name)}
            <button class="card text-left" title={`Copy URL — ${item.url}`} onclick={() => copyMediaUrl(item)}>
              {#if /\.(png|jpe?g|gif|webp|svg)$/i.test(item.name)}
                <img class="w-full aspect-video object-cover" src={item.url} alt={item.name} loading="lazy" />
              {:else}
                <div class="w-full aspect-video bg-crust grid place-items-center text-xs text-ink-soft">{item.name.split('.').pop()?.toUpperCase()}</div>
              {/if}
              <span class="text-xs font-bold truncate">{item.name}</span>
              <span class="text-xs text-ink-soft truncate">{item.slug}</span>
            </button>
          {/each}
        </div>
      {/if}
    </div>
  </div>
{/if}

{#if uploadModal}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Upload markdown page" tabindex="-1" onclick={() => (uploadModal = null)}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={saveUpload} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Upload — {uploadModal.fileName}</h3>
        <button type="button" class="close-x" onclick={() => (uploadModal = null)}>×</button>
      </header>

      {#if uploadModal.valid}
        <p class="alert alert-ok m-0">Frontmatter checks out. Serving as-is.</p>
      {:else}
        <p class="alert alert-warn m-0">
          The frontmatter needs attention — save will rebuild it from the fields below (body stays untouched):
        </p>
        <ul class="list-disc pl-5 my-0 text-error text-xs">
          {#each uploadModal.issues as issue}
            <li>{issue}</li>
          {/each}
        </ul>
      {/if}

      <label class="field">
        <span>Title</span>
        <input class="input" bind:value={uploadModal.title} required placeholder="Page title" />
      </label>
      <label class="field">
        <span>Slug (URL)</span>
        <input class="input" bind:value={uploadModal.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
          title="lowercase letters, digits, hyphens" />
      </label>

      <fieldset class="border border-dashed border-line p-2 flex flex-col gap-1 text-sm">
        <legend class="text-xs text-ink-soft px-1">Serve this page as</legend>
        <label class="check"><input type="radio" bind:group={uploadModal.asDraft} value={false} /> Published — visible to the public</label>
        <label class="check"><input type="radio" bind:group={uploadModal.asDraft} value={true} /> Draft — editors only until you publish</label>
      </fieldset>

      {#if uploadError}<p class="alert alert-err m-0" role="alert">{uploadError}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (uploadModal = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={uploading}>{uploading ? 'Folding…' : 'Create page'}</button>
      </footer>
    </form>
  </div>
{/if}

{#if pageSettings}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Page settings" tabindex="-1" onclick={() => (pageSettings = null)}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={savePageSettings} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Settings — {pageSettings.type}/{pageSettings.slug}</h3>
        <button type="button" class="close-x" onclick={() => (pageSettings = null)}>×</button>
      </header>

      <label class="field">
        <span>Title</span>
        <input class="input" bind:value={pageSettings.title} required />
      </label>
      <fieldset class="border border-dashed border-line p-2 flex flex-col gap-1 text-sm">
        <legend class="text-xs text-ink-soft px-1">Serve this page as</legend>
        <label class="check"><input type="radio" bind:group={pageSettings.asDraft} value={false} /> Published — visible to the public</label>
        <label class="check"><input type="radio" bind:group={pageSettings.asDraft} value={true} /> Draft — editors only until published</label>
      </fieldset>
      <label class="field">
        <span>Date</span>
        <input class="input" type="date" bind:value={pageSettings.date} />
      </label>
      <label class="field">
        <span>Tags (comma-separated)</span>
        <input class="input" bind:value={pageSettings.tags} />
      </label>
      {#if role === 'admin'}
        <label class="check">
          <input type="checkbox" bind:checked={pageSettings.isHome} />
          Home page <span class="hint">— the front door; the crown moves with this switch</span>
        </label>
      {/if}

      <p class="text-xs text-ink-soft m-0">Save writes the draft under the same conflict guard the editor used; a published page bakes live with the rendered body. Slug changes belong to the ✎ rename, not here.</p>
      {#if pageSettings.error}<p class="alert alert-err m-0" role="alert">{pageSettings.error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (pageSettings = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={pageSettings.saving}>{pageSettings.saving ? 'Baking…' : 'Save + bake'}</button>
      </footer>
    </form>
  </div>
{/if}

{#if newModal}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="New page" tabindex="-1" onclick={() => (newModal = null)}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={saveNew} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>New page</h3>
        <button type="button" class="close-x" onclick={() => (newModal = null)}>×</button>
      </header>

      <label class="field">
        <span>Title</span>
        <input class="input" bind:value={newModal.title} required placeholder="Page title" />
      </label>
      <label class="field">
        <span>Slug (URL)</span>
        <input class="input" bind:value={newModal.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
          title="lowercase letters, digits, hyphens" placeholder="my-tart" />
      </label>
      {#if activeThemeStyles.length > 0}
        <label class="field">
          <span>Style <span class="hint">(the theme's word — {activeTheme?.title})</span></span>
          <select class="input" bind:value={newModal.style}>
            {#each activeThemeStyles as s (s)}
              <option value={s}>{prettyStyle(s)}</option>
            {/each}
          </select>
        </label>
        {#if newModal?.style === 'frontpage'}
          <p class="m-0 text-xs text-ink-soft">The frontpage is the home — creating it moves the crown here.</p>
        {/if}
      {/if}
      <label class="field">
        <span>Fill from a page type</span>
        <select class="input" bind:value={newModal.scaffold} onchange={() => pickScaffold(newModal?.scaffold ?? 'article')}>
          {#each pagetypes as p (p.name)}
            <option value={p.name}>{p.title}</option>
          {/each}
        </select>
      </label>

      <fieldset class="border border-dashed border-line p-2 flex flex-col gap-1 text-sm">
        <legend class="text-xs text-ink-soft px-1">Serve this page as</legend>
        <label class="check"><input type="radio" bind:group={newModal.asDraft} value={false} /> Published — visible to the public</label>
        <label class="check"><input type="radio" bind:group={newModal.asDraft} value={true} /> Draft — editors only until you publish</label>
      </fieldset>

      {#if newError}<p class="alert alert-err m-0" role="alert">{newError}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (newModal = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={creating}>{creating ? 'Folding…' : 'Create page'}</button>
      </footer>
    </form>
  </div>
{/if}
