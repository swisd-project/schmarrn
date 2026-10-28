<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen. p4 chrome: no sidebar;
  // a burger opens the full-screen overlay (Pages / Site / Accounts / Media).
  import { session, login, logout, pageCount, createPage, deletePage, renamePage, listAccounts, addAccount, removeAccount, inviteAccount, setupStatus, runSetup, getSiteSettings, saveSiteSettings, listAllMedia } from '$lib/api';
  import { schemaFor, withDraftFlag } from '$lib/schema';
  import { splitFrontmatter } from '$lib/blocks';
  import { parseMdms } from '@swisd-project/mdms';
  import CanvasEditor from '$lib/components/CanvasEditor.svelte';
  import type { PageIndex, SelectedPage, AccountRef } from '$lib/types';
  import type { SiteMediaRef } from '$lib/api';

  let authenticated = $state<boolean | null>(null);
  let user = $state('');
  let role = $state('');
  let password = $state('');
  let error = $state('');
  let busy = $state(false);
  let index = $state<PageIndex | null>(null);
  let selected = $state<SelectedPage | null>(null);

  // establish session state once — and check whether the bakery needs founding
  let setupNeeded = $state(false);
  let setupThemes = $state<readonly string[]>([]);
  $effect(() => {
    session()
      .then((s) => {
        authenticated = s.authenticated;
        user = s.user;
        role = s.role;
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
    } catch (e) {
      setupError = e instanceof Error ? e.message : String(e);
    } finally {
      setupBusy = false;
    }
  }

  // ——— brand settings (admin) ———
  let siteSettings = $state<{ name: string; claim: string; logo: string; theme: string; home: string } | null>(null);
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

  async function signIn(event: SubmitEvent) {
    event.preventDefault();
    busy = true;
    error = '';
    try {
      const s = await login(user, password);
      authenticated = true;
      role = s.role;
      password = '';
    } catch (e) {
      error = e instanceof Error ? e.message : 'Login failed';
    } finally {
      busy = false;
    }
  }

  async function signOut() {
    await logout();
    authenticated = false;
    selected = null;
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
    selected = { type, slug };
    menuOpen = false; // picking a page leaves the overlay — back to the canvas
  }

  const selectedPublished = $derived.by(() => {
    if (selected === null) return false;
    const sel = selected;
    return (index?.[sel.type] ?? []).some((p) => p.slug === sel.slug && p.draft !== true);
  });

  // ——— the burger overlay: Pages / Site / Accounts / Media ———
  type Tab = 'pages' | 'site' | 'accounts' | 'media';
  let menuOpen = $state(false);
  let tab = $state<Tab>('pages');

  function openMenu() {
    menuOpen = true;
    if (role === 'admin') void loadSiteSettings(); // warm the site form while the overlay opens
  }

  function closeMenu() {
    menuOpen = false;
  }

  function showTab(next: Tab) {
    tab = next;
    if (next === 'media') void loadAllMedia(); // lazy — the tab pays for itself
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

  // ——— new page → modal: title, slug, draft-vs-publish (blog left for plugin-land) ———
  let newModal = $state<null | { title: string; slug: string; asDraft: boolean }>(null);
  let creating = $state(false);
  let newError = $state('');

  function openNew() {
    newError = '';
    newModal = { title: '', slug: '', asDraft: false };
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
      await createPage('pages', m.slug, `${frontmatter}\n\n\n`);
      refreshIndex();
      select('pages', m.slug);
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
      select('pages', m.slug);
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
      if (selected?.type === type && selected?.slug === slug) selected = null;
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
      if (selected?.type === type && selected?.slug === slug) selected = { type, slug: newSlug };
      refreshIndex();
    } catch (e) {
      renameError = e instanceof Error ? e.message : String(e);
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
      {#if selected}
        <span class="text-sm text-ink-soft">{selected.type} / {selected.slug}</span>
      {/if}
      <span class="ml-auto text-sm text-ink-soft">{user}{#if role} · {role}{/if}</span>
      <button class="btn btn-secondary" onclick={signOut}>Log out</button>
    </header>

    <section class="px-4 pb-8 max-w-6xl mx-auto w-full">
      {#if selected}
        {#key `${selected.type}/${selected.slug}`}
          <CanvasEditor
            page={selected}
            published={selectedPublished}
            currentUserName={user}
            onsaved={refreshIndex}
            onrenamed={(slug) => {
              if (selected) selected = { ...selected, slug };
              refreshIndex();
            }}
          />
        {/key}
      {:else}
        <div class="mg py-16 text-center">
          <p class="m-0 italic text-ink-soft">Pick a shredded piece from the menu, sugar.</p>
        </div>
      {/if}
    </section>
  {/if}

  {#if error && authenticated !== false}
    <p class="alert alert-err" role="alert">{error}</p>
  {/if}
</main>

{#if menuOpen}
  <div class="fixed inset-0 z-40 overflow-auto" role="dialog" aria-modal="true" aria-label="Menu" tabindex="-1"
    style="background: linear-gradient(145deg, #fffdf7 0%, #fff8ef 45%, #f9ecd9 100%);"
    onkeydown={(e) => e.key === 'Escape' && closeMenu()}
  >
    <div class="flex items-center gap-3 px-4 py-3">
      <button class="burger" aria-label="Close menu" onclick={closeMenu}>
        <span></span><span></span><span></span>
      </button>
      <span class="font-extrabold text-lg tracking-tight">Schmarrn</span>
      <span class="ml-auto text-sm text-ink-soft">{user}{#if role} · {role}{/if}</span>
      <button class="btn btn-secondary" onclick={closeMenu}>Back to the canvas</button>
    </div>

    <nav class="flex gap-2 px-4 pb-4 flex-wrap" aria-label="Menu sections">
      <button class="btn {tab === 'pages' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('pages')}>Pages</button>
      {#if role === 'admin'}
        <button class="btn {tab === 'site' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('site')}>Site</button>
        <button class="btn {tab === 'accounts' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('accounts')}>Accounts</button>
      {/if}
      <button class="btn {tab === 'media' ? 'btn-primary' : 'btn-secondary'}" onclick={() => showTab('media')}>Media</button>
    </nav>

    <div class="px-4 pb-12 max-w-4xl mx-auto w-full">
      {#if tab === 'pages'}
        {#if index}
          {#each Object.entries(index) as [type, pages] (type)}
            <section class="mg mb-6">
              <h4 class="mg-h">{type}</h4>
              <ul class="list-none p-0 m-0 flex flex-col gap-1">
                {#each pages as page (page.slug)}
                  <li class="flex items-center gap-1">
                    {#if renaming?.type === type && renaming?.slug === page.slug}
                      {@const to = renaming.value.trim().toLowerCase()}
                      {@const unchanged = to === renaming?.slug}
                      {@const taken = (index?.[type] ?? []).some((p) => p.slug === to && p.slug !== renaming?.slug)}
                      <form class="rename flex gap-1 flex-wrap items-center" onsubmit={commitRename}>
                        <input class="input max-w-48" bind:value={renaming.value} pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
                        <button type="submit" class="btn btn-primary" disabled={unchanged || taken}
                          title={unchanged ? 'Name unchanged' : taken ? 'Already taken' : 'Rename'}>✓</button>
                        <button type="button" class="btn btn-secondary" onclick={() => (renaming = null)}>×</button>
                        {#if unchanged}
                          <span class="text-xs text-ink-soft">Same name — change it or close.</span>
                        {:else if taken}
                          <span class="text-xs text-error">Another page already uses “{to}”.</span>
                        {:else if renameError}
                          <span class="text-xs text-error">{renameError}</span>
                        {/if}
                      </form>
                    {:else}
                      <button
                        class="btn btn-secondary flex-1 justify-start {selected?.type === type && selected?.slug === page.slug ? 'ring-2 ring-jam' : ''}"
                        onclick={() => select(type, page.slug)}
                      >
                        {page.slug}
                        {#if page.draft}<span class="badge badge-draft ml-2">draft</span>{/if}
                        {#if page.hasDraft}<span class="badge badge-live ml-2">edits</span>{/if}
                      </button>
                      <button class="btn-ghost" title="Rename" onclick={() => startRename(type, page.slug)}>✎</button>
                      <button class="btn-ghost danger" title="Delete" onclick={() => removePage(type, page.slug)}>×</button>
                    {/if}
                  </li>
                {/each}
              </ul>
            </section>
          {/each}
        {/if}

        <section class="mg flex flex-col gap-3">
          <h4 class="mg-h">New</h4>
          <button class="btn btn-primary self-start" onclick={openNew}>+ New page</button>
          <label class="btn btn-secondary self-start cursor-pointer">
            <input class="hidden" type="file" accept=".md,.markdown,text/markdown" onchange={onUploadPick} />
            Upload .md → new page
          </label>
          {#if uploadError}<p class="alert alert-err m-0" role="alert">{uploadError}</p>{/if}
        </section>
      {:else if tab === 'site' && role === 'admin'}
        {#if siteSettings}
          <form class="mg flex flex-col gap-3 max-w-md" onsubmit={saveSiteNow}>
            <h4 class="mg-h">Site settings</h4>
            <label class="field"><span>Site name</span><input class="input" bind:value={siteSettings.name} required /></label>
            <label class="field"><span>Claim</span><input class="input" bind:value={siteSettings.claim} /></label>
            <label class="field"><span>Logo (URL or /path)</span><input class="input" bind:value={siteSettings.logo} /></label>
            <label class="field"><span>Theme</span>
              <select class="input" bind:value={siteSettings.theme}>
                {#each setupThemes as theme (theme)}
                  <option value={theme}>{theme}</option>
                {/each}
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
      {:else if tab === 'accounts' && role === 'admin'}
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
      {:else if tab === 'media'}
        {#if mediaBusy}
          <p class="italic text-ink-soft">Sweeping the pantry…</p>
        {:else if mediaError}
          <p class="alert alert-err" role="alert">{mediaError}</p>
        {:else if overlayMedia.length === 0}
          <div class="mg py-12 text-center"><p class="m-0 italic text-ink-soft">No media yet — upload lives inside the image and video modals.</p></div>
        {:else}
          {#if mediaNote}<p class="alert alert-ok">{mediaNote}</p>{/if}
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
