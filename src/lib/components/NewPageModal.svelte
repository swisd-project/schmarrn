<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/NewPageModal.svelte
   * 2. Description: THE NEW-PAGE MODAL (strip 5c of the p8 STEP 6 dashboard
   *                 split). The parent opens it (lazy-loading the scaffolds
   *                 and the theme styles first) and hands the request in;
   *                 the form, the Landing law and the save live here.
   * 3. Expects: `request` — $bindable (the new-page form object); `scaffolds`
   *             — the pagetype list; `templates` — the theme list; `index` —
   *             the page index (the Landing law reads it); `error` —
   *             $bindable (the parent's lazy loaders write it too);
   *             `oncreated` — called with the new slug.
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { createPage, setHomePage, type PagetypeRef, type TemplateRef } from '$lib/api';
  import type { PageIndex } from '$lib/types';

  type Request = { title: string; slug: string; style: string; scaffold: string; asDraft: boolean };

  let {
    request = $bindable<Request | null>(null),
    scaffolds = [],
    templates = [],
    index = null,
    error = $bindable(''),
    oncreated,
  }: {
    request?: Request | null;
    scaffolds?: readonly PagetypeRef[];
    templates?: readonly TemplateRef[];
    index?: PageIndex | null;
    error?: string;
    oncreated?: (slug: string) => void;
  } = $props();

  let busy = $state(false);

  const activeTheme = $derived(templates.find((t) => t.active) ?? null);
  const activeThemeStyles = $derived(activeTheme?.styles ?? []);
  const prettyStyle = (style: string): string =>
    style.split('-').filter(Boolean).map((w) => w[0].toUpperCase() + w.slice(1)).join(' ');

  /** The Landing law (sidequest, 2026-11-07): the index page is the front door —
   *  picking the Landing scaffold while no index page exists preaches 'index'
   *  into the slug field, so the new page lands where the home law looks. */
  function pickScaffold(next: string) {
    const m = request;
    if (m === null) return;
    if (next === 'landing' && m.slug === ''
      && !(index?.['pages'] ?? []).some((p) => p.slug === 'index')) {
      m.slug = 'index';
    }
  }

  async function save(event: SubmitEvent) {
    event.preventDefault();
    const m = request;
    if (m === null) return;
    busy = true;
    error = '';
    try {
      const today = new Date().toISOString().slice(0, 10);
      // JSON.stringify quotes the title — YAML-hostile colons stay harmless.
      // The frontpage style is never written: the crown derives it; choosing
      // frontpage here crowns the new page the moment it exists.
      const styleLine = m.style !== '' && m.style !== 'frontpage' ? `\nstyle: ${m.style}` : '';
      const frontmatter = `---\ntitle: ${JSON.stringify(m.title)}\nslug: ${m.slug}\npublished: true\ndraft: ${m.asDraft}\ndate: ${today}\ntags: []${styleLine}\n---`;
      const scaffold = scaffolds.find((p) => p.name === m.scaffold);
      await createPage('pages', m.slug, `${frontmatter}\n\n${scaffold?.body ?? ''}`.trimEnd() + '\n');
      if (m.style === 'frontpage') await setHomePage(m.slug);
      request = null;
      oncreated?.(m.slug); // onto the live page — fill it there
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if request}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="New page" tabindex="-1" onclick={() => (request = null)}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={save} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>New page</h3>
        <button type="button" class="close-x" onclick={() => (request = null)}>×</button>
      </header>

      <label class="field">
        <span>Title</span>
        <input class="input" bind:value={request.title} required placeholder="Page title" />
      </label>
      <label class="field">
        <span>Slug (URL)</span>
        <input class="input" bind:value={request.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
          title="lowercase letters, digits, hyphens" placeholder="my-tart" />
      </label>
      {#if activeThemeStyles.length > 0}
        <label class="field">
          <span>Style <span class="hint">(the theme's word — {activeTheme?.title})</span></span>
          <select class="input" bind:value={request.style}>
            {#each activeThemeStyles as s (s)}
              <option value={s}>{prettyStyle(s)}</option>
            {/each}
          </select>
        </label>
        {#if request?.style === 'frontpage'}
          <p class="m-0 text-xs text-ink-soft">The frontpage is the home — creating it moves the crown here.</p>
        {/if}
      {/if}
      <label class="field">
        <span>Fill from a page type</span>
        <select class="input" bind:value={request.scaffold} onchange={() => pickScaffold(request?.scaffold ?? 'article')}>
          {#each scaffolds as p (p.name)}
            <option value={p.name}>{p.title}</option>
          {/each}
        </select>
      </label>

      <fieldset class="border border-dashed border-line p-2 flex flex-col gap-1 text-sm">
        <legend class="text-xs text-ink-soft px-1">Serve this page as</legend>
        <label class="check"><input type="radio" bind:group={request.asDraft} value={false} /> Published — visible to the public</label>
        <label class="check"><input type="radio" bind:group={request.asDraft} value={true} /> Draft — editors only until you publish</label>
      </fieldset>

      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (request = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={busy}>{busy ? 'Folding…' : 'Create page'}</button>
      </footer>
    </form>
  </div>
{/if}
