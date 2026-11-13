<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/MailModal.svelte
   * 2. Description: THE SMTP OVEN (strip 3 of the p8 STEP 6 dashboard split).
   *                 The line is dialed with CANDIDATE credentials first —
   *                 nothing is written until the test answers green. Owns
   *                 the form, the test, the busy/error slice; the connection
   *                 STATUS and the note ride back through `onchanged`.
   *                 Resets itself every time it opens — seeding the form
   *                 from the current connection when one exists.
   * 3. Expects: `open` — $bindable; `status` — the mail state
   *             (MailStatus | null); `onchanged` — (status, note).
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { mailConnect, mailDisconnect, mailTest, type MailStatus } from '$lib/api';

  let {
    open = $bindable(false),
    status = null,
    onchanged,
  }: {
    open?: boolean;
    status?: MailStatus | null;
    onchanged?: (status: MailStatus, note: string) => void;
  } = $props();

  let host = $state('');
  let port = $state(587);
  let secure = $state('tls');
  let user = $state('');
  let pass = $state('');
  let fromEmail = $state('');
  let fromName = $state('Schmarrn');
  let tested = $state<{ ok: boolean; error: string } | null>(null);
  let busy = $state(false);
  let error = $state('');

  // the modal resets itself every time it opens — seeding from the current
  // connection when one exists (the password is never seeded; it is unknown)
  $effect(() => {
    if (open) {
      tested = null;
      error = '';
      user = '';
      pass = '';
      const m = status;
      if (m?.configured) {
        host = m.host;
        port = m.port;
        secure = m.secure;
        fromEmail = m.fromEmail;
        fromName = m.fromName;
      }
    }
  });

  async function testNow() {
    if (busy) return;
    busy = true;
    error = '';
    tested = null;
    try {
      tested = await mailTest({
        host: host.trim(),
        port: Number(port) || 587,
        user: user.trim(),
        pass,
        secure,
        fromEmail: fromEmail.trim(),
        fromName: fromName.trim() || 'Schmarrn'
      });
      if (!tested.ok) error = tested.error;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function connectNow() {
    if (busy) return;
    busy = true;
    error = '';
    try {
      const fresh = await mailConnect({
        host: host.trim(),
        port: Number(port) || 587,
        user: user.trim(),
        pass,
        secure,
        fromEmail: fromEmail.trim(),
        fromName: fromName.trim() || 'Schmarrn'
      });
      pass = ''; // the password does not linger in the browser
      tested = null;
      open = false;
      onchanged?.(fresh, 'The oven is plumbed in — invites and password resets can ride.');
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
      const fresh = await mailDisconnect();
      tested = null;
      open = false;
      onchanged?.(fresh, 'The oven let go — mail features are off until it is plumbed in again.');
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if open}
  <!-- the SMTP oven: the line is dialed with CANDIDATE credentials first —
       nothing is written until the test answers green -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Mail connection" tabindex="-1" onclick={() => (open = false)}>
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Mail (SMTP)</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      {#if status?.configured}
        <p class="m-0 text-xs text-ink-soft">
          Plumbed in via <b>{status.host}</b>:<b>{status.port}</b> ({status.secure}), sending as <b>{status.fromEmail || status.host}</b>. The password is never shown again — reconnect to change it.
        </p>
      {/if}
      <label class="field"><span>SMTP host</span><input class="input" bind:value={host} placeholder="mail.example.com" /></label>
      <div class="flex gap-2">
        <label class="field flex-1"><span>Port</span><input class="input" type="number" bind:value={port} min="1" max="65535" /></label>
        <label class="field flex-1"><span>Encryption</span>
          <select class="input" bind:value={secure}>
            <option value="tls">STARTTLS (port 587)</option>
            <option value="ssl">Implicit SSL (port 465)</option>
            <option value="none">None (port 25)</option>
          </select>
        </label>
      </div>
      <label class="field"><span>Username <span class="hint">(empty = no auth)</span></span><input class="input" bind:value={user} autocomplete="off" /></label>
      <label class="field"><span>Password</span><input class="input" type="password" bind:value={pass} autocomplete="new-password" /></label>
      <div class="flex gap-2">
        <label class="field flex-1"><span>From address</span><input class="input" type="email" bind:value={fromEmail} placeholder="schmarrn@example.com" /></label>
        <label class="field flex-1"><span>From name</span><input class="input" bind:value={fromName} /></label>
      </div>
      <p class="m-0 text-xs text-ink-soft">
        This oven carries the house's letters — account invites and password resets. The password is sealed into the house vault (encrypted at rest, master key in a 0600 keyfile) and never shown again.
      </p>
      {#if tested?.ok}
        <p class="alert alert-ok m-0">The line answers and the key turns — the oven is reachable.</p>
      {/if}
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        {#if status?.configured}
          <button type="button" class="btn btn-secondary" onclick={disconnectNow} disabled={busy}>
            {busy ? '…' : 'Disconnect'}
          </button>
        {:else}
          <button type="button" class="btn btn-secondary" onclick={testNow} disabled={busy || host.trim() === ''}>
            {busy ? 'Dialing…' : 'Test connection'}
          </button>
          <button type="button" class="btn btn-primary" onclick={connectNow} disabled={busy || !tested?.ok}>
            {busy ? 'Plumbing…' : 'Connect'}
          </button>
        {/if}
      </footer>
    </div>
  </div>
{/if}
