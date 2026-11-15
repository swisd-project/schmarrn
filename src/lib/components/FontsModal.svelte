<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/FontsModal.svelte
   * 2. Description: THE EDITOR'S TICK LIST (2026-11-15, user order): the
   *                 MD-Editor line's edit button — the face is LOCKED to
   *                 Noto; what the admin ticks here is what gets DOWNLOADED:
   *                 the styles (the weights of the variable font) and the
   *                 languages (the script packs). Every tick acts at once —
   *                 the browser fetches a subset only when text uses its
   *                 unicode-range, so a ticked pack costs nothing on the
   *                 wire until its letters appear.
   * 3. Expects: `open` — $bindable.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { installFontPack, listFontPacks, removeFontPack, setFontStyles, type FontPacks } from '$lib/api';

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

  async function toggleWeight(weight: number) {
    if (packs === null) return;
    busy = 'w' + weight;
    error = '';
    const next = packs.weights.installed.includes(weight)
      ? packs.weights.installed.filter((w) => w !== weight)
      : [...packs.weights.installed, weight];
    try {
      packs = await setFontStyles(next);
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
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Editor fonts" tabindex="-1" onclick={() => (open = false)}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>MD-Editor · Noto (locked)</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      <p class="m-0 text-xs text-ink-soft">
        The face itself is law — Noto, every script it covers. Tick the styles
        and languages to bake onto the host; the browser fetches a subset only
        when text uses its letters.
      </p>
      {#if packs === null}
        <p class="italic text-ink-soft m-0">Reaching for the shelf…</p>
      {:else}
        <div class="field">
          <span>Styles (weights)</span>
          <div class="flex flex-wrap gap-2 text-sm">
            {#each packs.weights.available as weight (weight)}
              <label class="flex items-center gap-1 cursor-pointer">
                <input
                  type="checkbox"
                  checked={packs.weights.installed.includes(weight)}
                  disabled={busy !== ''}
                  onchange={() => toggleWeight(weight)}
                />
                <span>{weight}</span>
              </label>
            {/each}
          </div>
        </div>
        <div class="field">
          <span>Languages (script packs)</span>
          <div class="flex flex-col gap-1 text-sm">
            {#each packs.available as pack (pack.pack)}
              <label class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                  <input type="checkbox" checked={false} disabled={busy !== ''} onchange={() => pull(pack.pack)} />
                  <span>{pack.label}</span>
                </span>
                <span class="text-xs text-ink-soft">~{pack.kb} KB</span>
              </label>
            {/each}
            {#each packs.installed as pack (pack.pack)}
              <label class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                  <input type="checkbox" checked disabled={busy !== ''} onchange={() => drop(pack.pack)} />
                  <span>{pack.label}</span>
                </span>
                <span class="text-xs text-ink-soft">{pack.kb} KB</span>
              </label>
            {/each}
          </div>
        </div>
      {/if}
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (open = false)}>Close</button>
      </footer>
    </div>
  </div>
{/if}
