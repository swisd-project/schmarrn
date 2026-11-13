<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/ConfirmModal.svelte
   * 2. Description: THE HOUSE CONFIRM (strip 4 of the p8 STEP 6 dashboard
   *                 split; user order 2026-11-12: no system popups, ever —
   *                 every "are you sure" is a modal wearing the house
   *                 pattern). The parent keeps its askConfirm() API untouched
   *                 (five call sites); the request object rides in via
   *                 $bindable and the run/clear lives here.
   * 3. Expects: `request` — $bindable, {title, message, run} | null.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  type Request = { title: string; message: string; run: () => void };

  let { request = $bindable<Request | null>(null) }: { request?: Request | null } = $props();

  function confirmNow(): void {
    const run = request?.run;
    request = null;
    run?.();
  }
</script>

{#if request}
  <!-- the dark closes the modal (house law); ESC is gone by the same word —
       the keyboard path is deliberate absence, not an oversight -->
  <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label={request.title} tabindex="-1" onclick={() => (request = null)}>
    <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_static_element_interactions -->
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>{request.title}</h3>
        <button type="button" class="close-x" onclick={() => (request = null)}>×</button>
      </header>
      <p class="m-0 text-sm">{request.message}</p>
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (request = null)}>Cancel</button>
        <button type="button" class="btn btn-primary" onclick={confirmNow}>Confirm</button>
      </footer>
    </div>
  </div>
{/if}
