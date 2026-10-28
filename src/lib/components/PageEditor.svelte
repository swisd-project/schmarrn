<script lang="ts">
  /*
   * 1. Relative path: src/lib/components/PageEditor.svelte
   * 2. Description: Editing surface. Works on the editorial sidecar (.draft.md):
   *                 autosave-to-draft continuously, explicit publish copies it live.
   * 3. Expects: Page (type/slug), published flag, current user name; PHP draft/publish routes.
   * 4. Provides: Editing + preview + autosave + publish/unpublish + edit-lock UX.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */
  import { readDraft, writeDraft, publishPage, unpublishPage, acquireLock, releaseLock } from '$lib/api';
  import { schemaFor } from '$lib/schema';
  import { parseMdms } from '@swisd-project/mdms';
  import type { SelectedPage } from '$lib/types';

  interface Props {
    page: SelectedPage;
    published?: boolean;
    currentUserName?: string;
    onsaved?: () => void;
  }

  let { page, published = false, currentUserName = '', onsaved }: Props = $props();

  let raw = $state<string>('');
  let loadedFor = $state<string>('');
  let error = $state('');
  let saveState = $state<'saved' | 'dirty' | 'busy' | 'error' | 'publishing' | 'takedown'>('saved');
  let hasDraftCopy = $state(false);
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
        .then(({ raw: text, hasDraft }) => {
          if (cancelled || currentKey !== key) return;
          raw = text;
          hasDraftCopy = hasDraft;
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

  // Autosave-to-draft: 900 ms of silence, then the sidecar holds it.
  const queueAutosave = (value: string) => {
    raw = value;
    saveState = 'dirty';
    if (autosaveTimer !== null) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => {
      void writeDraft(page.type, page.slug, raw)
        .then(() => (saveState = 'saved'))
        .catch((e: unknown) => {
          saveState = 'error';
          error = e instanceof Error ? e.message : String(e);
        });
    }, 900);
  };

  // Live parse — same call the public site makes, by law.
  const preview = $derived.by(() => {
    if (raw === '') return { full: '', warning: '' };
    try {
      const result = parseMdms(raw, { validateFrontmatter: schemaFor(page.type).parse });
      return { full: result.fullHtml, warning: '' };
    } catch (e) {
      return { full: '', warning: e instanceof Error ? e.message : String(e) };
    }
  });

  async function takeover() {
    error = '';
    try {
      await acquireLock(page.type, page.slug, true);
      lockedMessage = '';
      const copy = await readDraft(page.type, page.slug);
      raw = copy.raw;
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
      await writeDraft(page.type, page.slug, raw);
      await publishPage(page.type, page.slug);
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
      hasDraftCopy = true;
      saveState = 'saved';
      onsaved?.();
    } catch (e) {
      saveState = 'error';
      error = e instanceof Error ? e.message : String(e);
    }
  }
</script>

<section class="editor">
  <header>
    <h2>{page.type} / {page.slug}</h2>
    <span class="state">{hasDraftCopy ? 'draft' : 'published'} · {saveState}</span>
    {#if lockedMessage === ''}
      <button class="publish" onclick={publish} disabled={busy}>
        {saveState === 'publishing' ? 'Serving…' : 'Publish'}
      </button>
      {#if published}
        <button class="takedown" onclick={takeDown} disabled={busy}>Unpublish</button>
      {/if}
    {/if}
    {#if lockedMessage}
      <span class="lock-banner" role="alert">
        {lockedMessage}
        <button class="takeover" onclick={takeover}>Take over</button>
      </span>
    {/if}
    {#if preview.warning}
      <span class="v-error" role="alert">{preview.warning}</span>
    {/if}
    {#if error}
      <span class="v-error" role="alert">{error}</span>
    {/if}
  </header>

  <div class="panes">
    <textarea
      bind:value={raw}
      oninput={(e) => queueAutosave(e.currentTarget.value)}
      disabled={lockedMessage !== ''}
      spellcheck="false"
      placeholder="--- title … --- body"
    ></textarea>
    <div class="preview">
      {@html preview.full}
    </div>
  </div>
</section>

<style>
  .editor { display: flex; flex-direction: column; gap: 1rem; height: 80vh; }
  header { display: flex; align-items: baseline; gap: 0.75rem; flex-wrap: wrap; }
  h2 { margin: 0; font-size: 1rem; }
  .state { font-size: 0.8rem; opacity: 0.6; }
  .v-error { font-size: 0.8rem; color: #b3004c; }
  .panes { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; flex: 1; min-height: 0; }
  textarea {
    flex: 1;
    width: 100%;
    resize: none;
    font: 0.9rem/1.5 ui-monospace, monospace;
    background: #fffdf9;
    border: 1px solid #d8c7b2;
    border-radius: 4px;
    padding: 1rem;
    box-sizing: border-box;
  }
  .preview {
    overflow: auto;
    border: 1px solid #d8c7b2;
    border-radius: 4px;
    padding: 1rem;
    background: #fffdf9;
  }
  .preview :global(h1), .preview :global(h2), .preview :global(h3) { margin-top: 0.5rem; }
  .preview :global(p) { margin: 0.4rem 0; }
  .publish { font-weight: 600; }
  .takedown { font-size: 0.8rem; opacity: 0.7; }
  .lock-banner { font-size: 0.8rem; color: #7a4a00; background: #ffe9d6; border-radius: 4px; padding: 0.2rem 0.5rem; }
  .takeover { font-size: 0.8rem; margin-left: 0.4rem; }
</style>
