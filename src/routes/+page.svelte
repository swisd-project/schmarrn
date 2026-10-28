<script lang="ts">
  // Login wall + page tree + editor — the Schmarrn kitchen, phase 1.
  import { session, login, logout, pageCount, createPage, listAccounts, addAccount, removeAccount } from '$lib/api';
  import { seedPage } from '$lib/schema';
  import PageEditor from '$lib/components/PageEditor.svelte';
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

  // ——— accounts (admin) ———
  let showAccounts = $state(false);
  let accounts = $state<readonly AccountRef[]>([]);
  let accountAddUser = $state('');
  let accountAddRole = $state<'admin' | 'editor'>('editor');
  let accountAddPassword = $state('');
  let accountError = $state('');

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
      await addAccount(accountAddUser, accountAddPassword, accountAddRole);
      accountAddUser = '';
      accountAddPassword = '';
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
                  <button
                    class:active={selected?.type === type && selected?.slug === page.slug}
                    onclick={() => select(type, page.slug)}
                  >
                    {page.slug}{#if page.draft}<span class="draft">draft</span>{/if}
                  </button>
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
                    <span>{account.user} · {account.role}</span>
                    {#if account.user !== user}
                      <button
                        class="account-remove"
                        aria-label={`Remove account ${account.user}`}
                        onclick={() => deleteAccountNow(account.user)}>×</button
                      >
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
                <select bind:value={accountAddRole}>
                  <option value="editor">editor</option>
                  <option value="admin">admin</option>
                </select>
                <button type="submit">Add account</button>
              </form>
              {#if accountError}<p class="error">{accountError}</p>{/if}
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
      </nav>

      <section class="stage">
        {#if selected}
          {#key `${selected.type}/${selected.slug}`}
            <PageEditor page={selected} onsaved={refreshIndex} />
          {/key}
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
  @media (max-width: 60rem) {
    .workbench { grid-template-columns: 1fr; }
  }
</style>
