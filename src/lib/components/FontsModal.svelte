<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/FontsModal.svelte
   * 2. Description: THE FONT-PACKS MODAL (2026-11-15, user order): the
   *                 settings font field's door to the font-pack station —
   *                 the editor bakes the core Noto subsets; every other
   *                 writing system is listed here and pulled ON DEMAND.
   *                 The browser downloads a subset only when text uses its
   *                 unicode-range, so an installed pack costs nothing until
   *                 its letters appear. The CJK packs are whales — the kb
   *                 hint says so before the admin pulls one.
   * 3. Expects: `open` — $bindable.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { installFontPack, listFontPacks, removeFontPack, type FontPacks } from '$lib/api';

  let { open = $bindable(false) }: { open?: boolean } = $props();

  let packs = $state<FontPacks | null>(null);
  let busy = $state('');
  let error = $state('');

  $effect(() => {
    if (open && packs === null) {
      void load();
    }
    if (!open) {
      error = '';
    }
  });

  async function load() {
    error = '';
    try {
      packs = await listFontPacks();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  async function pull(pack: string) {
    busy = pack;
    error = '';
    try {
      packs = await installFontPack(pack);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = '';
    }
  }

  async function drop(pack: string) {
    busy = pack;
    error = '';
    try {
      packs = await removeFontPack(pack);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = '';
    }
  }
</script>

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Font packs" tabindex="-1" onclick={() => (open = false)}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Font packs</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      <p class="m-0 text-xs text-ink-soft">
        The editor ships the core Noto subsets only. A pack adds one writing
        system to the host; the browser fetches a subset only when text uses
        its letters — nothing is downloaded until it appears.
      </p>
      {#if packs === null}
        <p class="italic text-ink-soft m-0">Reaching for the shelf…</p>
      {:else}
        <ul class="list-none p-0 m-0 flex flex-col gap-1 text-sm">
          <li class="flex items-center justify-between gap-2">
            <span>{packs.baked.label}</span>
            <span class="text-xs text-ink-soft">baked · {packs.baked.kb} KB</span>
          </li>
          {#each packs.installed as pack (pack.pack)}
            <li class="flex items-center justify-between gap-2">
              <span>{pack.label}</span>
              <span class="flex items-center gap-2">
                <span class="text-xs text-ink-soft">{pack.kb} KB</span>
                <button type="button" class="btn-ghost" disabled={busy !== ''} onclick={() => drop(pack.pack)}>Remove</button>
              </span>
            </li>
          {/each}
          {#each packs.available as pack (pack.pack)}
            <li class="flex items-center justify-between gap-2">
              <span>{pack.label}</span>
              <span class="flex items-center gap-2">
                <span class="text-xs text-ink-soft">~{pack.kb} KB</span>
                <button type="button" class="btn-ghost" disabled={busy !== ''} onclick={() => pull(pack.pack)}>
                  {busy === pack.pack ? 'Pulling…' : 'Pull'}
                </button>
              </span>
            </li>
          {/each}
        </ul>
      {/if}
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (open = false)}>Close</button>
      </footer>
    </div>
  </div>
{/if}
