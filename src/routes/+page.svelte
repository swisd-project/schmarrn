<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen. p4 chrome: no sidebar;
  // a burger opens the full-screen overlay (Contents / Settings / Plugins & Templates / Users).
  import { session, login, logout, pageCount, createPage, deletePage, renamePage, listAccounts, addAccount, removeAccount, inviteAccount, setupStatus, runSetup, getSiteSettings, saveSiteSettings, listAllMedia, uploadSiteAsset, listPlugins, setPlugins, listTemplates, activateTemplate, deleteTemplate, coreStatus, uploadCorePacket, runCoreUpdate, listPagetypes, listWares, installWare, getDraftRaw, putDraftRaw, publishDraft, type PluginRef, type TemplateRef, type PagetypeRef, type WareRef } from '$lib/api';
  import { closeTarget } from '$lib/back';
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
      .then((s) => {
        authenticated = s.authenticated;
        user = s.user;
        role = s.role;
        // Arriving at /_ while authed = the drawer sent you here — the ONE
        // overlay opens itself, pre-selected on the carried ?tab= (editors
        // are pinned to Contents; admins get all four).
        if (s.authenticated) {
          const wanted = new URLSearchParams(window.location.search).get('tab') ?? '';
          const allowed: readonly Tab[] = s.role === 'admin'
            ? ['contents', 'settings', 'plugins', 'templates', 'updates', 'users']
            : ['contents'];
          if ((allowed as readonly string[]).includes(wanted)) showTab(wanted as Tab);
          openMenu();
          // The Updates pill tells the truth from the boot, before any tab is
          // opened — core status + the Vitrine shelf answer for it.
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
  let siteSettings = $state<{ name: string; claim: string; logo: string; theme: string; home: string; style: string } | null>(null);
  let siteBusy = $state(false);
  let siteError = $state('');
  let siteNote = $state('');

  async function loadSiteSettings() {
    if (siteSettings !== null) return;
    try {
      siteSettings = { ...(await getSiteSettings()) };
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
      await saveSiteSettings(siteSettings);
      siteNote = 'Saved. The public face updates on the next serve.';
    } catch (e) {
      siteError = e instanceof Error ? e.message : String(e);
    } finally {
      siteBusy = false;
    }
  }

  /** Logo upload: straight into site assets, the URL lands in the form (save still explicit). */
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
      siteSettings = { ...siteSettings, logo: stored.url };
      siteNote = `Logo uploaded as ${stored.name} — save to put it on the public face.`;
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
  const dirty = $derived(sel !== null && raw !== baseline);
  const selPath = $derived(sel === null ? '' : `${sel.type}/${sel.slug}/index.md`);

  async function openRaw(type: string, slug: string) {
    if (sel?.type === type && sel.slug === slug) return;
    if (dirty && !window.confirm(`Unsaved changes on ${selPath} — leave without saving?`)) return;
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
  let tab = $state<Tab>('contents');

  /** The dialog takes focus when it mounts (auto-open leaves nothing focused —
   *  and without it, keyboard flow starts nowhere). Pure desktop manners. */
  const dialogFocus = (node: HTMLElement) => node.focus();

  function openMenu() {
    menuOpen = true;
    if (role === 'admin') void loadSiteSettings(); // warm the site form while the overlay opens
  }

  function closeMenu() {
    menuOpen = false;
    // THE PAGE comes back — deterministically. The pill opened this overlay by
    // carrying its own page in ?back=; Close walks exactly there. No param
    // (typed URL, the front door's own ride) → the front door. Dev stays inside
    // the SPA — the vite origin's / IS the dashboard. Never history.back(): a
    // restored tab can pass its length check with no real entry to return to
    // (audited 2026-11-04 — back() no-op = stranded on a blank /_).
    if (import.meta.env.DEV) return;
    window.location.href = closeTarget(window.location.search);
  }

  function showTab(next: Tab) {
    tab = next;
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

  /** The shelf's face for one installed template — undefined when the registry is dark. */
  function vitrineTemplateWare(name: string): WareRef | undefined {
    return vitrine.find((w) => w.kind === 'template' && w.name === name);
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

  async function deleteTemplateNow(template: TemplateRef) {
    if (templatesBusy) return;
    if (!confirm(`Delete the ${template.title} template? Pages keep working — the site falls back to a house theme until another is activated.`)) return;
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
  let core = $state<{ current: string; latest: string | null; error: string; localVersion: string | null; updatable: boolean; dashboard: string | null } | null>(null);
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

  async function loadCore() {
    if (coreBusy) return;
    coreBusy = true;
    coreError = '';
    try {
      const st = await coreStatus();
      core = { current: st.current, latest: st.latest, error: st.error ?? '', localVersion: st.localVersion ?? null, updatable: st.updatable === true, dashboard: st.dashboard ?? null };
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

  // ——— new page → modal: title, slug, a page-type scaffold, draft-vs-publish ———
  let pagetypes = $state<readonly PagetypeRef[]>([]);
  let newModal = $state<null | { title: string; slug: string; scaffold: string; asDraft: boolean }>(null);
  let creating = $state(false);
  let newError = $state('');

  function openNew() {
    newError = '';
    newModal = { title: '', slug: '', scaffold: 'article', asDraft: false };
    void loadPagetypes(); // scaffolds pay for themselves — lazy load on modal open
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
      const frontmatter = `---\ntitle: ${JSON.stringify(m.title)}\nslug: ${m.slug}\npublished: true\ndraft: ${m.asDraft}\ndate: ${today}\ntags: []\n---`;
      const scaffold = pagetypes.find((p) => p.name === m.scaffold);
      await createPage('pages', m.slug, `${frontmatter}\n\n${scaffold?.body ?? ''}`.trimEnd() + '\n');
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

  async function removePage(type: string, slug: string) {
    if (!confirm(`Delete ${type}/${slug} for good? No undo, no tears.`)) return;
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
  // serve-as, date, tags and draft state. published/published flags are preserved
  // verbatim (publish/unpublish toggles stay a deliberate act, not a side effect);
  // baking reuses the editor's own path — PUT draft under the X-Base-Hash guard +
  // publish with the client-rendered body. Slug changes stay with rename (✎), so
  // no URL surgery happens behind a settings form's back.
  const STYLE_OPTIONS = [['', 'theme default'], ['classical', 'classical'], ['slide', 'slide']] as const;

  let pageSettings = $state<null | {
    type: string;
    slug: string;
    published: boolean;
    title: string;
    asDraft: boolean;
    date: string;
    tags: string;
    style: string;
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
      pageSettings = {
        type,
        slug,
        published: peek('published') !== 'false', // an absent published flag IS published
        title: peek('title'),
        asDraft: peek('draft') === 'true',
        date: peek('date'),
        tags: peek('tags').replace(/^\[|\]$/g, ''),
        style: peek('style'),
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
      if (s.style !== '') lines.push(`style: ${s.style}`);
      lines.push('---');
      const nextRaw = `${lines.join('\n')}\n\n${body}`.trimEnd() + '\n';
      await putDraftRaw(s.type, s.slug, nextRaw, s.hash);
      // Baking only makes sense for a live page — draft-born pages wait for the
      // regular publish path (frontmatter flags ride verbatim, store-side).
      if (s.published) {
        await publishDraft(s.type, s.slug, renderMdms(nextRaw, { type: s.type, slug: s.slug }).fullHtml);
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

<svelte:window onkeydown={(e) => (menuOpen && e.key === 'Escape' ? closeMenu() : null)} />

{#if menuOpen}
  <!-- Escape lives on WINDOW (see the top-level <svelte:window>): the overlay
       auto-opens at boot without focus, and a div listener is DEAD until
       something inside is focused — a keyboard operator trapped in a
       full-screen overlay is a bug, not a preference. The dialog takes focus
       itself on mount. -->
  <div class="fixed inset-0 z-40 overflow-auto" role="dialog" aria-modal="true" aria-label="Menu" tabindex="-1"
    use:dialogFocus
    style="background: linear-gradient(145deg, #fffdf7 0%, #fff8ef 45%, #f9ecd9 100%);"
  >
    <!-- the overlay's chrome, slimmed by law (2026-11-07): tabs on top, the
         close X at the right edge where the live page wears its triangle,
         Log out lives in the drawer's foot — nothing else. -->
    <button class="sm-overlay-x" aria-label="Close the overlay — back to the page" title="Back to the page" onclick={closeMenu}>×</button>
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

    <div class="px-4 mx-auto w-full {tab === 'contents' ? 'pb-4 max-w-none' : 'pb-12 max-w-4xl'}">
      {#if tab === 'contents'}
        <div class="sm-desk">
          <!-- the tree: every md file in use, its folder is the group, the draft
               state rides as badges; the row verbs stay (rename, settings, delete,
               open live) — management never left, it just moved into the margin -->
          <aside class="sm-desk-tree">
            <div class="sm-desk-tree-list">
              {#if index === null}
                <p class="italic text-ink-soft">Opening the drawer…</p>
              {:else}
                {#each Object.entries(index) as [type, pages] (type)}
                  <p class="mg-h mt-3 first:mt-0">{type}</p>
                  <ul class="list-none p-0 m-0 flex flex-col gap-1">
                    {#each pages as page (page.slug)}
                      <li>
                        <div class="flex items-center gap-1">
                          <button
                            class="btn btn-secondary flex-1 justify-start min-w-0"
                            class:btn-primary={sel?.type === type && sel?.slug === page.slug}
                            title={`${type}/${page.slug}/index.md`}
                            onclick={() => openRaw(type, page.slug)}
                          >
                            <span class="truncate">{page.slug}</span>
                            {#if page.home}<span class="badge">home</span>{/if}
                            {#if page.draft}<span class="badge badge-draft">draft</span>{/if}
                            {#if page.hasDraft}<span class="badge badge-live">edits</span>{/if}
                          </button>
                          <button class="btn-ghost" title="Rename" onclick={() => startRename(type, page.slug)}>✎</button>
                          <button class="btn-ghost" title="Page settings" onclick={() => openPageSettings(type, page.slug)}>✑</button>
                          {#if page.home}
                            <!-- the home page stands: no delete, ever (the lid is server-side too) -->
                          {:else}
                            <button
                              class="btn-ghost danger"
                              title={sel?.type === type && sel?.slug === page.slug && unlocked ? 'Delete page' : 'Open the page and press Unlock first — deletion follows the unlock'}
                              disabled={!(sel?.type === type && sel?.slug === page.slug && unlocked)}
                              onclick={() => removePage(type, page.slug)}
                            >×</button>
                          {/if}
                          <button class="btn-ghost" title="Open the live page" onclick={() => select(type, page.slug)}>↗</button>
                        </div>
                        {#if renaming?.type === type && renaming?.slug === page.slug}
                          {@const to = renaming.value.trim().toLowerCase()}
                          {@const unchanged = to === renaming?.slug}
                          {@const taken = (index?.[type] ?? []).some((p) => p.slug === to && p.slug !== renaming?.slug)}
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
                {/each}
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
                  onclick={() => (unlocked = !unlocked)}
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
        {#if siteSettings}
          <form class="mg flex flex-col gap-3 max-w-md" onsubmit={saveSiteNow}>
            <h4 class="mg-h">Site settings</h4>
            <p class="m-0 text-xs text-ink-soft">The live template (theme) hangs in Plugins &amp; Templates now — activate it there.</p>
            <label class="field"><span>Site name</span><input class="input" bind:value={siteSettings.name} required /></label>
            <label class="field"><span>Claim</span><input class="input" bind:value={siteSettings.claim} /></label>
            <label class="field">
              <span>Logo</span>
              <input class="input" bind:value={siteSettings.logo} placeholder="URL, /path, or upload below" />
              <label class="btn btn-secondary self-start cursor-pointer">
                <input class="hidden" type="file" accept="image/*" onchange={onLogoPick} disabled={siteBusy} />
                {siteBusy ? 'Uploading…' : 'Upload logo…'}
              </label>
            </label>
            <label class="field"><span>Template <span class="hint">(the whole site — a page's own "Served as" overrides)</span></span>
              <select class="input" bind:value={siteSettings.style}>
                <option value="">theme default</option>
                <option value="classical">classical</option>
                <option value="slide">slide</option>
              </select>
            </label>
            <label class="field">
              <span>Home slug <span class="hint">(empty = first page)</span></span>
              <input class="input" bind:value={siteSettings.home} pattern="[a-z0-9]+(-[a-z0-9]+)*"
                title="lowercase, digits, hyphens — or empty" />
            </label>
            <button type="submit" class="btn btn-primary self-start" disabled={siteBusy}>{siteBusy ? 'Saving…' : 'Save site'}</button>
            {#if siteError}<p class="alert alert-err m-0" role="alert">{siteError}</p>{/if}
            {#if siteNote}<p class="alert alert-ok m-0">{siteNote}</p>{/if}
          </form>
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
        <section class="mg mb-6">
          <h4 class="mg-h">Plugins</h4>
          {#if pluginsBusy && plugins.length === 0}
            <p class="italic text-ink-soft">Opening the pantry…</p>
          {:else if pluginsError && plugins.length === 0}
            <p class="alert alert-err" role="alert">{pluginsError}</p>
          {:else if plugins.length === 0}
            <p class="m-0 italic text-ink-soft">No plugins installed — drop a folder with plugin.json + plugin.php into plugins/.</p>
          {:else}
            <ul class="list-none p-0 m-0 flex flex-col gap-2">
              {#each plugins as plugin (plugin.name)}
                <li class="card flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="m-0 font-bold">
                      {plugin.title} <span class="text-xs text-ink-soft">v{plugin.version}</span>
                      {#if plugin.error}<span class="badge">broken</span>{/if}
                    </p>
                    {#if plugin.description}<p class="m-0 text-sm text-ink-soft">{plugin.description}</p>{/if}
                    {#if !plugin.enabled && (plugin.requires ?? []).length > 0}
                      {@const sleeping = (plugin.requires ?? []).filter((r) => !plugins.some((p) => p.name === r && p.enabled))}
                      <p class="m-0 text-xs {sleeping.length > 0 ? 'text-error' : 'text-ink-soft'}">
                        requires: {(plugin.requires ?? []).join(', ')}
                        {sleeping.length > 0 ? `— sleeping: ${sleeping.join(', ')}` : '(all enabled ✓)'}
                      </p>
                    {/if}
                    {#if plugin.error}
                      <p class="m-0 text-sm text-ink-soft">{plugin.error}</p>
                    {:else}
                      <p class="m-0 text-xs text-ink-soft">
                        {plugin.placeholders.length} placeholder{plugin.placeholders.length === 1 ? '' : 's'}
                        · {plugin.routes.length} route{plugin.routes.length === 1 ? '' : 's'}
                      </p>
                    {/if}
                  </div>
                  {#if !plugin.error}
                    <button
                      class="btn {plugin.enabled ? 'btn-secondary' : 'btn-primary'}"
                      disabled={pluginsBusy}
                      onclick={() => togglePlugin(plugin)}
                    >
                      {#if pluginsBusy}…{:else if plugin.enabled}Disable{:else}Enable{/if}
                    </button>
                  {/if}
                </li>
              {/each}
            </ul>
          {/if}
          {#if pluginsError && plugins.length > 0}<p class="alert alert-err" role="alert">{pluginsError}</p>{/if}
          {#if pluginsNote}<p class="alert alert-ok">{pluginsNote}</p>{/if}
        </section>
        <section class="mg flex flex-col gap-2">
          <h4 class="mg-h">From the Vitrine</h4>
          {#if vitrineDark}
            <p class="alert alert-warn">{vitrineDark}</p>
          {:else if vitrineBusy && vitrine.length === 0}
            <p class="italic text-ink-soft">Reading the window display…</p>
          {:else}
            <ul class="list-none p-0 m-0 flex flex-col gap-2">
              {#each vitrine.filter((w) => w.kind === 'plugin' && w.state !== 'installed') as ware (ware.name)}
                <li class="card flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="m-0 font-bold">
                      {ware.title} <span class="text-xs text-ink-soft">v{ware.version}</span>
                      {#if ware.state === 'update'}<span class="badge">update</span>{/if}
                    </p>
                    {#if ware.description}<p class="m-0 text-sm text-ink-soft">{ware.description}</p>{/if}
                    {#if ware.missing.length > 0}
                      <p class="m-0 text-xs text-error">
                        requires {ware.requires.join(', ')} — missing: {ware.missing.join(', ')}. The dependency must be installed first.
                      </p>
                    {:else if ware.requires.length > 0 && ware.state !== 'installed'}
                      <p class="m-0 text-xs text-ink-soft">requires: {ware.requires.join(', ')} (all on this host ✓)</p>
                    {/if}
                    <p class="m-0 text-xs text-ink-soft">
                      {ware.state === 'installed'
                        ? 'up to date on this host'
                        : ware.state === 'update'
                          ? `installed: v${ware.installedVersion} — update available`
                          : 'not installed on this host'}
                    </p>
                  </div>
                  <div>
                    {#if ware.state === 'installable'}
                      <button
                        class="btn btn-primary"
                        disabled={vitrineBusy || ware.missing.length > 0}
                        onclick={() => installWareByName(ware)}
                      >
                        {#if vitrineBusy}…{:else}Install{/if}
                      </button>
                    {:else if ware.state === 'update'}
                      <span class="text-xs text-ink-soft self-center">update waiting — see Updates</span>
                    {/if}
                  </div>
                </li>
              {/each}
            </ul>
          {/if}
          {#if vitrineError && role === 'admin'}<p class="alert alert-err" role="alert">{vitrineError}</p>{/if}
          {#if vitrineNote}<p class="alert alert-ok">{vitrineNote}</p>{/if}
        </section>
      {:else if tab === 'templates' && role === 'admin'}
        <section class="mg flex flex-col gap-2">
          <h4 class="mg-h">Templates</h4>
          {#if templatesBusy && templates.length === 0}
            <p class="italic text-ink-soft">Reading the bake-shop wall…</p>
          {:else if templatesError && templates.length === 0}
            <p class="alert alert-err" role="alert">{templatesError}</p>
          {:else}
            <ul class="list-none p-0 m-0 flex flex-col gap-2">
              {#each templates as template (template.name)}
                {@const ware = vitrineTemplateWare(template.name)}
                <li class="card flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="m-0 font-bold">
                      {template.title} <span class="text-xs text-ink-soft">v{template.version}</span>
                      {#if template.active}<span class="badge badge-live ml-2">active</span>{/if}
                    </p>
                    {#if template.description}<p class="m-0 text-sm text-ink-soft">{template.description}</p>{/if}
                    {#if template.styles.length > 0}
                      <p class="m-0 text-xs text-ink-soft">styles: {template.styles.join(', ')}</p>
                    {/if}
                    {#if ware && ware.state === 'update'}
                      <p class="m-0 text-xs text-ink-soft">installed: v{ware.installedVersion} — update waiting in Updates</p>
                    {/if}
                  </div>
                  <div class="flex shrink-0 items-start gap-2">
                    {#if previewSlug !== ''}
                      <a
                        class="btn btn-secondary"
                        href={`/bridge/preview/${template.name}/pages/${previewSlug}`}
                        target="_blank"
                        rel="noopener"
                        title="See your page dressed in this theme — the fitting room, nothing saved"
                      >Preview ↗</a>
                    {/if}
                    {#if template.active}
                      <span class="text-xs text-ink-soft self-center text-right">live — activate another before deleting</span>
                    {:else}
                      <button
                        class="btn btn-primary"
                        disabled={templatesBusy}
                        onclick={() => activateTemplateNow(template)}
                      >
                        {#if templatesBusy}…{:else}Activate{/if}
                      </button>
                      <button
                        class="btn-ghost danger"
                        title="Delete template"
                        disabled={templatesBusy}
                        onclick={() => deleteTemplateNow(template)}
                      >×</button>
                    {/if}
                  </div>
                </li>
              {/each}
            </ul>
            {#if templatesError && templates.length > 0}<p class="alert alert-err" role="alert">{templatesError}</p>{/if}
            {#if templatesNote}<p class="alert alert-ok">{templatesNote}</p>{/if}
            <h4 class="mg-h mg-sub">From the Vitrine</h4>
            {#if vitrineDark}
              <p class="alert alert-warn">{vitrineDark}</p>
            {:else if vitrineBusy && vitrine.length === 0}
              <p class="italic text-ink-soft">Reading the window display…</p>
            {:else}
              <ul class="list-none p-0 m-0 flex flex-col gap-2">
                {#each vitrine.filter((w) => w.kind === 'template' && w.state !== 'installed') as ware (ware.name)}
                  <li class="card flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <p class="m-0 font-bold">
                        {ware.title} <span class="text-xs text-ink-soft">v{ware.version}</span>
                        {#if ware.state === 'update'}<span class="badge">update</span>{/if}
                      </p>
                      {#if ware.description}<p class="m-0 text-sm text-ink-soft">{ware.description}</p>{/if}
                      {#if ware.missing.length > 0}
                        <p class="m-0 text-xs text-error">
                          requires {ware.requires.join(', ')} — missing: {ware.missing.join(', ')}. The dependency must be installed first.
                        </p>
                      {:else if ware.requires.length > 0 && ware.state !== 'installed'}
                        <p class="m-0 text-xs text-ink-soft">requires: {ware.requires.join(', ')} (all on this host ✓)</p>
                      {/if}
                    </div>
                    <div>
                      {#if ware.state === 'installable'}
                        <button
                          class="btn btn-primary"
                          disabled={vitrineBusy || ware.missing.length > 0}
                          onclick={() => installWareByName(ware)}
                        >
                          {#if vitrineBusy}…{:else}Install{/if}
                        </button>
                      {:else if ware.state === 'update'}
                        <span class="text-xs text-ink-soft self-center">update waiting — see Updates</span>
                      {/if}
                    </div>
                  </li>
                {/each}
              </ul>
            {/if}
          {/if}
        </section>
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

{#if mediaModal}
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Media">
    <div class="modal-card" style="width: min(60rem, 94vw)">
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
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Upload markdown page">
    <form class="modal-card" onsubmit={saveUpload}>
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
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Page settings">
    <form class="modal-card" onsubmit={savePageSettings}>
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
        <span>Template <span class="hint">(this page only — a "Served as" here overrides the site-wide one)</span></span>
        <select class="input" bind:value={pageSettings.style}>
          {#each STYLE_OPTIONS as [value, label] (value)}
            <option value={value}>{label}</option>
          {/each}
        </select>
      </label>
      <label class="field">
        <span>Date</span>
        <input class="input" type="date" bind:value={pageSettings.date} />
      </label>
      <label class="field">
        <span>Tags (comma-separated)</span>
        <input class="input" bind:value={pageSettings.tags} />
      </label>

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
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="New page">
    <form class="modal-card" onsubmit={saveNew}>
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
