<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/PanelModal.svelte
   * 2. Description: THE PLUGIN'S ONE-FIELD PANEL MODAL (strip 7 of the p8
   *                 STEP 6 dashboard split) — the plugin asks for one field,
   *                 the action rides the plugin's own panel route. The parent
   *                 owns the panel session (panelData, the row actions, the
   *                 show-copy card); this strip owns the field and hands the
   *                 typed value back through `onaction(action, value)`.
   *                 `busy` and `note` are $bindable — the plugins tab reads
   *                 and renders both outside the modal. The field resets
   *                 itself when the modal closes.
   * 3. Expects: `request` — $bindable ({id,label,type,placeholder} | null);
   *             `panel` — the plugin's panel descriptor (the action lookup);
   *             `busy` — $bindable; `note` — $bindable; `onaction`.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import type { PluginPanel } from '$lib/api';

  type Request = { id: string; label: string; type: string; placeholder: string };

  let {
    request = $bindable<Request | null>(null),
    panel = null,
    busy = $bindable(false),
    note = $bindable(''),
    onaction,
  }: {
    request?: Request | null;
    panel?: PluginPanel | null;
    busy?: boolean;
    note?: string;
    onaction?: (action: { id: string; label: string }, value: string) => void;
  } = $props();

  let value = $state('');

  // the field empties itself whenever the modal closes (parent sets request
  // to null on success too — one reset law, one place)
  $effect(() => {
    if (request === null) {
      value = '';
    }
  });

  function save(event: SubmitEvent) {
    event.preventDefault();
    const act = panel?.actions?.find((a) => a.id === request?.id);
    if (act) {
      onaction?.(act, value);
    }
  }
</script>

{#if request}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label={request.label} tabindex="-1" onclick={() => (request = null)}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={save} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>{request.label}</h3>
        <button type="button" class="close-x" onclick={() => (request = null)}>×</button>
      </header>
      <label class="field">
        <span>{request.label}</span>
        <input
          class="input"
          type={request.type}
          bind:value
          placeholder={request.placeholder}
          required
          autocomplete="off"
        />
      </label>
      {#if note}<p class="alert alert-err m-0" role="alert">{note}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (request = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={busy}>Save</button>
      </footer>
    </form>
  </div>
{/if}
