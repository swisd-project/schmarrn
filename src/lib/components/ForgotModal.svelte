<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/ForgotModal.svelte
   * 2. Description: THE FORGOT-PASSWORD DOOR (strip 1 of the p8 STEP 6
   *                 dashboard split). Two steps — ask for a token
   *                 (enumeration-proof: the answer never says whether the
   *                 account exists), then spend it. Owns ALL of its state and
   *                 resets itself every time it opens; the parent keeps only
   *                 the boolean.
   * 3. Expects: `open` — $bindable, the modal's visibility.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { resetConfirm, resetRequest } from '$lib/api';

  let { open = $bindable(false) }: { open?: boolean } = $props();

  let step = $state(1); // 1 = ask for the token, 2 = token + new password
  let busy = $state(false);
  let error = $state('');
  let user = $state('');
  let token = $state('');
  let pass = $state('');
  let done = $state(false);
  let noteFromServer = $state('');

  // the modal resets itself every time it opens — the parent cannot forget
  $effect(() => {
    if (open) {
      step = 1;
      error = '';
      user = '';
      token = '';
      pass = '';
      done = false;
    }
  });

  async function requestResetNow() {
    if (busy || user.trim() === '') return;
    busy = true;
    error = '';
    try {
      const res = await resetRequest(user.trim());
      // enumeration-proof: the answer is always the same shape — the step moves on faith
      step = 2;
      noteFromServer = res.message;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function confirmResetNow() {
    if (busy) return;
    busy = true;
    error = '';
    try {
      await resetConfirm(user.trim(), token.trim(), pass);
      done = true;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if open}
  <!-- the forgot-password door: two steps — ask for a token (enumeration-proof:
       the answer never says whether the account exists), then spend it -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Forgot password" tabindex="-1" onclick={() => (open = false)}>
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Forgot password</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      {#if done}
        <p class="alert alert-ok m-0">The lock is turned — log in with the new password.</p>
        <footer class="modal-foot">
          <button type="button" class="btn btn-primary" onclick={() => (open = false)}>Back to login</button>
        </footer>
      {:else if step === 1}
        <label class="field"><span>Account name</span><input class="input" bind:value={user} autocomplete="username" required /></label>
        <p class="m-0 text-xs text-ink-soft">
          If the account exists, has an email on file and the SMTP oven is plumbed in, a one-time reset token rides the mail — valid two hours.
        </p>
        {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
        <footer class="modal-foot">
          <button type="button" class="btn btn-secondary" onclick={() => (open = false)}>Cancel</button>
          <button type="button" class="btn btn-primary" onclick={requestResetNow} disabled={busy || user.trim() === ''}>
            {busy ? 'Sending…' : 'Send token'}
          </button>
        </footer>
      {:else}
        <p class="m-0 text-xs text-ink-soft">{noteFromServer}</p>
        <label class="field"><span>Reset token <span class="hint">(from the mail)</span></span><input class="input" bind:value={token} autocomplete="off" /></label>
        <label class="field"><span>New password</span><input class="input" type="password" bind:value={pass} placeholder="min 8" minlength="8" autocomplete="new-password" required /></label>
        {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
        <footer class="modal-foot">
          <button type="button" class="btn btn-secondary" onclick={() => (open = false)}>Cancel</button>
          <button type="button" class="btn btn-primary" onclick={confirmResetNow} disabled={busy || token.trim() === '' || pass.length < 8}>
            {busy ? 'Turning…' : 'Set password'}
          </button>
        </footer>
      {/if}
    </div>
  </div>
{/if}
