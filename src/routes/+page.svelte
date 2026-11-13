<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen. p4 chrome: no sidebar;
  // a burger opens the full-screen overlay (Contents / Settings / Plugins & Templates / Users).
  import { session, login, logout, pageCount, searchPages, deletePage, renamePage, setupStatus, runSetup, getSiteSettings, saveSiteSettings, uploadSiteAsset, listPlugins, setPlugins, deletePlugin, savePluginSettings, listTemplates, activateTemplate, deleteTemplate, coreStatus, uploadCorePacket, runCoreUpdate, listPagetypes, listWares, installWare, getDraftRaw, putDraftRaw, publishDraft, githubStatus, githubInspect, githubInstall, mailStatus, type PageRef, type PluginRef, type TemplateRef, type PagetypeRef, type WareRef, type ThemeHandle, type GithubLook, type MailStatus } from '$lib/api';
  import { adminCloseTarget } from '$lib/back';
  import { base } from '$app/paths';
  import { schemaFor } from '$lib/schema';
  import { splitFrontmatter } from '$lib/blocks';
  import { renderMdms } from '$lib/render';
  import { parseMdms } from '@swisd-project/mdms';
  import type { PageIndex } from '$lib/types';
  import ForgotModal from '$lib/components/ForgotModal.svelte';
  import GithubModal from '$lib/components/GithubModal.svelte';
  import MailModal from '$lib/components/MailModal.svelte';
  import ConfirmModal from '$lib/components/ConfirmModal.svelte';
  import PanelModal from '$lib/components/PanelModal.svelte';
  import UploadModal from '$lib/components/UploadModal.svelte';
  import PageSettingsModal from '$lib/components/PageSettingsModal.svelte';
  import NewPageModal from '$lib/components/NewPageModal.svelte';
  import MediaModal from '$lib/components/MediaModal.svelte';
  import UsersTab from '$lib/components/UsersTab.svelte';
  import UpdatesTab from '$lib/components/UpdatesTab.svelte';
  import SettingsTab from '$lib/components/SettingsTab.svelte';
  import TemplatesTab from '$lib/components/TemplatesTab.svelte';
  import PluginsTab from '$lib/components/PluginsTab.svelte';

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
  // ——— the theme handles: the active template's declared CSS variables, as the
  // ——— pickers see them. The template's stylesheet keeps the layout; these
  // ——— knobs only turn the colors it declared. (STRIP 8c: the knobs live in
  // ——— src/lib/components/SettingsTab.svelte; the parent keeps the truth.) ———

  async function loadSiteSettings() {
    if (siteSettings !== null) return;
    try {
      siteSettings = { ...(await getSiteSettings()) };
    } catch {
      // the settings card shows its "reaching" note; not a page-top error
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
    // (contents' media loads itself — the MediaModal sweeps on open)
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

  // delete/toggle rides moved to PluginsTab.svelte (STRIP 8e).

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

  // ——— the Updates tab: STRIP 8b of the split — the verdicts, the update-all
  // ——— ride and its notes live in src/lib/components/UpdatesTab.svelte; the
  // ——— parent keeps the core session (ride/park/load) and installOne.
  /** The tab's report: the core rode — the shelves re-read (the registry's
   *  host floor may have moved). */
  async function refreshAfterCore(): Promise<void> {
    await loadCore();
    await loadWares(true);
  }


  // ——— media tab (lazy) ———
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


  // ——— the core's self-update (admin, part of "Plugins & Templates") ———
  let core = $state<{ current: string; latest: string | null; error: string; localVersion: string | null; updatable: boolean; dashboard: string | null; releaseName: string | null; published: string | null; contributors: readonly { login: string; url: string }[] } | null>(null);
  // ——— the GitHub door (Integrations card): STRIP 2 of the split — the
  // ——— token/test/connect flow lives in src/lib/components/GithubModal.svelte;
  // ——— the parent keeps the STATUS, the note, and the repo-inspect flow
  // ——— (the menu overlay renders both). ———
  let github = $state<{ connected: boolean; login: string | null } | null>(null);
  let githubRepo = $state('');
  let githubLook = $state<GithubLook | null>(null);
  let githubBusy = $state(false);
  let githubError = $state('');
  let githubNote = $state('');
  let githubModal = $state(false);

  /** The modal's report: the connection changed — the parent owns the status
   *  and the note (the note renders in the menu overlay, not the modal). */
  function githubChanged(fresh: { connected: boolean; login: string | null }, note: string): void {
    github = fresh;
    githubNote = note;
  }

  // ——— the house confirm: STRIP 4 of the split — the modal lives in
  // ——— src/lib/components/ConfirmModal.svelte; the parent keeps its
  // ——— askConfirm() API (five call sites) and hands the request over bound.
  // ——— (2026-11-12, user order): no system popups, ever — every "are you
  // ——— sure" is a modal wearing the house pattern.
  let confirmModal = $state<{ title: string; message: string; run: () => void } | null>(null);

  function askConfirm(title: string, message: string, run: () => void): void {
    confirmModal = { title, message, run };
  }

  // ——— the SMTP oven (Integrations card, second square) ———
  // ——— the mail oven (Integrations card, second square): STRIP 3 of the
  // ——— split — the form, the test and the connect flow live in
  // ——— src/lib/components/MailModal.svelte; the parent keeps the STATUS and
  // ——— the note (carried as-is: mailNote is set but rendered nowhere today
  // ——— — behavior unchanged). ———
  let mail = $state<{ configured: boolean; host: string; port: number; secure: string; fromEmail: string; fromName: string } | null>(null);
  let mailModal = $state(false);
  let mailNote = $state('');

  /** The modal's report: the connection changed. */
  function mailChanged(fresh: NonNullable<typeof mail>, note: string): void {
    mail = fresh;
    mailNote = note;
  }

  async function loadMail() {
    try {
      mail = await mailStatus();
    } catch {
      mail = null; // the card stays quiet when the oven door is out of reach
    }
  }

  // ——— the forgot-password door: STRIP 1 of the split — the whole flow lives
  // ——— in src/lib/components/ForgotModal.svelte; the parent keeps only the
  // ——— boolean (the component resets itself every time it opens). ———
  let forgotModal = $state(false);

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
// ——— the templates tab: STRIP 8d of the split — the rows, the selection and
// ——— the activate/delete rides live in src/lib/components/TemplatesTab.svelte;
// ——— the parent keeps the truth (templates, the shelf, the fold map, the
// ——— GitHub session) and the fallback previews (HOUSE_SHOTS/houseShot). ———
  let selectedTheme = $state('');
  let brokenPreviews = $state<Record<string, boolean>>({});

// ——— the plugins tab: STRIP 8e of the split — the rows, the detail tabs, the
// ——— settings draft, the plugin PANEL and its rides live in
// ——— src/lib/components/PluginsTab.svelte; the parent keeps the truth
// ——— (plugins, the shelf) and loadPlugins. ———

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

  async function parkCorePacket(file: File) {
    if (coreBusy) return;
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
    }
  }

  async function updateCoreNow(source: 'hub' | 'local' = 'hub'): Promise<{ from: string; to: string; dashboard: boolean } | null> {
    if (coreBusy) return null;
    coreBusy = true;
    coreError = '';
    coreNote = '';
    try {
      const done = await runCoreUpdate(source);
      await loadCore();
      coreNote = `${done.note} Reload the page — the dashboard you are wearing is from ${done.from}.`;
      if (!done.dashboard) coreNote += ' The dashboard did NOT move with this packet — rebuild it (npm run build) or ride a release that carries its asset zip.';
      return { from: done.from, to: done.to, dashboard: done.dashboard };
    } catch (e) {
      coreError = e instanceof Error ? e.message : String(e);
      return null;
    } finally {
      coreBusy = false;
    }
  }


  // ——— accounts (admin): STRIP 8a of the split — the whole tab lives in
  // ——— src/lib/components/UsersTab.svelte (it sweeps itself on mount). ———
  // ——— new page → modal: title, slug, style, a page-type scaffold, draft-vs-publish ———
  // The style is a creation-time choice (2026-11-09, user order): the options
  // are the ACTIVE THEME's declared styles, the frontpage among them — picking
  // it crowns the new page as the home (the frontpage style is derived from
  // the crown, never written to frontmatter). Styles load lazily with the
  // templates; no theme, no list, no selector.
  let pagetypes = $state<readonly PagetypeRef[]>([]);
  let newModal = $state<null | { title: string; slug: string; style: string; scaffold: string; asDraft: boolean }>(null);
  let newError = $state('');

  function openNew() {
    newError = '';
    newModal = { title: '', slug: '', style: templates.find((t) => t.active)?.defaultStyle ?? '', scaffold: 'article', asDraft: false };
    void loadPagetypes(); // scaffolds pay for themselves — lazy load on modal open
    if (templates.find((t) => t.active)?.styles.length === 0) void loadTemplates(); // the style list rides the theme
  }

  async function loadPagetypes() {
    if (pagetypes.length > 0) return; // fetched once per visit, refreshed by reload
    try {
      pagetypes = await listPagetypes();
    } catch (e) {
      newError = e instanceof Error ? e.message : String(e);
    }
  }
  // ——— upload .md → modal: validate frontmatter, fix slug/title, choose draft or publish ———
  let uploadError = $state('');
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

  /** The settings modal's report: saved. The parent refreshes the index and,
   *  when the crown moved, the site settings' home rides along. */
  function pageSettingsSaved(home: string | null): void {
    refreshIndex();
    if (home !== null && siteSettings !== null) {
      siteSettings = { ...siteSettings, home };
    }
  }

  /** The upload modal's report: the page exists — refresh and step onto it. */
  function uploaded(slug: string): void {
    refreshIndex();
    select('pages', slug); // onto the live page — fill it there
  }

  /** The new-page modal's report: the page exists — refresh and step onto it. */
  function created(slug: string): void {
    refreshIndex();
    select('pages', slug); // onto the live page — fill it there
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
        <button type="button" class="btn-ghost self-center" onclick={() => (forgotModal = true)}>Forgot password?</button>
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
                        <!-- THE CURSOR LAW, FLIPPED (2026-11-13, user order):
                             LOCKED sheet = the row's edit verbs are out of
                             reach (not-allowed); UNLOCKED = they invite the
                             hand. A user cannot unlock two files at a time —
                             the eye reads which file is in surgery. -->
                        <button class="btn-ghost" class:cursor-not-allowed={!unlocked} title="Rename" onclick={() => startRename(row.type, row.page.slug)}>✎</button>
                        <button class="btn-ghost" class:cursor-not-allowed={!unlocked} title="Page settings" onclick={() => openPageSettings(row.type, row.page.slug)}>✑</button>
                        {#if row.page.home}
                          <!-- the home page stands: no delete, ever (the lid is server-side too) -->
                        {:else}
                          <button
                            class="btn-ghost danger"
                            class:cursor-not-allowed={!unlocked}
                            title={sel?.type === row.type && sel?.slug === row.page.slug && unlocked ? 'Delete page' : 'Open the page and press Unlock first — deletion follows the unlock'}
                            disabled={!(sel?.type === row.type && sel?.slug === row.page.slug && unlocked)}
                            onclick={() => removePage(row.type, row.page.slug)}
                          >×</button>
                        {/if}
                        <button class="btn-ghost" class:cursor-not-allowed={!unlocked} title="Open the live page" onclick={() => select(row.type, row.page.slug)}>↗</button>
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
                <button class="btn btn-secondary flex-1 justify-center" onclick={() => (mediaModal = true)}>Media</button>
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
        <SettingsTab bind:siteSettings={siteSettings} core={core} github={github} mail={mail}
          githubError={githubError} githubNote={githubNote}
          ongithub={() => (githubModal = true)} onmail={() => (mailModal = true)} />
      {:else if tab === 'users' && role === 'admin'}
        <UsersTab user={user} />
      {:else if tab === 'plugins' && role === 'admin'}
        <PluginsTab bind:plugins={plugins} wares={vitrine} bind:brokenPreviews={brokenPreviews}
          folds={openSections} toggle={toggleSection}
          vitrineDark={vitrineDark} vitrineBusy={vitrineBusy} vitrineError={vitrineError}
          houseShot={houseShot} confirm={askConfirm} install={installWareByName}
          bind:githubRepo={githubRepo} bind:githubBusy={githubBusy} bind:githubError={githubError}
          bind:githubNote={githubNote} bind:githubLook={githubLook}
          onGithubLook={lookGithubNow} onGithubStage={(p) => stageGithubNow('plugin', p)} />
      {:else if tab === 'templates' && role === 'admin'}
        <TemplatesTab templates={templates} wares={vitrine} bind:selectedTheme={selectedTheme}
          bind:brokenPreviews={brokenPreviews} bind:siteSettings={siteSettings}
          folds={openSections} toggle={toggleSection} previewSlug={previewSlug}
          vitrineDark={vitrineDark} vitrineBusy={vitrineBusy} vitrineError={vitrineError} vitrineNote={vitrineNote}
          houseShot={houseShot} confirm={askConfirm} install={installWareByName} refreshShelves={() => loadWares(true)}
          bind:githubRepo={githubRepo} bind:githubBusy={githubBusy} bind:githubError={githubError}
          bind:githubNote={githubNote} bind:githubLook={githubLook}
          onGithubLook={lookGithubNow} onGithubStage={(p) => stageGithubNow('template', p)} />
      {:else if tab === 'updates' && role === 'admin'}
        <UpdatesTab wares={vitrine} dark={vitrineDark} core={core} coreBusy={coreBusy}
          coreError={coreError} coreNote={coreNote} rideCore={updateCoreNow}
          parkPacket={parkCorePacket} refreshAfterCore={refreshAfterCore}
          installOne={installOne} />
      {/if}
    </div>
  </div>
{/if}

<ConfirmModal bind:request={confirmModal} />

<ForgotModal bind:open={forgotModal} />

<MailModal bind:open={mailModal} status={mail} onchanged={mailChanged} />

<GithubModal bind:open={githubModal} status={github} onchanged={githubChanged} />

<MediaModal bind:open={mediaModal} />

<UploadModal bind:request={uploadModal} bind:error={uploadError} oncreated={uploaded} />

<PageSettingsModal bind:request={pageSettings} admin={role === 'admin'} onsaved={pageSettingsSaved} />

<NewPageModal bind:request={newModal} scaffolds={pagetypes} templates={templates} index={index} bind:error={newError} oncreated={created} />
