<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/PluginsTab.svelte
   * 2. Description: THE PLUGINS TAB (strip 8e of the p8 STEP 6 dashboard
   *                 split) — the biggest 50/50 split. LEFT = the chosen
   *                 plugin (preview + title/version, description, tags,
   *                 enable/delete under the ENABLED LAW, then the
   *                 documentation / dependencies / settings detail tabs —
   *                 including the plugin's own PANEL: the core renders what
   *                 the plugin hands over and understands none of it). RIGHT
   *                 = every plugin — installed AND the Vitrine shelf, one
   *                 list. The settings draft re-seats itself whenever the
   *                 selection or the shelf changes.
   * 3. Expects: `plugins` ($bindable), `wares`, `brokenPreviews`
   *             ($bindable), `folds` + `toggle`, `vitrineDark/Busy/Error`,
   *             `houseShot`, `confirm`, `install`, the GitHub session
   *             ($bindable) + `onGithubLook` / `onGithubStage`.
   * 4. Provides: The tab. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { deletePlugin, listPlugins, savePluginSettings, setPlugins, type GithubLook, type PluginRef, type WareRef } from '$lib/api';
  import PluginPanel from '$lib/components/PluginPanel.svelte';
  import GithubShelf from './GithubShelf.svelte';
  import PanelModal from './PanelModal.svelte';

  type PluginRow = {
    name: string;
    title: string;
    version: string;
    description: string;
    documentation: string;
    tags: readonly string[];
    requires: readonly string[];
    missing: readonly string[];
    enabled: boolean;
    installed: boolean;
    error?: string;
    state: 'enabled' | 'disabled' | 'update' | 'installable';
    preview: string | null;
    plugin: PluginRef | null;
    ware: WareRef | null;
  };

  let {
    plugins = $bindable<readonly PluginRef[]>([]),
    wares,
    brokenPreviews = $bindable<Record<string, boolean>>({}),
    folds,
    toggle,
    vitrineDark = '',
    vitrineBusy = false,
    vitrineError = '',
    houseShot,
    confirm,
    install,
    githubRepo = $bindable(''),
    githubBusy = $bindable(false),
    githubError = $bindable(''),
    githubLook = $bindable<GithubLook | null>(null),
    onGithubLook,
    onGithubStage,
  }: {
    plugins?: readonly PluginRef[];
    wares: readonly WareRef[];
    brokenPreviews?: Record<string, boolean>;
    folds: Record<string, boolean>;
    toggle: (id: string) => void;
    vitrineDark?: string;
    vitrineBusy?: boolean;
    vitrineError?: string;
    houseShot: (key: string) => string;
    confirm: (title: string, message: string, run: () => void) => void;
    install: (ware: WareRef) => Promise<void>;
    githubRepo?: string;
    githubBusy?: boolean;
    githubError?: string;
    githubLook?: GithubLook | null;
    onGithubLook: () => void;
    onGithubStage: (path: string) => void;
  } = $props();

  import { toast } from '$lib/toast.svelte';

  let selectedPlugin = $state('');
  let pluginDetailTab = $state<'documentation' | 'dependencies' | 'settings'>('documentation');
  // Plugin settings (2026-11-11): the draft mirrors the stored values; the
  // $effect re-seats it whenever the selected plugin (or the shelf) changes.
  let pluginSettingsDraft = $state<Record<string, string | boolean>>({});
  let revealedSecrets = $state<Record<string, boolean>>({});
  let settingsBusy = $state(false);
  let settingsNote = $state('');

  let pluginsBusy = $state(false);
  let pluginsError = $state('');

  const pluginRows = $derived.by((): PluginRow[] => {
    const rows: PluginRow[] = plugins.map((p) => {
      const ware = wares.find((w) => w.kind === 'plugin' && w.name === p.name);
      return {
        name: p.name,
        title: p.title,
        version: p.version,
        description: p.description,
        documentation: p.documentation ?? '',
        tags: p.tags ?? [],
        requires: ware?.requires ?? p.requires ?? [],
        missing: ware?.missing ?? [],
        enabled: p.enabled,
        installed: true,
        error: p.error,
        state: ware?.state === 'update' ? 'update' : p.enabled ? 'enabled' : 'disabled',
        preview: p.preview ?? null,
        plugin: p,
        ware: ware ?? null
      };
    });
    for (const w of wares) {
      if (w.kind !== 'plugin' || w.state === 'installed') continue;
      if (rows.some((r) => r.name === w.name)) continue; // the installed row speaks for it
      rows.push({
        name: w.name,
        title: w.title,
        version: w.version,
        description: w.description,
        documentation: w.documentation ?? '',
        tags: w.tags ?? [],
        requires: w.requires,
        missing: w.missing,
        enabled: false,
        installed: false,
        state: w.state === 'update' ? 'update' : 'installable',
        preview: w.previewUrl && w.previewUrl !== '' ? w.previewUrl : null,
        plugin: null,
        ware: w
      });
    }
    return rows;
  });
  const installedPluginRows = $derived(pluginRows.filter((r) => r.installed));
  const shelfPluginRows = $derived(pluginRows.filter((r) => !r.installed));

  const selectedPluginRow = $derived(
    pluginRows.find((r) => r.name === selectedPlugin) ?? pluginRows[0] ?? null
  );

  $effect(() => {
    const row = selectedPluginRow;
    pluginSettingsDraft = { ...(row?.plugin?.settingsValues ?? {}) };
    revealedSecrets = {};
    settingsNote = '';
  });

  async function savePluginSettingsNow() {
    const row = selectedPluginRow;
    if (!row?.plugin || settingsBusy) return;
    settingsBusy = true;
    settingsNote = '';
    try {
      await savePluginSettings(row.name, { ...pluginSettingsDraft });
      plugins = await listPlugins(); // the shelf re-reads — the draft effect re-seats itself
      settingsNote = 'Saved.';
    } catch (e) {
      settingsNote = e instanceof Error ? e.message : String(e);
    } finally {
      settingsBusy = false;
    }
  }

  /** Delete a plugin — the folder, the lock line and the switch leave together.
   *  No plugin is undeletable: the preinstalled default (hello) goes like any
   *  other. The house-default law is a TEMPLATE law — a plugin is decoration.
   *  THE ENABLED LAW (2026-11-13, user order): an enabled plugin is not
   *  deleted — the buttons refuse, and the server answers 409 besides. */
  function deleteNow(plugin: PluginRef) {
    confirm('Delete plugin', `Delete the ${plugin.title} plugin? Its placeholders and routes leave with it.`, () => deleteGo(plugin));
  }

  async function deleteGo(plugin: PluginRef) {
    if (pluginsBusy) return;
    pluginsBusy = true;
    pluginsError = '';
    try {
      await deletePlugin(plugin.name);
      plugins = await listPlugins();
      toast(`${plugin.title} left the shelf — deleted.`);
    } catch (e) {
      pluginsError = e instanceof Error ? e.message : String(e);
    } finally {
      pluginsBusy = false;
    }
  }

  async function toggleNow(plugin: PluginRef) {
    if (pluginsBusy) return;
    pluginsBusy = true;
    pluginsError = '';
    try {
      const fresh = await listPlugins();
      const enabled: Record<string, boolean> = {};
      for (const p of fresh) enabled[p.name] = p.name === plugin.name ? !p.enabled : p.enabled;
      // The enable law, stated client-side before the server ever answers:
      // enabling a plugin while its dependency sleeps is refused with the diff.
      if (enabled[plugin.name] === true && (plugin.requires ?? []).length > 0) {
        const sleeping = (plugin.requires ?? []).filter((dep) => !fresh.some((p) => p.name === dep && p.enabled));
        if (sleeping.length > 0) {
          pluginsError = `${plugin.title} waits for ${sleeping.join(', ')} to be enabled first — a plugin does not run on a sleeping dependency.`;
          return;
        }
      }
      await setPlugins(enabled);
      plugins = await listPlugins();
      toast(`${plugin.title} ${plugins.find((p) => p.name === plugin.name)?.enabled ? 'enabled' : 'disabled'}.`);
    } catch (e) {
      pluginsError = e instanceof Error ? e.message : String(e);
    } finally {
      pluginsBusy = false;
    }
  }
</script>

<!-- THE 50/50 SPLIT (2026-11-11, user order): LEFT = the chosen plugin
     (preview + title/version, description, tags, buttons, then the
     documentation / dependencies / settings detail tabs); RIGHT = every
     plugin you could pick — installed AND the Vitrine shelf, one list. -->
<div class="grid grid-cols-1 lg:grid-cols-2 grid-rows-1 gap-4 h-full min-h-0">
  <section class="mg flex flex-col gap-3 min-h-0">
    {#if selectedPluginRow}
      <div class="flex-none flex gap-3 items-start">
        {#if selectedPluginRow.preview && !brokenPreviews[selectedPluginRow.name]}
          <img
            src={selectedPluginRow.preview}
            alt="Preview of {selectedPluginRow.title}"
            class="w-20 h-20 object-cover border border-line"
            onerror={() => (brokenPreviews = { ...brokenPreviews, [selectedPluginRow.name]: true })}
          />
        {:else}
          <img src={houseShot(selectedPluginRow.name)} alt="Preview of {selectedPluginRow.title}" class="w-20 h-20 object-cover border border-line" />
        {/if}
        <div class="min-w-0">
          <p class="m-0 font-bold">
            {selectedPluginRow.title} <span class="text-xs text-ink-soft">v{selectedPluginRow.version}</span>
            {#if selectedPluginRow.error}<span class="badge">broken</span>{/if}
            {#if selectedPluginRow.state === 'update'}<span class="badge ml-2">update</span>{/if}
            {#if selectedPluginRow.installed && selectedPluginRow.enabled}<span class="badge badge-live ml-2">enabled</span>{/if}
          </p>
          {#if selectedPluginRow.description}<p class="m-0 text-sm text-ink-soft leading-snug line-clamp-5">{selectedPluginRow.description}</p>{/if}
        </div>
      </div>
      {#if selectedPluginRow.tags.length > 0}
        <p class="m-0 text-xs text-ink-soft">
          {#each selectedPluginRow.tags as tag (tag)}<span class="tag mr-1">{tag}</span>{/each}
        </p>
      {/if}
      <div class="flex-none flex items-center gap-2">
        {#if selectedPluginRow.installed && !selectedPluginRow.error}
          <button
            class="btn {selectedPluginRow.enabled ? 'btn-secondary' : 'btn-primary'}"
            disabled={pluginsBusy}
            onclick={() => selectedPluginRow.plugin && toggleNow(selectedPluginRow.plugin)}
          >
            {#if pluginsBusy}…{:else if selectedPluginRow.enabled}Disable{:else}Enable{/if}
          </button>
        {:else if !selectedPluginRow.installed && selectedPluginRow.ware}
          <button
            class="btn btn-primary"
            disabled={vitrineBusy || selectedPluginRow.missing.length > 0}
            onclick={() => selectedPluginRow.ware && install(selectedPluginRow.ware)}
          >
            {#if vitrineBusy}…{:else}Install{/if}
          </button>
        {:else if selectedPluginRow.state === 'update'}
          <span class="text-xs text-ink-soft">update waiting — see Updates</span>
        {/if}
        {#if selectedPluginRow.installed}
          <!-- THE ENABLED LAW (2026-11-13, user order): enabled plugins
               are not deleted — disable first, then the × wakes -->
          <button
            class="btn-ghost danger"
            title={selectedPluginRow.plugin?.enabled ? 'Enabled — disable it first, then delete' : 'Delete plugin'}
            disabled={pluginsBusy || selectedPluginRow.plugin?.enabled === true}
            onclick={() => selectedPluginRow.plugin && deleteNow(selectedPluginRow.plugin)}
          >×</button>
        {/if}
      </div>
      {#if selectedPluginRow.error}
        <p class="m-0 text-sm text-error">{selectedPluginRow.error}</p>
      {/if}
      {#if pluginsError && !selectedPluginRow.error}<p class="alert alert-err m-0" role="alert">{pluginsError}</p>{/if}
      <div class="flex-none flex flex-col gap-2 border-t border-line pt-3">
        <div class="flex gap-2">
          {#each ['documentation', 'dependencies', 'settings'] as detail (detail)}
            <button
              class="btn {pluginDetailTab === detail ? 'btn-primary' : 'btn-secondary'}"
              onclick={() => (pluginDetailTab = detail as typeof pluginDetailTab)}
            >{detail}</button>
          {/each}
        </div>
        {#if pluginDetailTab === 'documentation'}
          {#if selectedPluginRow.documentation}
            <p class="m-0 text-sm text-ink-soft whitespace-pre-line">{selectedPluginRow.documentation}</p>
          {:else}
            <p class="m-0 italic text-ink-soft">The plugin ships no documentation.</p>
          {/if}
        {:else if pluginDetailTab === 'dependencies'}
          {#if selectedPluginRow.requires.length === 0}
            <p class="m-0 italic text-ink-soft">No dependencies — the plugin stands alone.</p>
          {:else if !selectedPluginRow.installed && selectedPluginRow.missing.length > 0}
            <p class="m-0 text-xs text-error">
              requires {selectedPluginRow.requires.join(', ')} — missing: {selectedPluginRow.missing.join(', ')}. The dependency must be installed first.
            </p>
          {:else if !selectedPluginRow.installed}
            <p class="m-0 text-xs text-ink-soft">requires: {selectedPluginRow.requires.join(', ')} (all on this host ✓)</p>
          {:else}
            <ul class="list-none p-0 m-0 flex flex-col gap-1">
              {#each selectedPluginRow.requires as dep (dep)}
                <li class="text-sm {plugins.some((p) => p.name === dep && p.enabled) ? 'text-ink-soft' : 'text-error'}">
                  {dep} {plugins.some((p) => p.name === dep && p.enabled) ? '✓ enabled' : '— sleeping'}
                </li>
              {/each}
            </ul>
          {/if}
        {:else if !selectedPluginRow.installed || selectedPluginRow.error}
          <p class="m-0 italic text-ink-soft">Install the plugin (enabled, unbroken) to set it up.</p>
        {:else}
          <div class="flex flex-col gap-3">
            {#if selectedPluginRow.plugin?.panel}
              <PluginPanel name={selectedPluginRow.plugin.name} route={selectedPluginRow.plugin.panel.route} />
            {/if}

            {#if (selectedPluginRow.plugin?.settings?.length ?? 0) > 0}
              <form
                class="flex flex-col gap-2"
                onsubmit={(e) => {
                  e.preventDefault();
                  savePluginSettingsNow();
                }}
              >
                {#each selectedPluginRow.plugin!.settings as s (s.key)}
                  {#if s.type === 'toggle'}
                    <label class="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={pluginSettingsDraft[s.key] === true}
                        onchange={(e) => (pluginSettingsDraft[s.key] = e.currentTarget.checked)}
                      />
                      <span>{s.label}</span>
                    </label>
                  {:else}
                    <label class="flex flex-col gap-1 text-sm">
                      <span>{s.label}</span>
                      <span class="flex gap-1">
                        <input
                          class="input flex-1"
                          type={s.type === 'secret' && !revealedSecrets[s.key] ? 'password' : 'text'}
                          bind:value={pluginSettingsDraft[s.key]}
                          autocomplete="off"
                        />
                        {#if s.type === 'secret'}
                          <button
                            type="button"
                            class="btn-ghost"
                            title={revealedSecrets[s.key] ? 'Hide' : 'Show'}
                            onclick={() => (revealedSecrets[s.key] = !revealedSecrets[s.key])}
                          >{revealedSecrets[s.key] ? 'hide' : 'show'}</button>
                        {/if}
                      </span>
                    </label>
                  {/if}
                {/each}
                <div class="flex items-center gap-2">
                  <button class="btn btn-primary" type="submit" disabled={settingsBusy}>
                    {settingsBusy ? 'Saving…' : 'Save settings'}
                  </button>
                  {#if settingsNote}<span class="text-xs text-ink-soft">{settingsNote}</span>{/if}
                </div>
              </form>
            {:else if !selectedPluginRow.plugin?.panel}
              <p class="m-0 italic text-ink-soft">This plugin declares no settings.</p>
            {/if}
          </div>
        {/if}
      </div>
    {:else}
      <p class="italic text-ink-soft">No plugins on the shelf yet.</p>
    {/if}
  </section>
  <section class="mg flex flex-col gap-2 min-h-0">
    {#if vitrineDark}
      <p class="alert alert-warn">{vitrineDark}</p>
    {/if}
    {#if pluginsBusy && plugins.length === 0}
      <p class="italic text-ink-soft">Opening the pantry…</p>
    {:else if pluginsError && plugins.length === 0}
      <p class="alert alert-err" role="alert">{pluginsError}</p>
    {:else}
      {#snippet pluginRow(row: PluginRow)}
        <!-- a11y: keyboard activation of the card is unwired (X = the one door law); the row's actions stay buttons -->
        <!-- svelte-ignore a11y_click_events_have_key_events, a11y_no_noninteractive_element_interactions -->
        <li class="card card-shelf h-36 cursor-pointer" onclick={() => (selectedPlugin = row.name)} title="Show {row.title} in the pantry">
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
              <span class="block font-bold truncate {row.name === selectedPluginRow?.name ? 'text-jam' : ''}">
                {row.title} <span class="text-xs text-ink-soft">v{row.version}</span>
                {#if row.error}<span class="badge">broken</span>{/if}
              </span>
              {#if row.installed}
                <!-- THE ENABLED LAW: enabled plugins are not deleted — disable first -->
                <button
                  class="btn-ghost danger shrink-0"
                  title={row.plugin?.enabled ? 'Enabled — disable it first, then delete' : 'Delete plugin'}
                  disabled={pluginsBusy || row.plugin?.enabled === true}
                  onclick={(e) => { e.stopPropagation(); row.plugin && deleteNow(row.plugin); }}
                >×</button>
              {/if}
            </div>
            {#if row.description}
              <p class="m-0 text-sm text-ink-soft leading-snug line-clamp-3">{row.description}</p>
            {/if}
            <div class="flex items-center gap-2 mt-auto">
              {#if row.installed && !row.error}
                <button
                  class="btn {row.enabled ? 'btn-secondary' : 'btn-primary'} shrink-0"
                  disabled={pluginsBusy}
                  onclick={(e) => { e.stopPropagation(); row.plugin && toggleNow(row.plugin); }}
                >
                  {#if pluginsBusy}…{:else if row.enabled}Disable{:else}Enable{/if}
                </button>
              {:else if !row.installed && row.ware}
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
                {#each row.tags as tag (tag)}
                  <span class="tag mr-1">{tag}</span>
                {/each}
              </span>
            </div>
          </div>
        </li>
      {/snippet}
      <div class="flex-1 min-h-0 overflow-y-auto flex flex-col gap-2">
        <ul class="list-none p-0 m-0 flex flex-col gap-2">
          {#each installedPluginRows as row (row.name)}
            {@render pluginRow(row)}
          {/each}
        </ul>
        {#if shelfPluginRows.length > 0}
          <button
            class="w-full flex items-center justify-between gap-3 py-1 my-1 shrink-0 cursor-pointer"
            onclick={() => toggle('plugins-vitrine')}
            aria-expanded={folds['plugins-vitrine'] === true}
          >
            <span class="text-xs font-bold uppercase tracking-widest text-ink-soft">Vitrine</span>
            <span class="text-xs text-ink-soft">{folds['plugins-vitrine'] ? '▲' : '▼'}</span>
          </button>
          {#if folds['plugins-vitrine']}
            <ul class="list-none p-0 m-0 flex flex-col gap-2">
              {#each shelfPluginRows as row (row.name)}
                {@render pluginRow(row)}
              {/each}
            </ul>
          {/if}
        {/if}
        <GithubShelf
          kind="plugin"
          bind:repo={githubRepo}
          bind:busy={githubBusy}
          bind:error={githubError}
          bind:look={githubLook}
          open={folds['plugins-github'] === true}
          ontoggle={() => toggle('plugins-github')}
          onlook={onGithubLook}
          onstage={onGithubStage}
        />
      </div>
    {/if}
    {#if vitrineError}<p class="alert alert-err m-0" role="alert">{vitrineError}</p>{/if}
  </section>
</div>

