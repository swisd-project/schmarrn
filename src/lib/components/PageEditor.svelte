<script lang="ts">
  /*
   * 1. Relative path: src/lib/components/PageEditor.svelte
   * 2. Description: Raw-markdown editor with live mdms preview. The textarea is the
   *                 source of truth — frontmatter and body stay one file, always.
   * 3. Expects: A selected page (type/slug) as prop; the PHP raw-GET route.
   * 4. Provides: Editing + preview + save via PUT. No second renderer, ever.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */
  import { readPage, writePage } from '$lib/api';
  import { schemaFor } from '$lib/schema';
  import { parseMdms } from '@swisd-project/mdms';
  import type { SelectedPage } from '$lib/types';

  interface Props {
    page: SelectedPage;
    onsaved?: () => void;
  }

  let { page, onsaved }: Props = $props();

  let raw = $state<string>('');
  let loadedFor = $state<string>('');
  let error = $state('');
  let saveState = $state<'saved' | 'dirty' | 'busy' | 'error'>('saved');

  const currentKey = $derived(`${page.type}/${page.slug}`);

  // Load the file when a different page gets selected (skip reload when we just saved it).
  $effect(() => {
    const key = currentKey;
    if (loadedFor === key) return;
    error = '';
    readPage(page.type, page.slug)
      .then((text) => {
        if (currentKey !== key) return; // user roamed on
        raw = text;
        loadedFor = key;
        saveState = 'saved';
      })
      .catch((e: unknown) => (error = e instanceof Error ? e.message : String(e)));
  });

  // Live parse on every keystroke — same call the public site makes, by law.
  const preview = $derived.by(() => {
    if (raw === '') return { full: '', warning: '' };
    try {
      const result = parseMdms(raw, { validateFrontmatter: schemaFor(page.type).parse });
      return { full: result.fullHtml, warning: '' };
    } catch (e) {
      return { full: '', warning: e instanceof Error ? e.message : String(e) };
    }
  });

  async function save() {
    saveState = 'busy';
    try {
      await writePage(page.type, page.slug, raw);
      saveState = 'saved';
      loadedFor = `${page.type}/${page.slug}`; // reload-skip guard, not a pattern break
      onsaved?.();
    } catch (e) {
      saveState = 'error';
      error = e instanceof Error ? e.message : String(e);
    }
  }

  const dirty = $derived(saveState === 'dirty');
</script>

<section class="editor">
  <header>
    <h2>{page.type} / {page.slug}</h2>
    <button onclick={save} disabled={saveState === 'busy'}>
      {saveState === 'busy' ? 'Serving…' : 'Save'}
    </button>
    <span class="state">{saveState === 'saved' ? 'stored' : saveState}</span>
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
      oninput={() => (saveState = 'dirty')}
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
</style>
