<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/UsersAddModal.svelte
   * 2. Description: THE ADD-USER MODAL (the users panel's door) — the form
   *                 the old Users tab carried, one modal: username, password
   *                 (min 8), email (for invites), role. The fields reset
   *                 themselves when the modal closes; `oncreated` fires after
   *                 the account landed (the panel re-sweeps).
   * 3. Expects: `open` — $bindable; `oncreated`.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { addAccount } from '$lib/api';

  let { open = $bindable(false), oncreated }: { open?: boolean; oncreated?: () => void } = $props();

  let addUser = $state('');
  let addPassword = $state('');
  let addEmail = $state('');
  let addRole = $state<'admin' | 'editor'>('editor');
  let busy = $state(false);
  let error = $state('');

  // the fields empty themselves whenever the modal closes — one reset law
  $effect(() => {
    if (!open) {
      addUser = '';
      addPassword = '';
      addEmail = '';
      addRole = 'editor';
      error = '';
    }
  });

  async function addNow(event: SubmitEvent) {
    event.preventDefault();
    busy = true;
    error = '';
    try {
      await addAccount(addUser, addPassword, addRole, addEmail);
      open = false;
      oncreated?.();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Add user" tabindex="-1" onclick={() => (open = false)}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={addNow} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Add user</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      <label class="field"><span>Username</span><input class="input" bind:value={addUser} required autocomplete="off" /></label>
      <label class="field"><span>Password</span><input class="input" type="password" bind:value={addPassword} placeholder="min 8" minlength="8" required autocomplete="new-password" /></label>
      <label class="field"><span>Email <span class="hint">(for invites)</span></span><input class="input" type="email" bind:value={addEmail} autocomplete="off" /></label>
      <label class="field"><span>Role</span>
        <select class="input" bind:value={addRole}>
          <option value="editor">editor</option>
          <option value="admin">admin</option>
        </select>
      </label>
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (open = false)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={busy}>{busy ? 'Adding…' : 'Add user'}</button>
      </footer>
    </form>
  </div>
{/if}
