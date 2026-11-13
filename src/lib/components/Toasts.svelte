<!--
  1. Relative path: src/lib/components/Toasts.svelte
  2. Description: THE TOAST SHELF — renders the toast hearth's live stack,
     fixed bottom-right, newest last. Click burns one early. Mounted once in
     the dashboard shell (the dragon's face, 2026-11-13 user order).
  3. Expects: Mounted once, above the overlay's z-stack (z-[60]: over modals
     too — a ride's report outranks the form that raised it).
  4. Provides: The shelf. Nothing else.
  5. SPDX-License-Identifier: MPL-2.0
  6. Copyright (c) 2026 Homahuki GmbH
-->

<script lang="ts">
  import { toasts, burnToast } from '$lib/toast.svelte';
</script>

{#if toasts.length > 0}
  <div class="fixed bottom-3 right-3 z-[60] flex flex-col gap-1 items-end" aria-live="polite">
    {#each toasts as t (t.id)}
      <button
        type="button"
        class={`alert ${t.kind === 'ok' ? 'alert-ok' : 'alert-err'} m-0 max-w-80 text-left shadow-lg cursor-pointer`}
        onclick={() => burnToast(t.id)}
        title="Click to dismiss"
      >{t.message}</button>
    {/each}
  </div>
{/if}
