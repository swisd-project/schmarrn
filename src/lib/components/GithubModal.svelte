<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/GithubModal.svelte
   * 2. Description: THE GITHUB DOOR (strip 2 of the p8 STEP 6 dashboard
   *                 split). Owns the token, the test, the busy/error slice;
   *                 the connection STATUS and the note ride back to the
   *                 parent through `onchanged` (the menu overlay renders the
   *                 note, and the repo-inspect/install flow stays there).
   *                 Resets itself every time it opens; the parent keeps only
   *                 the boolean.
   * 3. Expects: `open` — $bindable, the modal's visibility; `status` — the
   *             connection state ({connected, login} | null); `onchanged` —
   *             called with the new status + the user-facing note.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { githubConnect, githubDisconnect, githubTest } from '$lib/api';

  let {
    open = $bindable(false),
    status = null,
    onchanged,
  }: {
    open?: boolean;
    status?: { connected: boolean; login: string | null } | null;
    onchanged?: (status: { connected: boolean; login: string | null }, note: string) => void;
  } = $props();

  let token = $state('');
  let tested = $state<{ ok: boolean; login: string | null; error: string } | null>(null);
  let busy = $state(false);
  let error = $state('');

  // the modal resets itself every time it opens
  $effect(() => {
    if (open) {
      token = '';
      tested = null;
      error = '';
    }
  });

  async function testNow() {
    if (busy || token.trim() === '') return;
    busy = true;
    error = '';
    tested = null;
    try {
      tested = await githubTest(token.trim());
      if (!tested.ok) {
        error = tested.error;
      }
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function connectNow() {
    if (busy || token.trim() === '') return;
    busy = true;
    error = '';
    try {
      const fresh = await githubConnect(token.trim());
      token = ''; // the token does not linger in the browser
      tested = null;
      open = false;
      onchanged?.(fresh, `GitHub connected — ${fresh.login}.`);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function disconnectNow() {
    if (busy) return;
    busy = true;
    error = '';
    try {
      const fresh = await githubDisconnect();
      tested = null;
      open = false;
      onchanged?.(fresh, 'The account let go — private repos are out of reach again.');
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if open}
  <!-- the dark closes the modal (house law, 2026-11-09) -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions, a11y_no_noninteractive_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="GitHub connection" tabindex="-1" onclick={() => (open = false)}>
    <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions, a11y_no_noninteractive_element_interactions -->
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>GitHub</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      <label class="field">
        <span>Personal Access Token</span>
        <input
          class="input"
          type="password"
          bind:value={token}
          placeholder="ghp_… or github_pat_…"
          autocomplete="off"
        />
      </label>
      <p class="m-0 text-xs text-ink-soft">
        A token with repo read scope. Public repos work without one — the token only widens the reach to this account's private repos. It rests sealed in the house vault (encrypted at rest), never shown again.
      </p>
      {#if tested?.ok}
        <p class="alert alert-ok m-0">The token answers — it belongs to <b>{tested.login}</b>.</p>
      {/if}
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        {#if status?.connected}
          <button type="button" class="btn btn-secondary" onclick={disconnectNow} disabled={busy}>
            {busy ? '…' : 'Disconnect'}
          </button>
        {:else}
          <button type="button" class="btn btn-secondary" onclick={testNow} disabled={busy || token.trim() === ''}>
            {busy ? 'Asking…' : 'Test connection'}
          </button>
          <button type="button" class="btn btn-primary" onclick={connectNow} disabled={busy || !tested?.ok}>
            {busy ? 'Opening…' : 'Connect'}
          </button>
        {/if}
      </footer>
    </div>
  </div>
{/if}
