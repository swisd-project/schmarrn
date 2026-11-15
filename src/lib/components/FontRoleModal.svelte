<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/FontRoleModal.svelte
   * 2. Description: THE FONT-ROLE EDITOR (2026-11-15, user order): one line
   *                 of the settings tab's font list — a role the THEME
   *                 declared (Headline (H1, H2, H3), Paragraph (p, a, li, …)).
   *                 The face is a Google font, NAME ONLY; a lookup asks the
   *                 bridge what styles the family offers and the dropdown
   *                 ticks them. Save writes the override (the serving engine
   *                 injects the css2 link + the role rules); Reset hands the
   *                 role back to the theme's default.
   * 3. Expects: `open` — $bindable; `role` — the FontRole; `onsaved`.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { googleFontStyles, saveSiteSettings, type FontRole } from '$lib/api';
  import { untrack } from 'svelte';

  let { open = $bindable(false), role, onsaved }: { open?: boolean; role: FontRole; onsaved?: () => void } = $props();

  let family = $state('');
  let available = $state<readonly string[]>([]);
  let ticked = $state<string[]>([]);
  let lookedUp = $state('');
  let busy = $state(false);
  let error = $state('');

  // every opening seeds from the role's truth: the stored override, else the theme's default
  $effect(() => {
    if (open) {
      family = role.value || role.default;
      ticked = role.styles === ''
        ? (role.value === '' ? ['400'] : [])
        : axisToStyles(role.styles);
      available = [];
      lookedUp = '';
      error = '';
      // untracked: the lookup's own state writes must not re-arm this effect
      untrack(() => void lookUp());
    }
  });

  /** 'wght@300;400' / 'ital,wght@0,300;1,400' → ['300', '400', '300 italic'] */
  function axisToStyles(axis: string): string[] {
    const [, tuples = ''] = axis.split('@');
    const out: string[] = [];
    for (const t of tuples.split(';')) {
      const [ital, w] = t.split(',');
      out.push(ital === '1' ? `${w} italic` : (w ?? t));
    }
    return out;
  }

  async function lookUp() {
    const name = family.trim();
    if (name === '' || (name === lookedUp && available.length > 0)) {
      return;
    }
    busy = true;
    error = '';
    try {
      available = await googleFontStyles(name);
      lookedUp = name;
      // the ticked styles must exist in the family — keep the honest ones
      ticked = ticked.filter((t) => available.includes(t));
      if (ticked.length === 0) {
        ticked = available.includes('400') ? ['400'] : available.slice(0, 1);
      }
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
      available = [];
    } finally {
      busy = false;
    }
  }

  function toggle(style: string) {
    ticked = ticked.includes(style) ? ticked.filter((t) => t !== style) : [...ticked, style];
  }

  async function save() {
    busy = true;
    error = '';
    try {
      await saveSiteSettings({ fonts: { [role.role]: { family: family.trim(), styles: ticked } } });
      open = false;
      onsaved?.();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function reset() {
    busy = true;
    error = '';
    try {
      await saveSiteSettings({ fonts: { [role.role]: '' } });
      open = false;
      onsaved?.();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if open}
  <!-- svelte-ignore a11y_click_events_have_key_events -->
  <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label={`Font for ${role.label}`} tabindex="-1" onclick={() => (open = false)}>
    <!-- svelte-ignore a11y_click_events_have_key_events -->
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <!-- svelte-ignore a11y_no_static_element_interactions -->
    <div class="modal-card" onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>{role.label}</h3>
        <button type="button" class="close-x" onclick={() => (open = false)}>×</button>
      </header>
      <p class="m-0 text-xs text-ink-soft">wears: {role.elements} · the theme's default: {role.default}</p>
      <label class="field">
        <span>Google font (name only)</span>
        <input class="input" bind:value={family} placeholder="e.g. Cormorant Upright" onblur={lookUp} />
      </label>
      {#if available.length > 0}
        <div class="field">
          <span>Styles the family offers — tick what to load</span>
          <div class="flex flex-wrap gap-2">
            {#each available as style (style)}
              <label class="flex items-center gap-1 text-sm cursor-pointer">
                <input type="checkbox" checked={ticked.includes(style)} onchange={() => toggle(style)} />
                <span>{style}</span>
              </label>
            {/each}
          </div>
        </div>
      {:else}
        <p class="m-0 text-xs text-ink-soft">Leave the field to look the styles up.</p>
      {/if}
      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={reset} disabled={busy || role.value === ''}>Theme default</button>
        <button type="button" class="btn btn-primary" onclick={save} disabled={busy || available.length === 0}>Save font</button>
      </footer>
    </div>
  </div>
{/if}
