<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/UsersTab.svelte
   * 2. Description: THE USERS TAB (strip 8a of the p8 STEP 6 dashboard split,
   *                 first slice of the menu overlay). Fully self-contained:
   *                 it sweeps the account list itself on mount (the tab's
   *                 {#if} unmounts it on switch — the old loadAccounts()
   *                 trigger died with the strip). Owns the add form, the
   *                 invite/delete actions, error/note. The only prop is the
   *                 session's own name — an account never removes itself.
   * 3. Expects: `user` — the session's own username.
   * 4. Provides: The tab. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { addAccount, inviteAccount, listAccounts, removeAccount, type AccountRef } from '$lib/api';

  let { user }: { user: string } = $props();

  let accounts = $state<readonly AccountRef[]>([]);
  let addUser = $state('');
  let addRole = $state<'admin' | 'editor'>('editor');
  let addPassword = $state('');
  let addEmail = $state('');
  let error = $state('');
  let note = $state('');

  // the tab sweeps itself on mount (the {#if} mounts it fresh every visit)
  $effect(() => {
    void load();
  });

  async function load() {
    try {
      accounts = await listAccounts();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  async function addNow(event: SubmitEvent) {
    event.preventDefault();
    error = '';
    try {
      await addAccount(addUser, addPassword, addRole, addEmail);
      addUser = '';
      addPassword = '';
      addEmail = '';
      accounts = await listAccounts();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  async function deleteNow(name: string) {
    try {
      await removeAccount(name);
      accounts = await listAccounts();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  async function inviteNow(name: string) {
    error = '';
    note = '';
    try {
      await inviteAccount(name);
      note = `Credentials mailed to ${name}.`;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }
</script>

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
            <button class="btn-ghost danger" aria-label={`Remove account ${account.user}`} onclick={() => deleteNow(account.user)}>×</button>
          </span>
        {/if}
      </li>
    {/each}
  </ul>
  {#if error}<p class="alert alert-err" role="alert">{error}</p>{/if}
  {#if note}<p class="alert alert-ok">{note}</p>{/if}
</section>
<form class="mg flex flex-col gap-3 max-w-md" onsubmit={addNow}>
  <h4 class="mg-h">Add account</h4>
  <label class="field"><span>Username</span><input class="input" bind:value={addUser} required /></label>
  <label class="field"><span>Password</span><input class="input" type="password" bind:value={addPassword} placeholder="min 8" minlength="8" required /></label>
  <label class="field"><span>Email <span class="hint">(for invites)</span></span><input class="input" type="email" bind:value={addEmail} /></label>
  <label class="field"><span>Role</span>
    <select class="input" bind:value={addRole}>
      <option value="editor">editor</option>
      <option value="admin">admin</option>
    </select>
  </label>
  <button type="submit" class="btn btn-primary self-start">Add account</button>
</form>
