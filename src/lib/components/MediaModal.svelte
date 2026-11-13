<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/MediaModal.svelte
   * 2. Description: THE MEDIA PANTRY (strip 6 of the p8 STEP 6 dashboard
   *                 split). Fully self-contained: it sweeps the shelf itself
   *                 every time it opens ($effect — the parent's opener no
   *                 longer has to remember the load), owns the copy-to-
   *                 clipboard note, the busy/error slice, and the grid.
   * 3. Expects: `open` — $bindable, the modal's visibility.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { listAllMedia, type SiteMediaRef } from '$lib/api';
  import { untrack } from 'svelte';

  let { open = $bindable(false) }: { open?: boolean } = $props();

  let items = $state<readonly SiteMediaRef[]>([]);
  let busy = $state(false);
  let error = $state('');
  let note = $state('');

  // the pantry sweeps itself every time it opens. untrack is the law: load()
  // reads and writes busy/items/error — tracked, every state write would
  // re-trigger this effect and the shelf would GET itself to death.
  $effect(() => {
    if (open) {
      untrack(() => void load());
    }
  });

  async function load() {
    if (busy) return;
    busy = true;
    error = '';
    try {
      items = await listAllMedia();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function copyUrl(item: SiteMediaRef) {
    try {
      await navigator.clipboard.writeText(item.url);
      note = `Copied: ${item.name}`;
    } catch {
      error = 'Clipboard refused — copy the URL below by hand.';
      note = item.url;
    }
  }
</script>

{#if open}
  <!-- the dark closes the modal (2026-11-09, user order); ESC is gone by the same word — the keyboard path is deliberate absence, not an oversight -->
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50 p-4" role="dialog" aria-modal="true" aria-label="Media" tabindex="-1" onclick={() => (open = false)}>
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="modal-card" style="width: min(60rem, 94vw)" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Media</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      {#if busy}
        <p class="italic text-ink-soft">Sweeping the pantry…</p>
      {:else if error}
        <p class="alert alert-err m-0" role="alert">{error}</p>
      {:else if items.length === 0}
        <p class="m-0 italic text-ink-soft">No media yet — upload lives inside the image and video modals.</p>
      {:else}
        {#if note}<p class="alert alert-ok m-0">{note}</p>{/if}
        <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(10rem,1fr))]">
          {#each items as item (item.type + '/' + item.slug + '/' + item.name)}
            <button class="card text-left" title={`Copy URL — ${item.url}`} onclick={() => copyUrl(item)}>
              {#if /\.(png|jpe?g|gif|webp|svg)$/i.test(item.name)}
                <img class="w-full aspect-video object-cover" src={item.url} alt={item.name} loading="lazy" />
              {:else}
                <div class="w-full aspect-video bg-crust grid place-items-center text-xs text-ink-soft">{item.name.split('.').pop()?.toUpperCase()}</div>
              {/if}
              <span class="text-xs font-bold truncate">{item.name}</span>
              <span class="text-xs text-ink-soft truncate">{item.slug}</span>
            </button>
          {/each}
        </div>
      {/if}
    </div>
  </div>
{/if}
