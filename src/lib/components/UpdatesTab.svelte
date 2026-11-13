<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/UpdatesTab.svelte
   * 2. Description: THE UPDATES TAB (strip 8b of the p8 STEP 6 dashboard
   *                 split). The verdicts derive here from the shelf + the
   *                 core status the parent hands in; the one-knife Update-all
   *                 rides the house order (core, then templates, then
   *                 plugins) and owns its own busy/note slice. The core
   *                 session (ride, park, its notes) stays with the parent —
   *                 the settings tab's attribution card wears the same facts.
   * 3. Expects: `wares` — the registry shelf; `dark` — the registry's
   *             unreachable note; `core` — the core status; `coreError` /
   *             `coreNote` — the core session's notes; `rideCore(source)` —
   *             the parent's knife (answers the ride for update-all's note);
   *             `parkPacket(file)`; `refreshAfterCore()` — loadCore +
   *             loadWares; `installOne(ware)` — one install-or-update ride.
   * 4. Provides: The tab. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import type { WareRef } from '$lib/api';

  type CoreInfo = {
    current: string;
    latest: string | null;
    error: string;
    localVersion: string | null;
    updatable: boolean;
    dashboard: string | null;
    releaseName: string | null;
    published: string | null;
    contributors: readonly { login: string; url: string }[];
  };

  let {
    wares,
    dark = '',
    core = null,
    coreBusy = false,
    coreError = '',
    coreNote = '',
    rideCore,
    parkPacket,
    refreshAfterCore,
    installOne,
  }: {
    wares: readonly WareRef[];
    dark?: string;
    core?: CoreInfo | null;
    coreBusy?: boolean;
    coreError?: string;
    coreNote?: string;
    rideCore: (source: 'hub' | 'local') => Promise<{ from: string; to: string; dashboard: boolean } | null>;
    parkPacket: (file: File) => Promise<void>;
    refreshAfterCore: () => Promise<void>;
    installOne: (ware: WareRef) => Promise<string>;
  } = $props();

  let busy = $state(false);
  let error = $state('');
  let note = $state('');
  let parking = $state(false);

  /** The park input: the file rides to the parent's core session; the input
   *  clears after, so the same file can be re-parked without a browser ruse. */
  async function parkNow(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || parking) return;
    parking = true;
    try {
      await parkPacket(file);
    } finally {
      parking = false;
      input.value = '';
    }
  }

  const templateUpdates = $derived(wares.filter((w) => w.kind === 'template' && w.state === 'update' && w.missing.length === 0));
  const pluginUpdates = $derived(wares.filter((w) => w.kind === 'plugin' && w.state === 'update' && w.missing.length === 0));
  const coreUpdateAvailable = $derived(core !== null && core.updatable);
  const updatesAvailable = $derived(coreUpdateAvailable || templateUpdates.length > 0 || pluginUpdates.length > 0);

  /** ONE ware updated — the same ride, its own notes. */
  async function updateWareNow(ware: WareRef) {
    if (busy) return;
    busy = true;
    error = '';
    note = '';
    try {
      note = await installOne(ware);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  /**
   * UPDATE ALL — the house order: the core rides first when GitHub announces a
   * newer floor, then the templates, then the plugins. Every stop re-reads its
   * shelf; the first break answers and the rest stay for the next round.
   */
  async function updateAllNow() {
    if (busy) return;
    busy = true;
    error = '';
    note = '';
    const done: string[] = [];
    let coreRide: Awaited<ReturnType<typeof rideCore>> = null; // captured before the ride flips the verdict
    try {
      if (coreUpdateAvailable && core !== null) {
        coreRide = await rideCore('hub');
        await refreshAfterCore(); // the registry's host floor may have moved
        if (coreRide !== null) done.push(`core ${coreRide.from} → ${coreRide.to}`);
      }
      for (const name of templateUpdates.map((w) => w.name)) {
        const fresh = wares.find((w) => w.kind === 'template' && w.name === name && w.state === 'update');
        if (fresh) done.push(await installOne(fresh));
      }
      for (const name of pluginUpdates.map((w) => w.name)) {
        const fresh = wares.find((w) => w.kind === 'plugin' && w.name === name && w.state === 'update');
        if (fresh) done.push(await installOne(fresh));
      }
      note = (done.length > 0 ? done.join(' · ') : 'Nothing needed the knife.') + '.';
      if (coreRide !== null && !coreRide.dashboard) note += ' The dashboard did NOT move with the core packet — rebuild it (npm run build) or ride a release that carries its asset zip.';
      else if (coreRide !== null) note += ' Reload the page — the dashboard you are wearing predates the core move.';
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
      if (done.length > 0) note = `Done before the break: ${done.join(' · ')}.`;
    } finally {
      busy = false;
    }
  }
</script>

<section class="mg flex flex-col gap-2">
  <h4 class="mg-h">Updates</h4>
  {#if dark}
    <p class="alert alert-warn">{dark}</p>
  {/if}
  {#if updatesAvailable}
    <div class="flex items-center justify-between gap-3">
      <p class="m-0 text-sm text-ink-soft">
        {#if coreUpdateAvailable}Core{/if}{#if coreUpdateAvailable && (templateUpdates.length > 0 || pluginUpdates.length > 0)} · {/if}{#if templateUpdates.length > 0}{templateUpdates.length} template{templateUpdates.length === 1 ? '' : 's'}{/if}{#if templateUpdates.length > 0 && pluginUpdates.length > 0} · {/if}{#if pluginUpdates.length > 0}{pluginUpdates.length} plugin{pluginUpdates.length === 1 ? '' : 's'}{/if}
        are waiting — the house order is core, then templates, then plugins.
      </p>
      <button class="btn btn-primary shrink-0" disabled={busy} onclick={() => updateAllNow()}>
        {#if busy}Under the knives…{:else}Update all{/if}
      </button>
    </div>
  {/if}
  {#if core !== null && (coreUpdateAvailable || core.localVersion)}
    <div class="card flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="m-0 font-bold">
          Schmarrn core <span class="text-xs text-ink-soft">
            {#if coreUpdateAvailable && core.latest !== null && core.latest !== core.current}v{core.current} → v{core.latest}{:else if coreUpdateAvailable}v{core.current} — the dashboard lags the floor: repair ride{:else if core.localVersion}v{core.current} — packet v{core.localVersion} parked{:else}v{core.current}{/if}
          </span>
        </p>
        <p class="m-0 text-xs text-ink-soft">Core files only — plugins, templates, dressed pagetypes and your settings never move. Visitors see a blank page while the knife is out.</p>
      </div>
      {#if coreUpdateAvailable}
        <button class="btn btn-secondary shrink-0" disabled={busy || coreBusy} onclick={() => rideCore('hub')}>
          {#if busy || coreBusy}…{:else if core.latest !== null && core.latest !== core.current}Core update → {core.latest}{:else}Repair the dashboard{/if}
        </button>
      {/if}
      {#if core.localVersion}
        <button class="btn btn-secondary shrink-0" disabled={busy || coreBusy} onclick={() => rideCore('local')}>
          {#if busy || coreBusy}…{:else}Locally → v{core.localVersion}{/if}
        </button>
      {/if}
    </div>
  {/if}
  {#each templateUpdates as ware (ware.name)}
    <div class="card flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="m-0 font-bold">{ware.title} <span class="text-xs text-ink-soft">v{ware.installedVersion} → v{ware.version}</span></p>
        <p class="m-0 text-xs text-ink-soft">template</p>
      </div>
      <button class="btn btn-secondary shrink-0" disabled={busy} onclick={() => updateWareNow(ware)}>
        {#if busy}…{:else}Update{/if}
      </button>
    </div>
  {/each}
  {#each pluginUpdates as ware (ware.name)}
    <div class="card flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="m-0 font-bold">{ware.title} <span class="text-xs text-ink-soft">v{ware.installedVersion} → v{ware.version}</span></p>
        <p class="m-0 text-xs text-ink-soft">plugin</p>
      </div>
      <button class="btn btn-secondary shrink-0" disabled={busy} onclick={() => updateWareNow(ware)}>
        {#if busy}…{:else}Update{/if}
      </button>
    </div>
  {/each}
  {#if !updatesAvailable && core !== null && !core.localVersion}
    <p class="m-0 italic text-ink-soft">Nothing to update — every shelf is current.</p>
  {/if}
  <label class="btn btn-secondary self-start cursor-pointer">
    <input class="hidden" type="file" accept=".zip" onchange={parkNow} disabled={coreBusy || busy} />
    {#if coreBusy}Parking…{:else}Park a local update packet (.zip){/if}
  </label>
  <p class="m-0 text-xs text-ink-soft">
    No hub answering? Download the release zip on any machine, park it here, and ride the knife from the shelf — an equal version counts as a repair.
  </p>
  {#if coreError}<p class="alert alert-err" role="alert">{coreError}</p>{/if}
  {#if coreNote}<p class="alert alert-ok">{coreNote}</p>{/if}
  {#if error}<p class="alert alert-err" role="alert">{error}</p>{/if}
  {#if note}<p class="alert alert-ok">{note}</p>{/if}
</section>
