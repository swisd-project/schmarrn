<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/PluginPanel.svelte
   * 2. Description: THE GENERIC PLUGIN PANEL FACE (slice 5a, 2026-11-13) —
   *                 the plugin hands a descriptor over its own panel route
   *                 and the core renders what it is told, understanding none
   *                 of the plugin's words. One face, two consumers: the
   *                 plugins tab's detail pane and the admin overlay's plugin
   *                 tabs. Fetches itself whenever (name, route) changes;
   *                 owns the busy/note/show/one-field-modal session.
   * 3. Expects: `name`, `route` — the plugin's panel route (both non-empty).
   * 4. Provides: The rendered descriptor. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { fetchPluginPanel, pluginPanelAction, type PluginPanel } from '$lib/api';
  import PanelModal from '$lib/components/PanelModal.svelte';

  let { name, route }: { name: string; route: string } = $props();

  let panelData = $state<PluginPanel | null>(null);
  let panelBusy = $state(false);
  let panelNote = $state('');
  let panelShow = $state<{ label: string; value: string } | null>(null);
  let panelModal = $state<null | { id: string; label: string; type: string; placeholder: string }>(null);

  async function loadPanel() {
    panelData = null;
    panelShow = null;
    try {
      panelData = await fetchPluginPanel(name, route);
    } catch (e) {
      panelNote = e instanceof Error ? e.message : String(e);
    }
  }

  // refetch whenever the panel door changes (tab switch, plugin row change)
  $effect(() => {
    void name;
    void route;
    panelNote = '';
    void loadPanel();
  });

  async function panelAction(action: { id: string; label: string }, rowId = '', value = '') {
    if (panelBusy) return;
    panelBusy = true;
    panelNote = '';
    try {
      const data = await pluginPanelAction(name, route, { action: action.id, id: rowId, value });
      panelData = data;
      panelModal = null;
      panelShow = data.show ? { label: data.show.label, value: data.show.value } : null;
    } catch (e) {
      panelNote = e instanceof Error ? e.message : String(e);
    } finally {
      panelBusy = false;
    }
  }

  async function copyPanelShow() {
    if (!panelShow) return;
    try {
      await navigator.clipboard.writeText(panelShow.value);
      panelNote = 'Copied.';
    } catch {
      panelNote = 'Copy failed — select it by hand.';
    }
  }
</script>

<div class="flex flex-col gap-2">
  {#if panelData?.title}
    <h4 class="m-0 text-sm font-semibold">{panelData.title}</h4>
  {/if}
  {#if panelData?.note}
    <p class="m-0 text-xs text-ink-soft">{panelData.note}</p>
  {/if}
  {#if panelData?.status}
    <div class="flex items-center gap-2 flex-wrap">
      <span class="text-sm font-semibold">{panelData.status.label}</span>
      <span class="badge">{panelData.status.value}</span>
    </div>
  {/if}
  {#if (panelData?.actions?.length ?? 0) > 0}
    <div class="flex items-center gap-2 flex-wrap">
      {#each panelData?.actions ?? [] as a, ai (ai)}
        {#if a.modal}
          {@const modal = a.modal}
          <button
            class="btn btn-secondary"
            disabled={panelBusy}
            onclick={() => {
              panelModal = {
                id: a.id,
                label: modal.label,
                type: modal.type ?? 'text',
                placeholder: modal.placeholder ?? ''
              };
            }}
          >{a.label}</button>
        {:else}
          <button
            class="btn btn-secondary"
            disabled={panelBusy}
            onclick={() => panelAction(a)}
          >{a.label}</button>
        {/if}
      {/each}
    </div>
  {/if}

  {#if panelShow}
    <div class="border border-line p-2 flex flex-col gap-1">
      <p class="m-0 text-xs text-ink-soft">{panelShow.label}</p>
      <code class="text-xs break-all select-all">{panelShow.value}</code>
      <div class="flex gap-2">
        <button class="btn btn-secondary" onclick={copyPanelShow}>Copy</button>
        <button class="btn-ghost" onclick={() => (panelShow = null)}>Dismiss</button>
      </div>
    </div>
  {/if}

  {#if panelData?.list}
    <div class="flex items-center gap-2 text-xs uppercase tracking-wide text-ink-soft">
      <span class="h-px flex-1 bg-line"></span>
      <span>{panelData.list.label ?? ''}</span>
      <span class="h-px flex-1 bg-line"></span>
    </div>
    {#if (panelData.list.rows?.length ?? 0) === 0}
      <p class="m-0 italic text-ink-soft">Nothing here yet.</p>
    {:else}
      <ul class="list-none p-0 m-0 flex flex-col gap-1">
        {#each panelData.list.rows ?? [] as row, i (row.id ?? i)}
          <li class="flex items-center gap-2 text-sm flex-wrap">
            {#each panelData.list.columns ?? [] as col (col.key)}
              <span>{row.cells?.[col.key] ?? ''}</span>
            {/each}
            {#if (row.actions?.length ?? 0) > 0}
              <span class="flex gap-1 ml-auto">
                {#each row.actions ?? [] as ra, ri (ri)}
                  <button
                    class="btn-ghost"
                    disabled={panelBusy}
                    onclick={() => panelAction(ra, row.id)}
                  >{ra.label}</button>
                {/each}
              </span>
            {/if}
          </li>
        {/each}
      </ul>
    {/if}
  {/if}
  {#if panelNote}<p class="m-0 text-xs text-ink-soft">{panelNote}</p>{/if}
</div>

<PanelModal bind:request={panelModal} panel={panelData} bind:busy={panelBusy} bind:note={panelNote}
  onaction={(act, value) => panelAction(act, '', value)} />
