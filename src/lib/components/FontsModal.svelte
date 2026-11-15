<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/FontsModal.svelte
   * 2. Description: THE EDITOR'S TICK LIST (2026-11-15, user order, tamed the
   *                 same day: NOTHING moves on a tick — the ticks are
   *                 wishes; the SAVE button is the law that downloads, in
   *                 one batch, immediately. Without saving, no download.)
   *                 The MD-Editor line's edit button — the FACE is locked to
   *                 Noto; what the admin gates through Save: the styles (the
   *                 weights of the variable font) and the languages (the
   *                 script packs). The browser fetches a subset only when
   *                 text uses its unicode-range, so an installed pack costs
   *                 nothing on the wire until its letters appear.
   * 3. Expects: `open` — $bindable.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { installFontPack, listFontPacks, removeFontPack, setFontStyles, type FontPacks } from '$lib/api';

  let { open = $bindable(false) }: { open?: boolean } = $props();

  let packs = $state<FontPacks | null>(null);
  // the wishes: what the ticks say — the server's truth stays untouched until Save
  let wishWeights = $state<number[]>([]);
  let wishPacks = $state<string[]>([]);
  let busy = $state(false);
  let progress = $state('');
  let error = $state('');

  $effect(() => {
    if (open && packs === null) {
      void load();
    }
    if (!open) {
      error = '';
      progress = '';
    }
  });

  async function load() {
    error = '';
    try {
      packs = await listFontPacks();
      wishWeights = [...packs.weights.installed];
      wishPacks = packs.installed.map((p) => p.pack);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  function toggleWeight(weight: number) {
    wishWeights = wishWeights.includes(weight)
      ? wishWeights.filter((w) => w !== weight)
      : [...wishWeights, weight];
  }

  function togglePack(pack: string) {
    wishPacks = wishPacks.includes(pack)
      ? wishPacks.filter((p) => p !== pack)
      : [...wishPacks, pack];
  }

  /** Dirty = the wishes left the server's truth. Only then does Save arm. */
  function dirty(): boolean {
    if (packs === null) return false;
    const w = [...wishWeights].sort((a, b) => a - b);
    const wNow = [...packs.weights.installed].sort((a, b) => a - b);
    if (w.length !== wNow.length || w.some((x, i) => x !== wNow[i])) return true;
    const p = [...wishPacks].sort();
    const pNow = packs.installed.map((x) => x.pack).sort();
    return p.length !== pNow.length || p.some((x, i) => x !== pNow[i]);
  }

  /** THE GATE: one Save, the whole batch, immediately — weights first (the
   *  core sheet re-cuts), then the packs pull/drop one by one, honestly
   *  narrated. A failure keeps the wishes; the truth on the server stands. */
  async function save() {
    if (packs === null || !dirty()) return;
    busy = true;
    error = '';
    try {
      const w = [...wishWeights].sort((a, b) => a - b);
      const wNow = [...packs.weights.installed].sort((a, b) => a - b);
      if (w.join(',') !== wNow.join(',')) {
        progress = 'Baking the styles…';
        packs = await setFontStyles(w);
        wishWeights = [...packs.weights.installed];
      }
      for (const pack of packs.available.map((p) => p.pack)) {
        if (!wishPacks.includes(pack)) continue;
        const label = packs.available.find((p) => p.pack === pack)?.label ?? pack;
        progress = `Pulling ${label}…`;
        packs = await installFontPack(pack);
      }
      for (const pack of packs.installed.map((p) => p.pack)) {
        if (wishPacks.includes(pack)) continue;
        const label = packs.installed.find((p) => p.pack === pack)?.label ?? pack;
        progress = `Dropping ${label}…`;
        packs = await removeFontPack(pack);
      }
      wishWeights = [...packs.weights.installed];
      wishPacks = packs.installed.map((p) => p.pack);
      progress = '';
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
      progress = '';
    } finally {
      busy = false;
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
        <h3>MD-Editor · Noto (face locked)</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      <p class="m-0 text-xs text-ink-soft">
        The face itself is law — Noto, every script it covers. Tick the styles
        and languages you want baked onto the host, then SAVE — nothing
        downloads before the word. The browser fetches a subset only when
        text uses its letters.
      </p>
      {#if packs === null}
        <p class="italic text-ink-soft m-0">Reaching for the shelf…</p>
      {:else}
        <div class="field">
          <span>Styles (weights)</span>
          <div class="flex flex-wrap gap-2 text-sm">
            {#each packs.weights.available as weight (weight)}
              <label class="flex items-center gap-1 cursor-pointer">
                <input type="checkbox" checked={wishWeights.includes(weight)} disabled={busy} onchange={() => toggleWeight(weight)} />
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
                  <input type="checkbox" checked={wishPacks.includes(pack.pack)} disabled={busy} onchange={() => togglePack(pack.pack)} />
                  <span>{pack.label}</span>
                </span>
                <span class="text-xs text-ink-soft">~{pack.kb} KB</span>
              </label>
            {/each}
            {#each packs.installed as pack (pack.pack)}
              <label class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2">
                  <input type="checkbox" checked={wishPacks.includes(pack.pack)} disabled={busy} onchange={() => togglePack(pack.pack)} />
                  <span>{pack.label}</span>
                </span>
                <span class="text-xs text-ink-soft">{pack.kb} KB</span>
              </label>
            {/each}
          </div>
        </div>
      {/if}
      {#if progress}<p class="m-0 text-xs text-ink-soft">{progress}</p>{/if}
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (open = false)}>Close</button>
        <button type="button" class="btn btn-primary" disabled={!dirty() || busy} onclick={save}>
          {busy ? 'Downloading…' : 'Save & download'}
        </button>
      </footer>
    </div>
  </div>
{/if}
