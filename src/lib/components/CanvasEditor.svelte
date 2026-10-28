<script lang="ts">
  /*
   * 1. Relative path: src/lib/components/CanvasEditor.svelte
   * 2. Description: The single-canvas editor. Shows the rendered page; click a block
   *                 to select it; a floating menu offers actions; every action opens
   *                 a modal; saving a modal rewrites that block's markdown and
   *                 autosaves the draft. No inline typing, no live markdown mangling.
   *                 Draft saves are hash-guarded: a stale save gets a conflict banner,
   *                 never a silent clobber (flaky-internet guardrail).
   * 3. Expects: Page (type/slug), published flag, current user name; PHP draft/publish routes.
   * 4. Provides: Canvas editing + module modals + autosave + publish/unpublish + lock UX.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */
  import {
    readDraft,
    writeDraft,
    publishPage,
    unpublishPage,
    acquireLock,
    releaseLock,
    listMedia,
    uploadMedia,
    renamePage,
    ConflictError
  } from '$lib/api';
  import { renderMdms, frontmatterError } from '$lib/render';
  import { parseMdms } from '@swisd-project/mdms';
  import { splitFrontmatter, splitBlocks, joinPage, directiveInner, attr, type Block } from '$lib/blocks';
  import type { SelectedPage } from '$lib/types';
  import type { MediaRef } from '$lib/api';

  interface Props {
    page: SelectedPage;
    published?: boolean;
    currentUserName?: string;
    onsaved?: () => void;
    /** Fired after the settings form renamed the page — the parent re-points its selection. */
    onrenamed?: (slug: string) => void;
  }

  let { page, published = false, currentUserName = '', onsaved, onrenamed }: Props = $props();

  let raw = $state<string>('');
  let loadedFor = $state<string>('');
  let error = $state('');
  let saveState = $state<'saved' | 'dirty' | 'busy' | 'error' | 'publishing' | 'takedown'>('saved');
  let hasDraftCopy = $state(false);
  let baseHash = $state('');
  let conflictHash = $state('');
  let lockedMessage = $state('');
  let heartbeat = $state<ReturnType<typeof setInterval> | null>(null);
  let autosaveTimer = $state<ReturnType<typeof setTimeout> | null>(null);

  const currentKey = $derived(`${page.type}/${page.slug}`);

  // One $effect guards BOTH the lock lifecycle and the content load per selected page.
  $effect(() => {
    const key = currentKey;
    let cancelled = false;

    // ——— lock: acquire, keep warm, release on exit ———
    lockedMessage = '';
    const warm = () =>
      acquireLock(page.type, page.slug, false).catch((e: unknown) => {
        lockedMessage = e instanceof Error ? e.message : String(e);
      });
    warm();
    heartbeat = setInterval(warm, 60_000);

    // ——— content: editorial sidecar wins over the published copy ———
    if (loadedFor !== key) {
      error = '';
      readDraft(page.type, page.slug)
        .then(({ raw: text, hasDraft, hash }) => {
          if (cancelled || currentKey !== key) return;
          raw = text;
          hasDraftCopy = hasDraft;
          baseHash = hash;
          loadedFor = key;
          saveState = 'saved';
        })
        .catch((e: unknown) => (error = e instanceof Error ? e.message : String(e)));
    }

    return () => {
      cancelled = true;
      if (heartbeat !== null) clearInterval(heartbeat);
      if (loadedFor === key) void releaseLock(page.type, page.slug);
    };
  });

  // ——— the canvas model: frontmatter + blocks + per-block rendered html ———
  // Gated on the load: until THIS page's content has landed, nothing renders —
  // no stale flashes of the previous page's markdown under the new page's schema.
  const canvas = $derived.by(() => {
    if (loadedFor !== currentKey) return null;
    const split = splitFrontmatter(raw);
    const blocks = splitBlocks(split.body);
    const html = blocks.map((block) => {
      try {
        return {
          html: renderMdms(block.source, { type: page.type, slug: page.slug }).fullHtml,
          warning: ''
        };
      } catch (e) {
        return { html: '', warning: e instanceof Error ? e.message : String(e) };
      }
    });
    return { split, blocks, html };
  });

  const pageTitle = $derived(
    canvas === null ? page.slug : (/^title:\s*(.+)$/m.exec(canvas.split.frontmatter)?.[1]?.trim() ?? page.slug)
  );

  // Frontmatter schema gate — frontmatter-only parse (cheap), human-readable output.
  const schemaWarning = $derived(loadedFor === currentKey ? frontmatterError(raw, page.type) : '');

  // Autosave-to-draft, hash-guarded: 900 ms of silence, then a guarded PUT.
  const queueAutosave = (value: string) => {
    if (conflictHash !== '') return; // frozen until the conflict is resolved
    raw = value;
    saveState = 'dirty';
    if (autosaveTimer !== null) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => {
      void writeDraft(page.type, page.slug, raw, baseHash)
        .then((hash) => {
          baseHash = hash;
          saveState = 'saved';
        })
        .catch((e: unknown) => {
          if (e instanceof ConflictError) {
            conflictHash = e.serverHash;
            saveState = 'error';
          } else {
            saveState = 'error';
            error = e instanceof Error ? e.message : String(e);
          }
        });
    }, 900);
  };

  /** Rewrite the body through a block transformer and queue the autosave. */
  function withBlocks(fn: (blocks: Block[]) => Block[], frontmatter = canvas?.split.frontmatter ?? ''): void {
    queueAutosave(joinPage({ frontmatter, body: '' }, fn(splitBlocks(splitFrontmatter(raw).body))));
  }

  function saveBlockSource(index: number, source: string): void {
    withBlocks((bs) => bs.map((b, i) => (i === index ? { ...b, source } : b)));
  }

  function moveBlock(index: number, delta: -1 | 1): void {
    withBlocks((bs) => {
      const target = index + delta;
      if (target < 0 || target >= bs.length) return bs;
      const copy = [...bs];
      [copy[index], copy[target]] = [copy[target], copy[index]];
      return copy;
    });
  }

  function removeBlock(index: number): void {
    withBlocks((bs) => bs.filter((_, i) => i !== index));
    selected = null;
    menuPos = null;
  }

  function insertBlock(at: number, block: Block): void {
    withBlocks((bs) => {
      const copy = [...bs];
      copy.splice(Math.min(at, copy.length), 0, block);
      return copy;
    });
  }

  async function takeover() {
    error = '';
    try {
      await acquireLock(page.type, page.slug, true);
      lockedMessage = '';
      const copy = await readDraft(page.type, page.slug);
      raw = copy.raw;
      baseHash = copy.hash;
      hasDraftCopy = copy.hasDraft;
      loadedFor = currentKey;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  const busy = $derived(saveState === 'busy' || saveState === 'publishing' || saveState === 'takedown');

  /** Publish: flush pending autosave, then push the sidecar live. */
  async function publish() {
    error = '';
    saveState = 'publishing';
    try {
      if (autosaveTimer !== null) {
        clearTimeout(autosaveTimer);
        autosaveTimer = null;
      }
      baseHash = await writeDraft(page.type, page.slug, raw, baseHash);
      // The browser renders (GOSPEL serving law): ship the rendered body with the
      // publish call so PHP only has to store it. Render blow-up? Publish anyway —
      // the fallback shell re-renders client-side from the raw .md.
      let html: string | undefined;
      try {
        html = renderMdms(raw, { type: page.type, slug: page.slug }).fullHtml;
      } catch {
        html = undefined;
      }
      await publishPage(page.type, page.slug, html);
      hasDraftCopy = false;
      saveState = 'saved';
      loadedFor = currentKey; // published copy === editorial copy now
      onsaved?.();
    } catch (e) {
      saveState = 'error';
      error = e instanceof Error ? e.message : String(e);
    }
  }

  /** Unpublish: published copy becomes the editorial copy; the public goes dark. */
  async function takeDown() {
    error = '';
    saveState = 'takedown';
    try {
      await unpublishPage(page.type, page.slug);
      const copy = await readDraft(page.type, page.slug);
      raw = copy.raw;
      baseHash = copy.hash;
      hasDraftCopy = true;
      saveState = 'saved';
      onsaved?.();
    } catch (e) {
      saveState = 'error';
      error = e instanceof Error ? e.message : String(e);
    }
  }

  // ——— conflict resolution: the flaky-internet guardrail's visible half ———
  async function reloadDraft() {
    if (autosaveTimer !== null) clearTimeout(autosaveTimer);
    try {
      const copy = await readDraft(page.type, page.slug);
      raw = copy.raw;
      baseHash = copy.hash;
      hasDraftCopy = copy.hasDraft;
      conflictHash = '';
      saveState = 'saved';
      selected = null;
      menuPos = null;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  async function forceOverwrite() {
    if (autosaveTimer !== null) clearTimeout(autosaveTimer);
    try {
      baseHash = await writeDraft(page.type, page.slug, raw);
      conflictHash = '';
      saveState = 'saved';
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  // ——— selection + floating menu ———
  let canvasEl = $state<HTMLDivElement | null>(null);
  let selected = $state<number | null>(null);
  let menuPos = $state<{ x: number; y: number } | null>(null);

  function selectBlock(index: number, target: Element) {
    selected = index;
    const host = canvasEl;
    if (host === null) return;
    const hostRect = host.getBoundingClientRect();
    const rect = target.getBoundingClientRect();
    menuPos = {
      x: Math.max(8, Math.min(rect.left - hostRect.left, host.clientWidth - 300)),
      y: rect.bottom - hostRect.top + 6
    };
  }

  function deselect() {
    selected = null;
    menuPos = null;
  }

  const selectedIsModule = $derived(
    canvas !== null && selected !== null && canvas.blocks[selected]?.kind === 'module' &&
      ['image', 'video', 'embed'].includes(canvas.blocks[selected]?.name ?? '')
  );

  // ——— modals: every change goes through one, per the house interaction model ———
  type Modal =
    | { kind: 'text'; index: number; isNew: boolean; value: string }
    | { kind: 'image'; index: number; isNew: boolean; file: string; caption: string; inner: string }
    | { kind: 'video'; index: number; isNew: boolean; url: string; file: string }
    | { kind: 'embed'; index: number; isNew: boolean; url: string }
    | {
        kind: 'settings';
        title: string;
        slug: string;
        style: string;
        date: string;
        tagsText: string;
        draftFlag: boolean;
        publishedFlag: boolean;
      };

  let modal = $state<Modal | null>(null);
  let modalError = $state('');

  /** Insertion point for new blocks: after the selection, else at the end. */
  function insertAt(): number {
    return selected === null ? (canvas?.blocks.length ?? 0) : selected + 1;
  }

  function openEditText(index: number) {
    modalError = '';
    modal = { kind: 'text', index, isNew: false, value: canvas?.blocks[index]?.source ?? '' };
  }

  function addText() {
    modalError = '';
    modal = { kind: 'text', index: insertAt(), isNew: true, value: 'New text — double the recipe.' };
  }

  /** Pull `key="value"` pairs back out of a directive's first line. */
  const attrOf = (source: string, key: string): string =>
    new RegExp(`${key}="([^"]*)"`).exec(source.split('\n')[0] ?? '')?.[1] ?? '';

  function openEditModule(index: number) {
    modalError = '';
    const block = canvas?.blocks[index];
    if (block === undefined) return;
    const source = block.source;
    const inner = directiveInner(source);
    if (block.name === 'image') {
      modal = { kind: 'image', index, isNew: false, file: attrOf(source, 'file'), caption: attrOf(source, 'caption'), inner };
    } else if (block.name === 'video') {
      modal = { kind: 'video', index, isNew: false, url: attrOf(source, 'url'), file: attrOf(source, 'file') };
    } else if (block.name === 'embed') {
      modal = { kind: 'embed', index, isNew: false, url: attrOf(source, 'url') };
    } else {
      modal = { kind: 'text', index, isNew: false, value: source };
    }
  }

  function addModule(name: 'image' | 'video' | 'embed') {
    modalError = '';
    const index = insertAt();
    if (name === 'image') modal = { kind: 'image', index, isNew: true, file: '', caption: '', inner: '' };
    else if (name === 'video') modal = { kind: 'video', index, isNew: true, url: '', file: '' };
    else modal = { kind: 'embed', index, isNew: true, url: '' };
    void loadModalMedia();
  }

  function openSettings() {
    modalError = '';
    const fm = canvas?.split.frontmatter ?? '';
    // Best-effort parse; a broken frontmatter falls back to regex peeks + defaults —
    // saving the form then REBUILDS valid YAML, which is the escape hatch out of
    // a validation dead end.
    let data: Record<string, unknown> = {};
    try {
      parseMdms(`${fm}\n`, {
        validateFrontmatter: (d) => {
          data = d as Record<string, unknown>;
          return data;
        }
      });
    } catch {
      data = {};
    }
    const peek = (key: string): string =>
      new RegExp(`^\\s*${key}:\\s*(.+)$`, 'm').exec(fm)?.[1]?.trim() ?? '';
    const unquote = (value: string): string =>
      value.length >= 2 && (value.startsWith('"') || value.startsWith("'")) && value.endsWith(value[0])
        ? value.slice(1, -1)
        : value;
    const title =
      typeof data.title === 'string' && data.title !== '' ? data.title : unquote(peek('title')) || page.slug;
    const slugCandidate = typeof data.slug === 'string' ? data.slug : '';
    const slug = /^[a-z0-9]+(-[a-z0-9]+)*$/.test(slugCandidate) ? slugCandidate : page.slug;
    const styleCandidate = data.style === 'slide' || data.style === 'classical' ? data.style : peek('style');
    const style = styleCandidate === 'slide' || styleCandidate === 'classical' ? styleCandidate : '';
    const datePeek = peek('date').replace(/["']/g, '');
    const date =
      data.date instanceof Date
        ? data.date.toISOString().slice(0, 10)
        : /^\d{4}-\d{2}-\d{2}$/.test(datePeek)
          ? datePeek
          : new Date().toISOString().slice(0, 10);
    const tagsText = Array.isArray(data.tags) ? data.tags.filter((t) => typeof t === 'string').join(', ') : '';
    const draftFlag = /^\s*draft:\s*true\b/m.test(fm);
    const publishedFlag = /^\s*published:\s*false\b/m.test(fm) ? false : true;
    modal = { kind: 'settings', title, slug, style, date, tagsText, draftFlag, publishedFlag };
  }

  /** Canonical frontmatter from the settings form — valid YAML by construction. */
  function buildFrontmatter(m: Extract<Modal, { kind: 'settings' }>): string {
    const tags = m.tagsText.split(',').map((t) => t.trim()).filter(Boolean);
    const lines = [
      '---',
      `title: ${JSON.stringify(m.title.trim())}`,
      `slug: ${m.slug.trim()}`,
      `published: ${m.publishedFlag}`,
      `draft: ${m.draftFlag}`,
      `date: ${m.date !== '' ? m.date : new Date().toISOString().slice(0, 10)}`,
      `tags: [${tags.map((t) => JSON.stringify(t)).join(', ')}]`
    ];
    if (m.style !== '') lines.push(`style: ${m.style}`);
    lines.push('---');
    return lines.join('\n');
  }

  /** Settings save: rebuild the frontmatter from the form; a changed slug renames the page. */
  async function saveSettings(event: SubmitEvent) {
    event.preventDefault();
    if (modal === null || modal.kind !== 'settings') return;
    const m = modal;
    const slug = m.slug.trim();
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug)) {
      modalError = 'Slug: lowercase letters, digits and hyphens only.';
      return;
    }
    if (m.title.trim() === '') {
      modalError = 'The title cannot be empty.';
      return;
    }
    const body = splitFrontmatter(raw).body;
    const next = joinPage({ frontmatter: buildFrontmatter(m), body: '' }, splitBlocks(body));
    modalError = '';
    try {
      if (slug !== page.slug) {
        // Flush the rebuilt document to the sidecar BEFORE the directory moves,
        // then rename; the parent re-points the selection and the editor remounts.
        baseHash = await writeDraft(page.type, page.slug, next, baseHash);
        await renamePage(page.type, page.slug, slug);
        raw = next;
        saveState = 'saved';
        hasDraftCopy = true;
        closeModal();
        onsaved?.();
        onrenamed?.(slug);
      } else {
        queueAutosave(next);
        closeModal();
      }
    } catch (e) {
      modalError = e instanceof Error ? e.message : String(e);
    }
  }

  function closeModal() {
    modal = null;
    modalError = '';
  }

  function saveModal(event: SubmitEvent) {
    event.preventDefault();
    const m = modal;
    if (m === null) return;
    if (m.kind === 'settings') {
      void saveSettings(event);
      return;
    }
    if (m.kind === 'text') {
      if (m.isNew) insertBlock(m.index, { kind: 'text', source: m.value });
      else saveBlockSource(m.index, m.value);
      closeModal();
      return;
    }
    if (m.kind === 'image') {
      if (m.file === '') {
        modalError = 'Pick or upload a file first.';
        return;
      }
      const attrs = `file="${attr(m.file)}"${m.caption !== '' ? ` caption="${attr(m.caption)}"` : ''}`;
      const source = `:::image{${attrs}}${m.inner !== '' ? `\n${m.inner}\n` : '\n'}:::`;
      if (m.isNew) insertBlock(m.index, { kind: 'module', name: 'image', source });
      else saveBlockSource(m.index, source);
      closeModal();
      return;
    }
    if (m.kind === 'video') {
      if (m.file === '' && m.url === '') {
        modalError = 'Give it a video — upload a file or paste an http(s) URL.';
        return;
      }
      const source =
        m.file !== ''
          ? `:::video{file="${attr(m.file)}"}\n:::`
          : `:::video{url="${attr(m.url)}"}\n:::`;
      if (m.isNew) insertBlock(m.index, { kind: 'module', name: 'video', source });
      else saveBlockSource(m.index, source);
      closeModal();
      return;
    }
    // embed — the last branch; settings went through saveSettings instead
    if (m.kind !== 'embed') return;
    if (!/^https?:\/\//i.test(m.url.trim())) {
      modalError = 'Embeds need an http(s) URL.';
      return;
    }
    const source = `:::embed{url="${attr(m.url)}"}\n:::`;
    if (m.isNew) insertBlock(m.index, { kind: 'module', name: 'embed', source });
    else saveBlockSource(m.index, source);
    closeModal();
  }

  // ——— media for the image/video modals ———
  let modalMedia = $state<readonly MediaRef[]>([]);
  let mediaBusy = $state(false);

  async function loadModalMedia() {
    try {
      modalMedia = await listMedia(page.type, page.slug);
    } catch (e) {
      modalError = e instanceof Error ? e.message : String(e);
    }
  }

  async function uploadToModal(event: Event, target: 'image' | 'video') {
    const input = event.currentTarget as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    mediaBusy = true;
    modalError = '';
    try {
      const stored = await uploadMedia(page.type, page.slug, file);
      modalMedia = await listMedia(page.type, page.slug);
      const m = modal;
      if (m !== null && m.kind === 'image') modal = { ...m, file: stored.name };
      else if (m !== null && m.kind === 'video') modal = { ...m, file: stored.name };
      void target;
    } catch (e) {
      modalError = e instanceof Error ? e.message : String(e);
    } finally {
      mediaBusy = false;
      input.value = '';
    }
  }
</script>

<section class="editor">
  <header class="flex items-center gap-3 flex-wrap">
    <h2 class="m-0 text-base font-extrabold">{page.type} / {page.slug}</h2>
    <span class="text-xs text-ink-soft">{hasDraftCopy ? 'draft' : 'published'} · {saveState}</span>
    {#if lockedMessage === '' && conflictHash === ''}
      <button class="btn btn-primary" onclick={publish} disabled={busy}>
        {saveState === 'publishing' ? 'Serving…' : 'Publish'}
      </button>
      {#if published}
        <button class="btn btn-secondary" onclick={takeDown} disabled={busy}>Unpublish</button>
      {/if}
    {/if}
    {#if lockedMessage}
      <span class="alert alert-warn m-0" role="alert">
        {lockedMessage}
        <button class="btn btn-secondary" onclick={takeover}>Take over</button>
      </span>
    {/if}
    {#if conflictHash !== ''}
      <span class="alert alert-warn m-0" role="alert">
        This draft changed elsewhere while you were editing — nothing was clobbered.
        <button class="btn btn-secondary" onclick={reloadDraft}>Take the server copy</button>
        <button class="btn btn-attention" onclick={forceOverwrite}>Keep mine</button>
      </span>
    {/if}
    {#if schemaWarning}
      <span class="alert alert-err m-0" role="alert">{schemaWarning} — open ⚙ Page settings and save the form to rebuild the frontmatter.</span>
    {/if}
    {#if error}
      <span class="alert alert-err m-0" role="alert">{error}</span>
    {/if}
  </header>

  <div
    class="canvas"
    bind:this={canvasEl}
    role="presentation"
    onclick={(e) => {
      // Deselect on background clicks — but never when the floating menu ate the click.
      if ((e.target as HTMLElement).closest('.menu') === null) deselect();
    }}
    onkeydown={(e) => e.key === 'Escape' && deselect()}
  >
    {#if canvas === null}
      <p class="empty">Plating…</p>
    {:else}
      <button
        class="btn btn-secondary settings-chip"
        title="Page settings (title, slug, style)"
        onclick={(e) => {
          e.stopPropagation();
          openSettings();
        }}
      >
        ⚙ {pageTitle}
      </button>

      {#each canvas.blocks as block, i (i)}
      <div
        class="block {block.kind}"
        class:selected={selected === i}
        role="button"
        tabindex="0"
        onclick={(e) => {
          e.stopPropagation();
          selectBlock(i, e.currentTarget);
        }}
        onkeydown={(e) => {
          if (e.key === 'Enter') {
            e.stopPropagation();
            selectBlock(i, e.currentTarget);
          }
        }}
      >
        {#if canvas.html[i].warning !== ''}
          <p class="block-warning">{canvas.html[i].warning}</p>
        {/if}
        {@html canvas.html[i].html}
      </div>
    {:else}
      <div class="starter">
        <p class="empty">
          {#if schemaWarning}
            This page's frontmatter is broken — that's why it looks empty. Open
            <strong>⚙ Page settings</strong> and save the form: it rebuilds valid frontmatter from
            the fields, and the error clears. Or start adding content right here:
          {:else}
            Empty page — start with a block:
          {/if}
        </p>
        <div class="float-menu starter-row">
          <button onclick={addText}>Text</button>
          <button onclick={() => addModule('image')}>Image</button>
          <button onclick={() => addModule('video')}>Video</button>
          <button onclick={() => addModule('embed')}>Embed</button>
        </div>
      </div>
    {/each}

    {#if canvas !== null && selected !== null && menuPos !== null && conflictHash === ''}
      <div class="float-menu menu" style:left="{menuPos.x}px" style:top="{menuPos.y}px">
        <div class="menu-row">
          {#if selectedIsModule}
            <button onclick={() => openEditModule(selected as number)}>Edit module</button>
          {:else}
            <button onclick={() => openEditText(selected as number)}>Edit text</button>
          {/if}
          <button title="Move up" onclick={() => moveBlock(selected as number, -1)} disabled={(selected as number) === 0}>↑</button>
          <button title="Move down" onclick={() => moveBlock(selected as number, 1)} disabled={(selected as number) === canvas.blocks.length - 1}>↓</button>
          <button class="danger" onclick={() => removeBlock(selected as number)}>Delete</button>
        </div>
        <div class="menu-row add">
          <span>Add:</span>
          <button onclick={addText}>Text</button>
          <button onclick={() => addModule('image')}>Image</button>
          <button onclick={() => addModule('video')}>Video</button>
          <button onclick={() => addModule('embed')}>Embed</button>
        </div>
      </div>
    {/if}
    {/if}
  </div>
</section>

{#if modal !== null}
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true">
    <form class="modal-card" onsubmit={saveModal}>
      <header class="modal-head">
        <h3>
          {#if modal.kind === 'text'}{modal.isNew ? 'Add text block' : 'Edit text block'}{/if}
          {#if modal.kind === 'image'}{modal.isNew ? 'Add image' : 'Edit image'}{/if}
          {#if modal.kind === 'video'}{modal.isNew ? 'Add video' : 'Edit video'}{/if}
          {#if modal.kind === 'embed'}{modal.isNew ? 'Add embed' : 'Edit embed'}{/if}
          {#if modal.kind === 'settings'}Page settings{/if}
        </h3>
        <button type="button" class="close-x" onclick={closeModal}>×</button>
      </header>

      {#if modal.kind === 'text'}
        <textarea class="input" bind:value={modal.value} rows="8" spellcheck="true"></textarea>
        <p class="hint text-xs text-ink-soft m-0">Markdown. Raw HTML is dropped by the renderer — by design.</p>
      {:else if modal.kind === 'image'}
        <label class="field">
          <span>File</span>
          <input class="input" bind:value={modal.file} placeholder="pick below or paste an http(s) URL" readonly={false} />
        </label>
        <label class="field">
          <span>Caption</span>
          <input class="input" bind:value={modal.caption} placeholder="A cappuccino and a hush" />
        </label>
        <div class="media-pick">
          <span class="hint">Page media</span>
          <label class="upload">
            <input type="file" accept="image/*" onchange={(e) => uploadToModal(e, 'image')} disabled={mediaBusy} />
            {mediaBusy ? 'Uploading…' : 'Upload…'}
          </label>
          <ul>
            {#each modalMedia as file (file.name)}
              <li>
                <button type="button" class:picked={modal.file === file.name} onclick={() => { if (modal?.kind === 'image') modal = { ...modal, file: file.name }; }}>
                  <img src={`/api/media/${page.type}/${page.slug}/${encodeURIComponent(file.name)}`} alt={file.name} loading="lazy" />
                  {file.name}
                </button>
              </li>
            {/each}
          </ul>
        </div>
      {:else if modal.kind === 'video'}
        <label class="field">
          <span>URL (http(s))</span>
          <input class="input" bind:value={modal.url} placeholder="https://…" />
        </label>
        <div class="media-pick">
          <span class="hint">…or a page video file</span>
          <label class="upload">
            <input type="file" accept="video/mp4,video/webm" onchange={(e) => uploadToModal(e, 'video')} disabled={mediaBusy} />
            {mediaBusy ? 'Uploading…' : 'Upload…'}
          </label>
          <ul>
            {#each modalMedia.filter((f) => /\.(mp4|webm)$/i.test(f.name)) as file (file.name)}
              <li>
                <button type="button" class:picked={modal.file === file.name} onclick={() => { if (modal?.kind === 'video') modal = { ...modal, file: file.name, url: '' }; }}>
                  {file.name}
                </button>
              </li>
            {/each}
          </ul>
        </div>
      {:else if modal.kind === 'embed'}
        <label class="field">
          <span>Embed URL (http(s))</span>
          <input class="input" bind:value={modal.url} placeholder="https://www.youtube.com/embed/…" />
        </label>
      {:else}
        <label class="field">
          <span>Title</span>
          <input class="input" bind:value={modal.title} required placeholder="Page title" />
        </label>
        <label class="field">
          <span>Slug (URL)</span>
          <input class="input" bind:value={modal.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
            title="lowercase letters, digits, hyphens" />
          {#if modal.slug.trim() !== page.slug}
            <span class="hint warn-hint">Saving renames the page to <code>{modal.slug.trim()}</code>.</span>
          {/if}
        </label>
        <label class="field">
          <span>Serve as</span>
          <select class="input" bind:value={modal.style}>
            <option value="">Theme default</option>
            <option value="slide">Slide — full screen</option>
            <option value="classical">Classical — with menu</option>
          </select>
        </label>
        <label class="field">
          <span>Date</span>
          <input class="input" type="date" bind:value={modal.date} />
        </label>
        <label class="field">
          <span>Tags (comma-separated)</span>
          <input class="input" bind:value={modal.tagsText} placeholder="studio, 2026" />
        </label>
        <p class="hint text-xs text-ink-soft m-0">Saving rebuilds the frontmatter from these fields — a broken one gets fixed in the same breath. Visibility stays with the Publish / Unpublish buttons.</p>
      {/if}

      {#if modalError}<p class="alert alert-err m-0" role="alert">{modalError}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={closeModal}>Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </footer>
    </form>
  </div>
{/if}

<style>
  /* The editor chrome wears the system (app.css); only the canvas-specific
     geometry lives here. Colors come from the tokens. */
  .editor { display: flex; flex-direction: column; gap: 1rem; height: 80vh; }

  .canvas {
    position: relative;
    overflow: auto;
    flex: 1;
    min-height: 0;
    border-radius: var(--radius-lg);
    background: var(--color-batter);
    box-shadow: var(--raise-mg);
    padding: 1.25rem 1.5rem 3rem;
  }
  .settings-chip { font-size: var(--text-xs); padding: 0.25rem 0.75rem; margin-bottom: 1rem; }

  .block { border: 1px solid transparent; border-radius: var(--radius-md); padding: 0.35rem 0.5rem; cursor: pointer; }
  .block:hover { border-color: var(--color-blossom); }
  .block.selected { border-color: var(--color-jam); background: #fff7f2; box-shadow: var(--hover-lift); }
  .block.module { background: #fbf7ff; }
  .block.module.selected { border-color: var(--color-neon-blue); background: #f4fbff; }
  .block :global(figure) { margin: 0.5rem 0; }
  .block :global(img), .block :global(video) { max-width: 100%; border-radius: var(--radius-md); }
  .block :global(iframe) { width: 100%; aspect-ratio: 16 / 9; border: 0; border-radius: var(--radius-md); }
  .block-warning { color: var(--color-error); font-size: var(--text-xs); }
  .empty { opacity: 0.6; font-style: italic; color: var(--color-ink-soft); }
  .starter { border: 2px dashed var(--color-line); border-radius: var(--radius-lg); padding: 1rem; }
  .starter .empty { margin: 0 0 0.6rem; }

  /* float-menu specifics: the .float-menu base is global; position + rows here */
  .menu { position: absolute; z-index: 20; flex-direction: column; }
  .menu-row { display: flex; gap: 0.25rem; align-items: center; }
  .menu-row.add { border-top: 1px solid rgba(255, 255, 255, 0.2); margin-top: 0.25rem; padding-top: 0.35rem; }
  .menu-row.add span { font-size: var(--text-xs); opacity: 0.7; }
  .menu button:disabled { opacity: 0.35; cursor: default; }

  .media-pick ul { list-style: none; margin: 0.3rem 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 0.4rem; max-height: 9rem; overflow: auto; }
  .media-pick li button {
    display: flex; align-items: center; gap: 0.35rem; font-size: var(--text-xs);
    background: var(--color-batter); border-radius: var(--radius-md);
    padding: 0.2rem 0.4rem; cursor: pointer; box-shadow: var(--hover-lift);
    transition: box-shadow var(--lift), transform var(--lift);
  }
  .media-pick li button:hover { transform: translateY(-2px); }
  .media-pick li button.picked { box-shadow: var(--press); transform: none; }
  .media-pick li img { width: 2.4rem; height: 1.6rem; object-fit: cover; border-radius: var(--radius-sm); background: var(--color-crust); }
  .upload { font-size: var(--text-xs); cursor: pointer; color: var(--color-ink-soft); }
  .upload:hover { color: var(--color-ink); }
  .upload input { display: none; }
  .warn-hint { color: var(--color-warn); }
</style>
