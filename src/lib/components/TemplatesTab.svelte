<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/TemplatesTab.svelte
   * 2. Description: THE TEMPLATES TAB (strip 8d of the p8 STEP 6 dashboard
   *                 split). THE 50/50 SPLIT (2026-11-10, user order): LEFT =
   *                 the fitting room (the preview) with the theme's details
   *                 at its bottom; RIGHT = every theme you could pick —
   *                 installed AND the Vitrine shelf, one list. The rows
   *                 derive here from the templates + the shelf; the shared
   *                 state (the GitHub session, the folds, the broken
   *                 previews, the site settings the activate flow moves)
   *                 re-binds through; the activate/delete rides live here,
   *                 delete through the parent's house confirm.
   * 3. Expects: `templates`, `wares`, `selectedTheme` ($bindable),
   *             `brokenPreviews` ($bindable), `siteSettings` ($bindable),
   *             `folds` + `toggle` (the shared fold map), `previewSlug`,
   *             `vitrineDark/Busy/Error/Note`, `houseShot`, `confirm` (the
   *             house confirm), `install` (one shelf install), the GitHub
   *             session ($bindable) + `onGithubLook` / `onGithubStage`.
   * 4. Provides: The tab. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { activateTemplate, deleteTemplate, listTemplates, type GithubLook, type TemplateRef, type WareRef, type ThemeHandle } from '$lib/api';
  import GithubShelf from './GithubShelf.svelte';

  type SiteSettings = { name: string; claim: string; logo: string; theme: string; home: string; style: string; font: string; handles: readonly ThemeHandle[] };
  type ThemeRow = {
    name: string;
    title: string;
    version: string;
    description: string;
    styles: readonly string[];
    requires: readonly string[];
    missing: readonly string[];
    active: boolean;
    houseDefault: boolean;
    installed: boolean;
    state: 'active' | 'update' | 'installed' | 'installable';
    preview: string | null;
    template: TemplateRef | null;
    ware: WareRef | null;
  };

  let {
    templates,
    wares,
    selectedTheme = $bindable(''),
    brokenPreviews = $bindable<Record<string, boolean>>({}),
    siteSettings = $bindable<SiteSettings | null>(null),
    folds,
    toggle,
    previewSlug = '',
    vitrineDark = '',
    vitrineBusy = false,
    vitrineError = '',
    vitrineNote = '',
    houseShot,
    confirm,
    install,
    refreshShelves,
    githubRepo = $bindable(''),
    githubBusy = $bindable(false),
    githubError = $bindable(''),
    githubLook = $bindable<GithubLook | null>(null),
    onGithubLook,
    onGithubStage,
  }: {
    templates: readonly TemplateRef[];
    wares: readonly WareRef[];
    selectedTheme?: string;
    brokenPreviews?: Record<string, boolean>;
    siteSettings?: SiteSettings | null;
    folds: Record<string, boolean>;
    toggle: (id: string) => void;
    previewSlug?: string;
    vitrineDark?: string;
    vitrineBusy?: boolean;
    vitrineError?: string;
    vitrineNote?: string;
    houseShot: (key: string) => string;
    confirm: (title: string, message: string, run: () => void) => void;
    install: (ware: WareRef) => Promise<void>;
    refreshShelves: () => Promise<void>;
    githubRepo?: string;
    githubBusy?: boolean;
    githubError?: string;
    githubLook?: GithubLook | null;
    onGithubLook: () => void;
    onGithubStage: (path: string) => void;
  } = $props();

  import { toast } from '$lib/toast.svelte';

  let templatesBusy = $state(false);
  let templatesError = $state('');

  const themeRows = $derived.by((): ThemeRow[] => {
    const rows: ThemeRow[] = templates.map((t) => {
      const ware = wares.find((w) => w.kind === 'template' && w.name === t.name);
      return {
        name: t.name,
        title: t.title,
        version: t.version,
        description: t.description,
        styles: t.styles,
        requires: ware?.requires ?? [],
        missing: ware?.missing ?? [],
        active: t.active,
        houseDefault: t.default,
        installed: true,
        state: t.active ? 'active' : (ware?.state === 'update' ? 'update' : 'installed'),
        preview: t.preview ?? null,
        template: t,
        ware: ware ?? null
      };
    });
    for (const w of wares) {
      if (w.kind !== 'template' || w.state === 'installed') continue;
      if (rows.some((r) => r.name === w.name)) continue; // the installed row speaks for it
      rows.push({
        name: w.name,
        title: w.title,
        version: w.version,
        description: w.description,
        styles: w.styles ?? [],
        requires: w.requires,
        missing: w.missing,
        active: false,
        houseDefault: false,
        installed: false,
        state: w.state === 'update' ? 'update' : 'installable',
        preview: w.previewUrl && w.previewUrl !== '' ? w.previewUrl : null,
        template: null,
        ware: w
      });
    }
    return rows;
  });

  // the list, split: what this host HAS on top, the Vitrine's window below
  const installedRows = $derived(themeRows.filter((r) => r.installed));
  const shelfRows = $derived(themeRows.filter((r) => !r.installed));

  // the chosen theme: the picked one, else the live one, else the first on the shelf
  const selectedRow = $derived(
    themeRows.find((r) => r.name === selectedTheme) ?? themeRows.find((r) => r.active) ?? themeRows[0] ?? null
  );

  async function activateNow(template: TemplateRef) {
    if (templatesBusy) return;
    templatesBusy = true;
    templatesError = '';
    try {
      await activateTemplate(template.name);
      templates = await listTemplates();
      // The settings form caches its copy — the theme switch reaches it here.
      if (siteSettings !== null) siteSettings = { ...siteSettings, theme: template.name };
      toast(`${template.title} is the live theme now.`);
    } catch (e) {
      templatesError = e instanceof Error ? e.message : String(e);
    } finally {
      templatesBusy = false;
    }
  }

  function deleteNow(template: TemplateRef) {
    confirm('Delete template', `Delete the ${template.title} template? Pages keep working — the site falls back to a house theme until another is activated.`, () => deleteGo(template));
  }

  async function deleteGo(template: TemplateRef) {
    if (templatesBusy) return;
    templatesBusy = true;
    templatesError = '';
    try {
      await deleteTemplate(template.name);
      templates = await listTemplates();
      await refreshShelves(); // the shelf re-reads: a deleted update face becomes installable again
      toast(`${template.title} left the shelf — deleted.`);
    } catch (e) {
      templatesError = e instanceof Error ? e.message : String(e);
    } finally {
      templatesBusy = false;
    }
  }
</script>

<!-- THE 50/50 SPLIT (2026-11-10, user order): LEFT = the fitting room
     (the preview) with the theme's details at its bottom; RIGHT = every
     theme you could pick — installed AND the Vitrine shelf, one list. -->
<div class="grid grid-cols-1 lg:grid-cols-2 grid-rows-1 gap-4 h-full min-h-0">
  <section class="mg flex flex-col gap-3 min-h-0">
    {#if selectedRow}
      <div class="flex-1 min-h-0 grid grid-rows-1 place-items-center">
        {#if selectedRow.preview && !brokenPreviews[selectedRow.name]}
          <img
            src={selectedRow.preview}
            alt="Preview of {selectedRow.title}"
            class="w-full h-full object-cover"
            onerror={() => (brokenPreviews = { ...brokenPreviews, [selectedRow.name]: true })}
          />
        {:else}
          <img src={houseShot(selectedRow.name)} alt="Preview of {selectedRow.title}" class="w-full h-full object-cover" />
        {/if}
      </div>
      <div class="flex-none flex flex-col gap-2 border-t border-line pt-3">
        <p class="m-0 font-bold">
          {selectedRow.title} <span class="text-xs text-ink-soft">v{selectedRow.version}</span>
          {#if selectedRow.active}<span class="badge badge-live ml-2">active</span>{/if}
          {#if selectedRow.houseDefault}<span class="badge ml-2">house default</span>{/if}
        </p>
        {#if selectedRow.description}<p class="m-0 text-sm text-ink-soft">{selectedRow.description}</p>{/if}
        {#if selectedRow.styles.length > 0}
          <p class="m-0 text-xs text-ink-soft">
            {#each selectedRow.styles as style (style)}<span class="tag mr-1">{style}</span>{/each}
          </p>
        {/if}
        {#if selectedRow.missing.length > 0}
          <p class="m-0 text-xs text-error">
            requires {selectedRow.requires.join(', ')} — missing: {selectedRow.missing.join(', ')}. The dependency must be installed first.
          </p>
        {/if}
        <div class="flex items-center gap-2">
          {#if selectedRow.installed}
            {#if selectedRow.active}
              <span class="btn btn-secondary btn-static">active</span>
            {:else}
              <button
                class="btn btn-primary"
                disabled={templatesBusy}
                onclick={() => selectedRow.template && activateNow(selectedRow.template)}
              >
                {#if templatesBusy}…{:else}Activate{/if}
              </button>
              {#if !selectedRow.houseDefault}
                <button
                  class="btn-ghost danger"
                  title="Delete template"
                  disabled={templatesBusy}
                  onclick={() => selectedRow.template && deleteNow(selectedRow.template)}
                >×</button>
              {/if}
              {#if previewSlug !== ''}
                <a
                  class="btn btn-secondary"
                  href={`/bridge/preview/${selectedRow.name}/pages/${previewSlug}`}
                  target="_blank"
                  rel="noopener"
                  title="Open the fitting room in a new tab"
                >Preview ↗</a>
              {/if}
            {/if}
          {:else if selectedRow.ware}
            <button
              class="btn btn-primary"
              disabled={vitrineBusy || selectedRow.missing.length > 0}
              onclick={() => selectedRow.ware && install(selectedRow.ware)}
            >
              {#if vitrineBusy}…{:else}Install{/if}
            </button>
          {:else if selectedRow.state === 'update'}
            <span class="text-xs text-ink-soft">update waiting — see Updates</span>
          {/if}
        </div>
        {#if templatesError}<p class="alert alert-err m-0" role="alert">{templatesError}</p>{/if}
        </div>
    {:else}
      <p class="italic text-ink-soft">No themes on the shelf yet.</p>
    {/if}
  </section>
  <section class="mg flex flex-col gap-2 min-h-0">
    {#if vitrineDark}
      <p class="alert alert-warn">{vitrineDark}</p>
    {/if}
    {#if templatesBusy && templates.length === 0}
      <p class="italic text-ink-soft">Reading the bake-shop wall…</p>
    {:else if templatesError && templates.length === 0}
      <p class="alert alert-err" role="alert">{templatesError}</p>
    {:else}
      {#snippet themeRow(row: ThemeRow)}
        <!-- a11y: keyboard activation of the card is unwired (X = the one door law); the row's actions stay buttons -->
        <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_noninteractive_element_interactions -->
        <li class="card card-shelf h-36 cursor-pointer" onclick={() => (selectedTheme = row.name)} title="Show {row.title} in the fitting room">
          {#if row.preview && !brokenPreviews[row.name]}
            <img
              src={row.preview}
              alt=""
              class="shelf-shot"
              loading="lazy"
              onerror={() => (brokenPreviews = { ...brokenPreviews, [row.name]: true })}
            />
          {:else}
            <img src={houseShot(row.name)} alt="" class="shelf-shot" loading="lazy" />
          {/if}
          <div class="flex-1 min-w-0 py-2 pr-3 flex flex-col gap-1 overflow-hidden">
            <div class="flex items-start justify-between gap-2">
              <span class="block font-bold truncate {row.name === selectedRow?.name ? 'text-jam' : ''}">
                {row.title} <span class="text-xs text-ink-soft">v{row.version}</span>
              </span>
              {#if row.installed && !row.active && !row.houseDefault}
                <button
                  class="btn-ghost danger shrink-0"
                  title="Delete template"
                  disabled={templatesBusy}
                  onclick={(e) => { e.stopPropagation(); row.template && deleteNow(row.template); }}
                >×</button>
              {/if}
            </div>
            {#if row.description}
              <p class="m-0 text-sm text-ink-soft leading-snug line-clamp-2">{row.description}</p>
            {/if}
            <div class="flex items-center gap-2 mt-auto">
              {#if row.active}
                <span class="btn btn-secondary btn-static shrink-0">active</span>
              {:else if row.installed}
                <button
                  class="btn btn-primary shrink-0"
                  disabled={templatesBusy}
                  onclick={(e) => { e.stopPropagation(); row.template && activateNow(row.template); }}
                >
                  {#if templatesBusy}…{:else}Activate{/if}
                </button>
              {:else if row.ware}
                <button
                  class="btn btn-primary shrink-0"
                  disabled={vitrineBusy || row.missing.length > 0}
                  onclick={(e) => { e.stopPropagation(); row.ware && install(row.ware); }}
                >
                  {#if vitrineBusy}…{:else}Install{/if}
                </button>
              {:else if row.state === 'update'}
                <span class="text-xs text-ink-soft shrink-0">update waiting — see Updates</span>
              {/if}
              <span class="flex-1 min-w-0 truncate text-right">
                {#each row.styles as style (style)}
                  <span class="tag mr-1">{style}</span>
                {/each}
              </span>
            </div>
          </div>
        </li>
      {/snippet}
      <div class="flex-1 min-h-0 overflow-y-auto flex flex-col gap-2">
        <ul class="list-none p-0 m-0 flex flex-col gap-2">
          {#each installedRows as row (row.name)}
            {@render themeRow(row)}
          {/each}
        </ul>
        {#if shelfRows.length > 0}
          <button
            class="w-full flex items-center justify-between gap-3 py-1 my-1 shrink-0 cursor-pointer"
            onclick={() => toggle('templates-vitrine')}
            aria-expanded={folds['templates-vitrine'] === true}
          >
            <span class="text-xs font-bold uppercase tracking-widest text-ink-soft">Vitrine</span>
            <span class="text-xs text-ink-soft">{folds['templates-vitrine'] ? '▲' : '▼'}</span>
          </button>
          {#if folds['templates-vitrine']}
            <ul class="list-none p-0 m-0 flex flex-col gap-2">
              {#each shelfRows as row (row.name)}
                {@render themeRow(row)}
              {/each}
            </ul>
          {/if}
        {/if}
        <GithubShelf
          kind="template"
          bind:repo={githubRepo}
          bind:busy={githubBusy}
          bind:error={githubError}
          bind:look={githubLook}
          open={folds['templates-github'] === true}
          ontoggle={() => toggle('templates-github')}
          onlook={onGithubLook}
          onstage={onGithubStage}
        />
      </div>
    {/if}
    {#if vitrineError}<p class="alert alert-err m-0" role="alert">{vitrineError}</p>{/if}
    {#if vitrineNote}<p class="alert alert-ok m-0">{vitrineNote}</p>{/if}
  </section>
</div>
