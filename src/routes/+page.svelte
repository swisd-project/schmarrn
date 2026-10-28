<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen, phase 1.
  import { session, login, logout, pageCount, createPage, deletePage, renamePage, listAccounts, addAccount, removeAccount, inviteAccount } from '$lib/api';
  import { seedPage } from '$lib/schema';
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

  // establish session state once
  $effect(() => {
    session()
      .then((s) => {
        authenticated = s.authenticated;
        user = s.user;
        role = s.role;
      })
      .catch(() => (authenticated = false));
  });

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

  // ——— new page ———
  let newType = $state('pages');
  let newSlug = $state('');
  let creating = $state(false);
  let createError = $state('');

  async function createNew(event: SubmitEvent) {
    event.preventDefault();
    creating = true;
    createError = '';
    const type = newType.trim().toLowerCase();
    const slug = newSlug.trim().toLowerCase();
    try {
      await createPage(type, slug, seedPage(type, slug));
      refreshIndex();
      select(type, slug);
      newSlug = '';
    } catch (e) {
      createError = e instanceof Error ? e.message : String(e);
    } finally {
      creating = false;
    }
  }

  // ——— upload .md → new page (route a); the tree button (route b) stays below ———
  let uploadError = $state('');

  async function uploadMd(event: Event) {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) return;
    uploadError = '';
    const base = file.name.replace(/\.(md|markdown)$/i, '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
    const slug = base || `uploaded-${Date.now()}`;
    const type = 'pages';
    try {
      await createPage(type, slug, await file.text());
      refreshIndex();
      select(type, slug);
    } catch (e) {
      uploadError = e instanceof Error ? e.message : String(e);
    }
  }

  // ——— delete + rename per page ———
  let renaming = $state<{ type: string; slug: string; value: string } | null>(null);

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
      error = e instanceof Error ? e.message : String(e);
    }
  }
</script>

<main class="shell">
  {#if authenticated === null}
    <p aria-live="polite">Reaching for the pantry…</p>
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
                    <form class="rename" onsubmit={commitRename}>
                      <input bind:value={renaming.value} pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
                      <button type="submit">✓</button>
                      <button type="button" onclick={() => (renaming = null)}>×</button>
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

        <form class="newpage" onsubmit={createNew}>
          <input bind:value={newType} placeholder="type (e.g. pages)" required />
          <input bind:value={newSlug} placeholder="slug (e.g. my-tart)" required
            pattern="[a-z0-9]+(-[a-z0-9]+)*" title="lowercase, digits, hyphens" />
          <button type="submit" disabled={creating}>{creating ? 'Folding…' : 'New page'}</button>
          {#if createError}<p class="error">{createError}</p>{/if}
        </form>

        <label class="upload-md">
          <input type="file" accept=".md,.markdown,text/markdown" onchange={uploadMd} />
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
  .rename { display: flex; gap: 0.2rem; width: 100%; }
  .rename input { width: 100%; font-size: 0.8rem; padding: 0.15rem 0.3rem; border: 1px solid #d8c7b2; border-radius: 4px; }
  .upload-md { display: block; margin-top: 0.75rem; font-size: 0.8rem; opacity: 0.8; cursor: pointer; }
  .upload-md input { display: none; }
  .upload-md:hover { opacity: 1; }
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
  .account-actions { display: inline-flex; gap: 0.35rem; }
  .account-mail { border: 0; background: transparent; cursor: pointer; }
  .account-note { font-size: 0.75rem; opacity: 0.75; margin: 0.25rem 0 0; }
  @media (max-width: 60rem) {
    .workbench { grid-template-columns: 1fr; }
  }
</style>
