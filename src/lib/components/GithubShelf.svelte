<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/GithubShelf.svelte
   * 2. Description: THE GITHUB SHELF (2026-11-12, user order; extracted as
   *                 the shared piece of the p8 STEP 6 split) — under the
   *                 Vitrine, one foldable row that takes a repo URL. The LOOK
   *                 reads the manifest and tells what the repo holds; STAGE
   *                 copies it onto the shelf — lided, DISABLED, its code
   *                 UNRUN until the admin enables it. Rendered by BOTH the
   *                 templates and the plugins tab — every piece of state is
   *                 $bindable so the two instances share one truth.
   * 3. Expects: `kind` — which shelf it feeds; `repo`, `busy`, `error`,
   *             `note`, `look` — $bindable, the shared GitHub session;
   *             `open` / `ontoggle` — the fold; `onlook` / `onstage(path)`.
   * 4. Provides: The foldable shelf row. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import type { GithubLook } from '$lib/api';

  let {
    kind,
    repo = $bindable(''),
    busy = $bindable(false),
    error = $bindable(''),
    note = $bindable(''),
    look = $bindable<GithubLook | null>(null),
    open = false,
    ontoggle,
    onlook,
    onstage,
  }: {
    kind: 'plugin' | 'template';
    repo?: string;
    busy?: boolean;
    error?: string;
    note?: string;
    look?: GithubLook | null;
    open?: boolean;
    ontoggle?: () => void;
    onlook?: () => void;
    onstage?: (path: string) => void;
  } = $props();
</script>

<!-- the foldable section line: the triangle rides the very right —
     down while closed, up while open -->
<button
  class="w-full flex items-center justify-between gap-3 py-1 my-1 shrink-0 cursor-pointer"
  onclick={() => ontoggle?.()}
  aria-expanded={open}
>
  <span class="text-xs font-bold uppercase tracking-widest text-ink-soft">GitHub</span>
  <span class="text-xs text-ink-soft">{open ? '▲' : '▼'}</span>
</button>
{#if open}
  <div class="card p-3 flex flex-col gap-2 shrink-0">
    <label class="field">
      <span>Repository</span>
      <input class="input" bind:value={repo} placeholder="owner/repo or a GitHub URL" />
    </label>
    <div class="flex items-center gap-2 flex-wrap">
      <button class="btn btn-secondary" disabled={busy || repo.trim() === ''} onclick={() => onlook?.()}>
        {busy ? 'Looking…' : 'Look'}
      </button>
    </div>
    {#if look}
      {#if look.error}
        <p class="m-0 text-xs text-error">{look.error}</p>
      {:else}
        <p class="m-0 text-xs text-ink-soft">
          {look.repo}
          {#if look.private}<span class="badge">private</span>{/if}
        </p>
        <ul class="list-none p-0 m-0 flex flex-col gap-1">
          {#each look.wares ?? [] as ware (ware.kind + ':' + ware.path)}
            <li class="flex items-center justify-between gap-2 text-xs">
              <span class="min-w-0 truncate">
                <span class="badge">{ware.kind}</span>
                {ware.name ?? '—'}
                {#if ware.version}<span class="text-ink-soft">v{ware.version}</span>{/if}
                {#if ware.path}<span class="text-ink-soft">({ware.path})</span>{/if}
                {#if ware.error}<span class="text-error"> — {ware.error}</span>{/if}
              </span>
              {#if !ware.error && ware.kind === kind}
                <button
                  class="btn btn-primary shrink-0"
                  disabled={busy}
                  onclick={() => onstage?.(ware.path ?? '')}
                >Stage</button>
              {/if}
            </li>
          {/each}
        </ul>
      {/if}
    {/if}
    {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
    {#if note}<p class="alert alert-ok m-0">{note}</p>{/if}
    <p class="m-0 text-xs text-ink-soft">
      A staged ware lands DISABLED — its code does not run until you enable it with your own hand.
    </p>
  </div>
{/if}
