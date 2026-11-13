<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/ContentsTab.svelte
   * 2. Description: THE CONTENTS DESK (strip 8f of the p8 STEP 6 dashboard
   *                 split — the last tab). The tree (needle + tag + rows)
   *                 and the raw sheet: the desk edits the DRAFT
   *                 (getDraftRaw/putDraftRaw), the hash guard refuses to
   *                 trample a concurrent editor, save exists only when the
   *                 text actually changed, Ctrl/Cmd+S saves. THE LOCK LAW
   *                 (2026-11-07): raw markdown is surgery — every page
   *                 arrives locked, the lock returns on save. THE CURSOR
   *                 LAW, FLIPPED (2026-11-13, user order): locked sheet =
   *                 the rows' edit verbs are out of reach; unlocked = they
   *                 invite the hand — a user cannot unlock two files at a
   *                 time. THE SURGERY FLAG (2026-11-12): the unlocked state
   *                 rides sessionStorage to the live page. Rename and delete
   *                 (confirm through the parent's house confirm) ride here
   *                 too; `index` is $bindable — every ride re-sweeps it.
   * 3. Expects: `index` — $bindable (the page index); `confirm` — the house
   *             confirm; `openPageSettings(type, slug)`; `openNew()`;
   *             `onupload(event)` — the parent's pick+parse; `onmedia()` —
   *             opens the media modal; `openLive(type, slug)` — onto the
   *             live page.
   * 4. Provides: The tab. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { deletePage, getDraftRaw, pageCount, putDraftRaw, renamePage, searchPages, type PageIndex, type PageRef } from '$lib/api';

  let {
    index = $bindable<PageIndex | null>(null),
    confirm,
    openPageSettings,
    openNew,
    onupload,
    onmedia,
    openLive,
    uploadError = '',
  }: {
    index?: PageIndex | null;
    confirm: (title: string, message: string, run: () => void) => void;
    openPageSettings: (type: string, slug: string) => void;
    openNew: () => void;
    onupload: (event: Event) => void;
    onmedia: () => void;
    openLive: (type: string, slug: string) => void;
    uploadError?: string;
  } = $props();

  // ——— the desk's search + tag filter (2026-11-09, user order) ———
  let searchQ = $state('');
  let tagFilter = $state('');
  let searchBusy = $state(false);
  let searchHits = $state<{ q: string; types: PageIndex } | null>(null); // keyed by the query it answered
  let searchTimer: ReturnType<typeof setTimeout> | undefined;
  let deskError = $state('');
  let deskNote = $state('');

  const activeQ = $derived(searchQ.trim());
  const allTags = $derived.by(() => {
    const tags = new Set<string>();
    for (const pages of Object.values(index ?? {})) {
      for (const p of pages) for (const t of p.tags ?? []) tags.add(t);
    }
    return [...tags].sort((a, b) => a.localeCompare(b));
  });
  const deskRows = $derived.by(() => {
    // the needle's own answers while they are current; the stale list holds
    // the fort while a newer one is in flight — no flicker, no blank flash
    const source: PageIndex =
      activeQ === '' ? (index ?? {}) : searchHits?.q === activeQ ? searchHits.types : (searchHits?.types ?? {});
    const rows: { key: string; type: string; page: PageRef }[] = [];
    for (const [type, pages] of Object.entries(source)) {
      for (const p of pages) {
        if (tagFilter !== '' && !(p.tags ?? []).includes(tagFilter)) continue;
        rows.push({ key: `${type}/${p.slug}`, type, page: p });
      }
    }
    return rows;
  });

  function onSearchInput() {
    clearTimeout(searchTimer);
    const q = searchQ.trim();
    if (q === '') {
      searchHits = null;
      searchBusy = false;
      return;
    }
    searchBusy = true;
    searchTimer = setTimeout(() => {
      searchPages(q)
        .then((types) => {
          if (searchQ.trim() === q) searchHits = { q, types }; // a stale answer never lands
        })
        .catch((e) => (deskError = e instanceof Error ? e.message : String(e)))
        .finally(() => {
          if (searchQ.trim() === q) searchBusy = false;
        });
    }, 250);
  }

  // ——— the raw desk: the filetree picks, the sheet edits the DRAFT ———
  let sel = $state<null | { type: string; slug: string }>(null);
  let raw = $state('');
  let baseline = $state('');
  let baseHash = $state('');
  let deskBusy = $state(false);
  // The sheet locks by law (2026-11-07): raw markdown is surgery — it needs an
  // explicit Unlock, and the lock returns the moment the page is saved. Every
  // freshly opened page arrives locked; switching pages locks too. The lock
  // gates NEW edits only — pending changes stay saveable (and dirty stays
  // truthful) even after a manual re-lock.
  let unlocked = $state(false);
  // ——— the surgery flag (2026-11-12, user order): while the raw sheet is
  // unlocked, the flag rides sessionStorage to the live page — the visual
  // editor there shows the disabled cursor on its content edit buttons and
  // stays deaf. Cleared on Lock/save (unlocked=false) and on the desk's next
  // boot; it survives the ride to /_page on purpose — the surgery is still open.
  $effect(() => {
    if (unlocked && sel !== null) {
      sessionStorage.setItem('sm-raw-surgery', `${sel.type}/${sel.slug}`);
    } else {
      sessionStorage.removeItem('sm-raw-surgery');
    }
  });
  const dirty = $derived(sel !== null && raw !== baseline);
  const selPath = $derived(sel === null ? '' : `${sel.type}/${sel.slug}/index.md`);

  async function openRaw(type: string, slug: string) {
    if (sel?.type === type && sel.slug === slug) return;
    if (dirty) {
      confirm('Unsaved changes', `Unsaved changes on ${selPath} — leave without saving?`, () => openRawGo(type, slug));
      return;
    }
    openRawGo(type, slug);
  }

  async function openRawGo(type: string, slug: string) {
    deskBusy = true;
    deskError = '';
    deskNote = '';
    unlocked = false; // every page arrives locked — surgery needs its own yes
    try {
      const d = await getDraftRaw(type, slug);
      sel = { type, slug };
      raw = d.raw;
      baseline = d.raw;
      baseHash = d.hash;
    } catch (e) {
      deskError = e instanceof Error ? e.message : String(e);
    } finally {
      deskBusy = false;
    }
  }

  async function saveRaw() {
    if (!dirty || deskBusy || sel === null) return;
    deskBusy = true;
    deskError = '';
    deskNote = '';
    try {
      baseHash = await putDraftRaw(sel.type, sel.slug, raw, baseHash);
      baseline = raw;
      unlocked = false; // the lock returns when the page is saved — the law
      deskNote = 'Draft saved — publish from ✑ when it is ready for the public face.';
      index = await pageCount(); // the tree's draft/edits badges re-read
      if (searchQ.trim() !== '') onSearchInput(); // the needle re-runs — hits re-read
    } catch (e) {
      deskError = e instanceof Error ? e.message : String(e);
    } finally {
      deskBusy = false;
    }
  }

  function deskKeydown(event: KeyboardEvent) {
    // Ctrl/Cmd+S saves the draft — the one reflex a text desk owes its writer.
    if ((event.ctrlKey || event.metaKey) && event.key === 's') {
      event.preventDefault();
      void saveRaw();
    }
  }

  // ——— rename per page (the ✎ inline form) ———
  let renaming = $state<{ type: string; slug: string; value: string } | null>(null);
  let renameError = $state('');

  function startRename(type: string, slug: string) {
    renameError = '';
    renaming = { type, slug, value: slug };
  }

  async function commitRename(event: SubmitEvent) {
    event.preventDefault();
    if (renaming === null) return;
    const { type, slug, value } = renaming;
    const to = value.trim().toLowerCase();
    try {
      await renamePage(type, slug, to);
      renaming = null;
      index = await pageCount();
    } catch (e) {
      renameError = e instanceof Error ? e.message : String(e);
    }
  }

  // ——— delete per page: the home stands (server lid too); a delete clears
  // the sheet when it was the deleted page's — the desk never haunts a ghost.
  function removePage(type: string, slug: string) {
    confirm('Delete page', `Delete ${type}/${slug} for good? No undo, no tears.`, () => removePageGo(type, slug));
  }

  async function removePageGo(type: string, slug: string) {
    try {
      await deletePage(type, slug);
      if (sel?.type === type && sel?.slug === slug) {
        sel = null;
        raw = '';
        baseline = '';
        baseHash = '';
        unlocked = false;
      }
      index = await pageCount();
    } catch (e) {
      deskError = e instanceof Error ? e.message : String(e);
    }
  }
</script>

<div class="sm-desk">
  <!-- the tree: every md file in use — no type headlines (2026-11-09,
       user order: redundant); a needle and a tag do the finding. The
       draft state rides as badges; the row verbs stay in the margin -->
  <aside class="sm-desk-tree">
    <div class="flex flex-col gap-1">
      <input
        class="input"
        type="search"
        placeholder="Search the pages…"
        aria-label="Search page text"
        bind:value={searchQ}
        oninput={onSearchInput}
      />
      <select class="input" aria-label="Filter by tag" bind:value={tagFilter}>
        <option value="">All tags</option>
        {#each allTags as t (t)}<option value={t}>{t}</option>{/each}
      </select>
    </div>
    <div class="sm-desk-tree-list">
      {#if index === null}
        <p class="italic text-ink-soft">Opening the drawer…</p>
      {:else if deskRows.length === 0}
        <p class="italic text-ink-soft">
          {#if searchBusy}Searching…{:else}Nothing found{activeQ !== '' ? ` for “${activeQ}”` : ''}{tagFilter !== '' ? ` tagged “${tagFilter}”` : ''}.{/if}
        </p>
      {:else}
        <ul class="list-none p-0 m-0 flex flex-col gap-1">
          {#each deskRows as row (row.key)}
            <li>
              <div class="flex items-center gap-1">
                <button
                  class="btn btn-secondary flex-1 justify-start min-w-0"
                  class:btn-primary={sel?.type === row.type && sel?.slug === row.page.slug}
                  title={`${row.type}/${row.page.slug}/index.md`}
                  onclick={() => openRaw(row.type, row.page.slug)}
                >
                  <span class="truncate">{row.page.slug}</span>
                  {#if row.page.home}<span class="badge">home</span>{/if}
                  {#if row.page.draft}<span class="badge badge-draft">draft</span>{/if}
                  {#if row.page.hasDraft}<span class="badge badge-live">edits</span>{/if}
                </button>
                <!-- THE CURSOR LAW, FLIPPED (2026-11-13, user order):
                     LOCKED sheet = the row's edit verbs are out of
                     reach (not-allowed); UNLOCKED = they invite the
                     hand. A user cannot unlock two files at a time —
                     the eye reads which file is in surgery. -->
                <button class="btn-ghost" class:cursor-not-allowed={!unlocked} title="Rename" onclick={() => startRename(row.type, row.page.slug)}>✎</button>
                <button class="btn-ghost" class:cursor-not-allowed={!unlocked} title="Page settings" onclick={() => openPageSettings(row.type, row.page.slug)}>✑</button>
                {#if row.page.home}
                  <!-- the home page stands: no delete, ever (the lid is server-side too) -->
                {:else}
                  <button
                    class="btn-ghost danger"
                    class:cursor-not-allowed={!unlocked}
                    title={sel?.type === row.type && sel?.slug === row.page.slug && unlocked ? 'Delete page' : 'Open the page and press Unlock first — deletion follows the unlock'}
                    disabled={!(sel?.type === row.type && sel?.slug === row.page.slug && unlocked)}
                    onclick={() => removePage(row.type, row.page.slug)}
                  >×</button>
                {/if}
                <button class="btn-ghost" class:cursor-not-allowed={!unlocked} title="Open the live page" onclick={() => openLive(row.type, row.page.slug)}>↗</button>
              </div>
              {#if renaming?.type === row.type && renaming?.slug === row.page.slug}
                {@const to = renaming.value.trim().toLowerCase()}
                {@const unchanged = to === renaming?.slug}
                {@const taken = (index?.[row.type] ?? []).some((p) => p.slug === to && p.slug !== renaming?.slug)}
                <form class="rename flex gap-1 flex-wrap items-center mt-1" onsubmit={commitRename}>
                  <input class="input max-w-40" bind:value={renaming.value} pattern="[a-z0-9]+(-[a-z0-9]+)*" required />
                  <button type="submit" class="btn btn-primary" disabled={unchanged || taken}
                    title={unchanged ? 'Name unchanged' : taken ? 'Already taken' : 'Rename'}>✓</button>
                  <button type="button" class="btn btn-secondary" onclick={() => (renaming = null)}>×</button>
                  {#if unchanged}
                    <span class="text-xs text-ink-soft">Same name.</span>
                  {:else if taken}
                    <span class="text-xs text-error">Another page already uses “{to}”.</span>
                  {:else if renameError}
                    <span class="text-xs text-error">{renameError}</span>
                  {/if}
                </form>
              {/if}
            </li>
          {/each}
        </ul>
      {/if}
    </div>
    <div class="sm-desk-foot">
      {#if deskError}<p class="alert alert-err m-0" role="alert">{deskError}</p>{/if}
      {#if deskNote}<p class="alert alert-ok m-0">{deskNote}</p>{/if}
      <!-- the save button only wakes when the sheet actually differs -->
      <button
        class="btn btn-primary w-full justify-center"
        disabled={!dirty || deskBusy}
        onclick={() => saveRaw()}
        title="Save the draft (Ctrl+S)"
      >
        {#if deskBusy}Saving…{:else if dirty}Save draft{:else}Saved{/if}
      </button>
      <div class="flex gap-1">
        <button class="btn btn-secondary flex-1 justify-center" onclick={() => openNew?.()}>+ New</button>
        <label class="btn btn-secondary flex-1 justify-center cursor-pointer">
          <input class="hidden" type="file" accept=".md,.markdown,text/markdown" onchange={onupload} />
          Upload
        </label>
        <button class="btn btn-secondary flex-1 justify-center" onclick={() => onmedia?.()}>Media</button>
      </div>
      {#if uploadError}<p class="alert alert-err m-0" role="alert">{uploadError}</p>{/if}
    </div>
  </aside>
  <!-- the sheet: the md file, plain text, the whole remaining width -->
  <div class="sm-desk-main">
    {#if sel === null}
      <div class="flex-1 grid place-items-center text-center p-6">
        <p class="m-0 italic text-ink-soft">Pick a file from the tree — its markdown opens here. The draft saves guarded; publish from ✑.</p>
      </div>
    {:else}
      <div class="flex items-center gap-2 flex-wrap">
        <p class="m-0 font-bold text-sm">{selPath}</p>
        {#if dirty}<span class="badge">unsaved</span>{/if}
        {#if !unlocked}<span class="badge">locked</span>{/if}
        <button
          class="btn btn-secondary ml-auto"
          disabled={deskBusy}
          onclick={() => (unlocked ? (unlocked = false) : confirm('Unlock the sheet', 'Raw markdown is surgery — a stray keystroke can knead the page beyond recognition. The lock returns on save.', () => (unlocked = true)))}
          title={unlocked ? 'Lock the sheet again' : 'Raw markdown is surgery — unlock to edit; the lock returns on save'}
        >
          {unlocked ? 'Lock' : 'Unlock'}
        </button>
      </div>
      <p class="m-0 text-xs text-ink-soft">
        The visual editor is the house way — open the live page (↗) and click what you see.
        This sheet writes raw markdown: unlock only for surgery, and it locks again on save.
      </p>
      <textarea
        class="sm-desk-sheet"
        class:sm-desk-locked={!unlocked}
        bind:value={raw}
        onkeydown={deskKeydown}
        readonly={!unlocked}
        spellcheck="false"
        disabled={deskBusy}
        aria-label={`Markdown source of ${selPath}`}
      ></textarea>
    {/if}
  </div>
</div>
