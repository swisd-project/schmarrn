<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen, phase 1.
  import { session, login, logout, pageCount, createPage, deletePage, renamePage, listAccounts, addAccount, removeAccount, inviteAccount, setupStatus, runSetup, getSiteSettings, saveSiteSettings } from '$lib/api';
  import { schemaFor, withDraftFlag } from '$lib/schema';
  import { splitFrontmatter } from '$lib/blocks';
  import { parseMdms } from '@swisd-project/mdms';
  import CanvasEditor from '$lib/components/CanvasEditor.svelte';
  import type { PageIndex, SelectedPage, AccountRef } from '$lib/types';

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
  let showSite = $state(false);
  let siteSettings = $state<{ name: string; claim: string; logo: string; theme: string; home: string } | null>(null);
  let siteBusy = $state(false);
  let siteError = $state('');
  let siteNote = $state('');

  async function toggleSite() {
    showSite = !showSite;
    if (showSite && siteSettings === null) {
      try {
        siteSettings = { ...(await getSiteSettings()) };
      } catch (e) {
        siteError = e instanceof Error ? e.message : String(e);
      }
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
  }

  const selectedPublished = $derived.by(() => {
    if (selected === null) return false;
    const sel = selected;
    return (index?.[sel.type] ?? []).some((p) => p.slug === sel.slug && p.draft !== true);
  });

  // ——— accounts (admin) ———
  let showAccounts = $state(false);
  let accounts = $state<readonly AccountRef[]>([]);
  let accountAddUser = $state('');
  let accountAddRole = $state<'admin' | 'editor'>('editor');
  let accountAddPassword = $state('');
  let accountAddEmail = $state('');
  let accountError = $state('');
  let accountNote = $state('');

  async function toggleAccounts() {
    showAccounts = !showAccounts;
    if (showAccounts) {
      accounts = await listAccounts();
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

<main class="shell">
  {#if authenticated === null}
    <p aria-live="polite">Reaching for the pantry…</p>
  {:else if setupNeeded}
    <form onsubmit={foundBakery} class="login setup">
      <h1>Welcome to Schmarrn</h1>
      <p class="setup-note">First visit — found the bakery. One admin account, one name, one theme.</p>
      <input type="text" bind:value={setupUser} placeholder="admin username" autocomplete="username" required />
      <input type="password" bind:value={setupPassword} placeholder="password (min 8)" minlength="8" autocomplete="new-password" required />
      <input type="text" bind:value={setupSiteName} placeholder="site name" required />
      <input type="text" bind:value={setupClaim} placeholder="claim (optional)" />
      <select bind:value={setupTheme}>
        {#each setupThemes as theme (theme)}
          <option value={theme}>{theme}</option>
        {/each}
      </select>
      <button type="submit" disabled={setupBusy}>{setupBusy ? 'Lighting the ovens…' : 'Found the bakery'}</button>
      {#if setupError}<p class="error">{setupError}</p>{/if}
    </form>
  {:else if authenticated === false}
    <form onsubmit={signIn} class="login">
      <h1>Schmarrn</h1>
      <input type="text" bind:value={user} placeholder="Who's kneading?" autocomplete="username" required />
      <input type="password" bind:value={password} placeholder="Kitchen password" autocomplete="current-password" required />
      <button type="submit" disabled={busy}>{busy ? 'Plating…' : 'Schnitten!'}</button>
      {#if error}<p class="error">{error}</p>{/if}
    </form>
  {:else}
    <header>
      <h1>Schmarrn</h1>
      <span class="whoami">{user}{#if role} · {role}{/if}</span>
      <button onclick={signOut}>Log out</button>
    </header>

    <div class="workbench">
      <nav class="tree" aria-label="Pages">
        {#if index}
          {#each Object.entries(index) as [type, pages] (type)}
            <p><strong>{type}</strong></p>
            <ul>
              {#each pages as page (page.slug)}
                <li>
                  {#if renaming?.type === type && renaming?.slug === page.slug}
                    {@const to = renaming.value.trim().toLowerCase()}
                    {@const unchanged = to === renaming?.slug}
                    {@const taken = (index?.[type] ?? []).some((p) => p.slug === to && p.slug !== renaming?.slug)}
                    <form class="rename" onsubmit={commitRename}>
                      <input bind:value={renaming.value} pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
                      <button type="submit" disabled={unchanged || taken} title={unchanged ? 'Name unchanged' : taken ? 'Already taken' : 'Rename'}>✓</button>
                      <button type="button" onclick={() => (renaming = null)}>×</button>
                      {#if unchanged}
                        <span class="rename-hint">Same name — change it or close.</span>
                      {:else if taken}
                        <span class="rename-error">Another page already uses “{to}”.</span>
                      {:else if renameError}
                        <span class="rename-error">{renameError}</span>
                      {/if}
                    </form>
                  {:else}
                    <button
                      class:active={selected?.type === type && selected?.slug === page.slug}
                      onclick={() => select(type, page.slug)}
                    >
                      {page.slug}{#if page.draft}<span class="draft">draft</span>{/if}{#if page.hasDraft}<span class="draft">edits</span>{/if}
                    </button>
                    <span class="page-actions">
                      <button class="mini" title="Rename" onclick={() => startRename(type, page.slug)}>✎</button>
                      <button class="mini danger" title="Delete" onclick={() => removePage(type, page.slug)}>×</button>
                    </span>
                  {/if}
                </li>
              {/each}
            </ul>
          {/each}
        {/if}

        {#if role === 'admin'}
          <button class="accounts-toggle" onclick={toggleSite}>
            {showSite ? 'Hide site settings' : 'Site settings'}
          </button>
          {#if showSite && siteSettings}
            <form class="site-settings" onsubmit={saveSiteNow}>
              <input bind:value={siteSettings.name} placeholder="site name" required />
              <input bind:value={siteSettings.claim} placeholder="claim" />
              <input bind:value={siteSettings.logo} placeholder="logo (URL or /path)" />
              <select bind:value={siteSettings.theme}>
                {#each setupThemes as theme (theme)}
                  <option value={theme}>{theme}</option>
                {/each}
              </select>
              <input bind:value={siteSettings.home} placeholder="home slug (empty = first page)"
                pattern="[a-z0-9]+(-[a-z0-9]+)*" title="lowercase, digits, hyphens — or empty" />
              <button type="submit" disabled={siteBusy}>{siteBusy ? 'Saving…' : 'Save site'}</button>
              {#if siteError}<p class="error">{siteError}</p>{/if}
              {#if siteNote}<p class="account-note">{siteNote}</p>{/if}
            </form>
          {/if}

          <button class="accounts-toggle" onclick={toggleAccounts}>
            {showAccounts ? 'Hide accounts' : 'Accounts'}
          </button>
          {#if showAccounts}
            <div class="accounts">
              <ul>
                {#each accounts as account (account.user)}
                  <li>
                    <span>{account.user} · {account.role}{#if account.email} · {account.email}{/if}</span>
                    {#if account.user !== user}
                      <span class="account-actions">
                        {#if account.email}
                          <button
                            class="account-mail"
                            title="Mail freshly issued credentials"
                            onclick={() => inviteNow(account.user)}>✉</button
                          >
                        {/if}
                        <button
                          class="account-remove"
                          aria-label={`Remove account ${account.user}`}
                          onclick={() => deleteAccountNow(account.user)}>×</button
                        >
                      </span>
                    {/if}
                  </li>
                {/each}
              </ul>
              <form class="newpage" onsubmit={addAccountNow}>
                <input bind:value={accountAddUser} placeholder="username" required />
                <input
                  bind:value={accountAddPassword}
                  type="password"
                  placeholder="password (min 8)"
                  minlength="8"
                  required
                />
                <input bind:value={accountAddEmail} type="email" placeholder="email (for invites)" />
                <select bind:value={accountAddRole}>
                  <option value="editor">editor</option>
                  <option value="admin">admin</option>
                </select>
                <button type="submit">Add account</button>
              </form>
              {#if accountError}<p class="error">{accountError}</p>{/if}
              {#if accountNote}<p class="account-note">{accountNote}</p>{/if}
            </div>
          {/if}
        {/if}

        <button class="newpage-btn" onclick={openNew}>+ New page</button>

        <label class="upload-md">
          <input type="file" accept=".md,.markdown,text/markdown" onchange={onUploadPick} />
          Upload .md → new page
        </label>
        {#if uploadError}<p class="error">{uploadError}</p>{/if}
      </nav>

      <section class="stage">
        {#if selected}
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
          {/if}
        {:else}
          <p class="empty">Pick a shredded piece from the left, sugar.</p>
        {/if}
      </section>
    </div>
  {/if}

  {#if error && authenticated !== false}
    <p class="error">{error}</p>
  {/if}
</main>

{#if uploadModal}
  <div class="overlay" role="dialog" aria-modal="true" aria-label="Upload markdown page">
    <form class="card" onsubmit={saveUpload}>
      <header class="card-head">
        <h3>Upload — {uploadModal.fileName}</h3>
        <button type="button" class="close" onclick={() => (uploadModal = null)}>×</button>
      </header>

      {#if uploadModal.valid}
        <p class="ok">Frontmatter checks out. Serving as-is.</p>
      {:else}
        <p class="warn">
          The frontmatter needs attention — save will rebuild it from the fields below (body stays untouched):
        </p>
        <ul class="issues">
          {#each uploadModal.issues as issue}
            <li>{issue}</li>
          {/each}
        </ul>
      {/if}

      <label class="field">
        <span>Title</span>
        <input bind:value={uploadModal.title} required placeholder="Page title" />
      </label>
      <label class="field">
        <span>Slug (URL)</span>
        <input bind:value={uploadModal.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
          title="lowercase letters, digits, hyphens" />
      </label>

      <fieldset class="serving">
        <legend>Serve this page as</legend>
        <label><input type="radio" bind:group={uploadModal.asDraft} value={false} /> Published — visible to the public</label>
        <label><input type="radio" bind:group={uploadModal.asDraft} value={true} /> Draft — editors only until you publish</label>
      </fieldset>

      {#if uploadError}<p class="error" role="alert">{uploadError}</p>{/if}
      <footer class="card-foot">
        <button type="button" class="cancel" onclick={() => (uploadModal = null)}>Cancel</button>
        <button type="submit" class="save" disabled={uploading}>{uploading ? 'Folding…' : 'Create page'}</button>
      </footer>
    </form>
  </div>
{/if}

{#if newModal}
  <div class="overlay" role="dialog" aria-modal="true" aria-label="New page">
    <form class="card" onsubmit={saveNew}>
      <header class="card-head">
        <h3>New page</h3>
        <button type="button" class="close" onclick={() => (newModal = null)}>×</button>
      </header>

      <label class="field">
        <span>Title</span>
        <input bind:value={newModal.title} required placeholder="Page title" />
      </label>
      <label class="field">
        <span>Slug (URL)</span>
        <input bind:value={newModal.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
          title="lowercase letters, digits, hyphens" placeholder="my-tart" />
      </label>

      <fieldset class="serving">
        <legend>Serve this page as</legend>
        <label><input type="radio" bind:group={newModal.asDraft} value={false} /> Published — visible to the public</label>
        <label><input type="radio" bind:group={newModal.asDraft} value={true} /> Draft — editors only until you publish</label>
      </fieldset>

      {#if newError}<p class="error" role="alert">{newError}</p>{/if}
      <footer class="card-foot">
        <button type="button" class="cancel" onclick={() => (newModal = null)}>Cancel</button>
        <button type="submit" class="save" disabled={creating}>{creating ? 'Folding…' : 'Create page'}</button>
      </footer>
    </form>
  </div>
{/if}

<style>
  .shell { max-width: 72rem; margin: 0 auto; padding: 2rem; }
  .login { display: flex; flex-direction: column; gap: 0.75rem; max-width: 16rem; }
  .error { color: #b3004c; }
  header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem; }
  .workbench { display: grid; grid-template-columns: 12rem 1fr; gap: 1.5rem; }
  .tree ul { list-style: none; padding: 0; margin: 0.25rem 0 1rem; }
  .tree p { margin: 0.5rem 0 0; opacity: 0.7; }
  .tree button {
    width: 100%; text-align: left; background: transparent; border: 0;
    padding: 0.25rem 0.5rem; cursor: pointer; border-radius: 4px; color: var(--crust);
  }
  .tree button:hover { background: #efe6d8; }
  .tree button.active { background: var(--raisin); color: var(--batter); }
  .draft { margin-left: 0.4rem; font-size: 0.65rem; text-transform: uppercase; opacity: 0.75; }
  .tree li { display: flex; align-items: center; gap: 0.2rem; }
  .tree li > button:first-child { flex: 1; }
  .page-actions { display: inline-flex; }
  .mini { border: 0; background: transparent; cursor: pointer; font-size: 0.75rem; opacity: 0.5; padding: 0 0.2rem; }
  .mini:hover { opacity: 1; }
  .mini.danger { color: #b3004c; }
  .rename { display: flex; gap: 0.2rem; width: 100%; flex-wrap: wrap; }
  .rename input { width: 100%; font-size: 0.8rem; padding: 0.15rem 0.3rem; border: 1px solid #d8c7b2; border-radius: 4px; }
  .rename-error { flex-basis: 100%; color: #b3004c; font-size: 0.7rem; }
  .rename-hint { flex-basis: 100%; color: #6f5d4f; font-size: 0.7rem; }
  .overlay {
    position: fixed; inset: 0; background: rgba(40, 25, 15, 0.45);
    display: grid; place-items: center; z-index: 50;
  }
  .card {
    background: #fffdf9; border-radius: 12px; padding: 1rem 1.25rem;
    width: min(30rem, 92vw); max-height: 84vh; overflow: auto;
    box-shadow: 0 12px 30px rgba(60, 30, 10, 0.25);
    display: flex; flex-direction: column; gap: 0.7rem;
  }
  .card-head { display: flex; justify-content: space-between; align-items: baseline; }
  .card-head h3 { margin: 0; font-size: 1rem; }
  .close { border: 0; background: transparent; font-size: 1.2rem; cursor: pointer; }
  .field { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.85rem; }
  .field input { border: 1px solid #d8c7b2; border-radius: 6px; padding: 0.4rem 0.6rem; background: #fff; }
  .serving { border: 1px dashed #d8c7b2; border-radius: 8px; padding: 0.5rem 0.75rem; display: flex; flex-direction: column; gap: 0.3rem; font-size: 0.85rem; }
  .serving legend { font-size: 0.75rem; opacity: 0.7; padding: 0 0.3rem; }
  .ok { color: #1d7a3a; font-size: 0.85rem; margin: 0; }
  .warn { color: #7a4a00; font-size: 0.85rem; margin: 0; }
  .issues { margin: 0; padding-left: 1.2rem; color: #b3004c; font-size: 0.8rem; }
  .card-foot { display: flex; justify-content: flex-end; gap: 0.5rem; }
  .save { font-weight: 600; }
  .cancel { opacity: 0.7; }
  .upload-md { display: block; margin-top: 0.75rem; font-size: 0.8rem; opacity: 0.8; cursor: pointer; }
  .upload-md input { display: none; }
  .upload-md:hover { opacity: 1; }
  .newpage-btn {
    width: 100%; margin-top: 0.75rem; font-size: 0.85rem; font-weight: 600;
    border: 1px dashed #d8c7b2; background: #fff4e6; border-radius: 6px;
    padding: 0.4rem; cursor: pointer;
  }
  .newpage-btn:hover { background: #ffe9d6; }
  .newpage { display: flex; flex-direction: column; gap: 0.35rem; margin-top: 1rem; }
  .newpage input { font-size: 0.8rem; padding: 0.3rem 0.5rem; border: 1px solid #d8c7b2; border-radius: 4px; }
  .empty { opacity: 0.5; font-style: italic; }
  .whoami { opacity: 0.6; font-size: 0.85rem; }
  .accounts-toggle { font-size: 0.8rem; width: 100%; margin-top: 0.75rem; }
  .accounts ul { list-style: none; padding: 0; margin: 0.25rem 0; }
  .accounts li { display: flex; justify-content: space-between; align-items: center; font-size: 0.8rem; }
  .account-remove { border: 0; background: transparent; cursor: pointer; color: #b3004c; font-weight: bold; }
  .accounts select { font-size: 0.8rem; padding: 0.25rem; border: 1px solid #d8c7b2; border-radius: 4px; }
  .accounts { border: 1px solid #efe6d8; border-radius: 4px; padding: 0.5rem; }
  .site-settings { border: 1px solid #efe6d8; border-radius: 4px; padding: 0.5rem; display: flex; flex-direction: column; gap: 0.4rem; }
  .setup-note { font-size: 0.85rem; color: #6f5d4f; margin: 0 0 0.75rem; }
  .account-actions { display: inline-flex; gap: 0.35rem; }
  .account-mail { border: 0; background: transparent; cursor: pointer; }
  .account-note { font-size: 0.75rem; opacity: 0.75; margin: 0.25rem 0 0; }
  @media (max-width: 60rem) {
    .workbench { grid-template-columns: 1fr; }
  }
</style>
