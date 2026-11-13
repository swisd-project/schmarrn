<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/PageSettingsModal.svelte
   * 2. Description: THE PAGE SETTINGS OVERLAY (strip 5b of the p8 STEP 6
   *                 dashboard split; ported from the retired editor burger).
   *                 The ONE overlay law (2026-11-04): the dashboard carries
   *                 the page's title, date, tags and draft state. The
   *                 published flag is preserved verbatim (publish/unpublish
   *                 toggles stay a deliberate act, not a side effect); baking
   *                 reuses the editor's own path — PUT draft under the
   *                 X-Base-Hash guard + publish with the client-rendered
   *                 body. Slug changes stay with rename (✎), so no URL
   *                 surgery happens behind a settings form's back.
   *                 The parent opens (it fetches + peeks the frontmatter
   *                 against the index) and hands the request in; the save
   *                 lives here. `onsaved` receives the crown's new home
   *                 slug — or null when the crown did not move.
   * 3. Expects: `request` — $bindable (the settings object); `admin` —
   *             whether the crown switch shows; `onsaved` — (home | null).
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { publishDraft, putDraftRaw, setHomePage } from '$lib/api';
  import { splitFrontmatter } from '$lib/blocks';
  import { renderMdms } from '$lib/render';

  type Request = {
    type: string;
    slug: string;
    published: boolean;
    title: string;
    asDraft: boolean;
    date: string;
    tags: string;
    isHome: boolean;
    wasHome: boolean;
    baseRaw: string;
    hash: string;
    saving: boolean;
    error: string;
  };

  let {
    request = $bindable<Request | null>(null),
    admin = false,
    onsaved,
  }: {
    request?: Request | null;
    admin?: boolean;
    onsaved?: (home: string | null) => void;
  } = $props();

  async function save(event: SubmitEvent) {
    event.preventDefault();
    const s = request;
    if (s === null || s.saving) return;
    if (s.title.trim() === '') {
      s.error = 'The title cannot be empty.';
      return;
    }
    s.saving = true;
    s.error = '';
    try {
      const body = splitFrontmatter(s.baseRaw).body;
      const tagList = s.tags.split(',').map((t) => t.trim()).filter(Boolean);
      const lines = [
        '---',
        `title: ${JSON.stringify(s.title.trim())}`,
        `slug: ${s.slug}`,
        `published: ${s.published ? 'true' : 'false'}`,
        `draft: ${s.asDraft ? 'true' : 'false'}`,
        `date: ${s.date !== '' ? s.date : new Date().toISOString().slice(0, 10)}`,
        `tags: [${tagList.map((t) => JSON.stringify(t)).join(', ')}]`
      ];
      lines.push('---');
      const nextRaw = `${lines.join('\n')}\n\n${body}`.trimEnd() + '\n';
      await putDraftRaw(s.type, s.slug, nextRaw, s.hash);
      // Baking only makes sense for a live page — draft-born pages wait for the
      // regular publish path (frontmatter flags ride verbatim, store-side).
      if (s.published) {
        await publishDraft(s.type, s.slug, renderMdms(nextRaw, { type: s.type, slug: s.slug }).fullHtml);
      }
      // the crown moves only when the switch moved — one key of the settings
      // PUT, the server merges the rest; '' hands the crown to the fallback
      const crown = s.isHome !== s.wasHome ? (s.isHome ? s.slug : '') : null;
      if (crown !== null) {
        await setHomePage(crown);
      }
      request = null;
      onsaved?.(crown);
    } catch (e) {
      s.error = e instanceof Error ? e.message : String(e);
      s.saving = false;
    }
  }
</script>

{#if request}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Page settings" tabindex="-1" onclick={() => (request = null)}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={save} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Settings — {request.type}/{request.slug}</h3>
        <button type="button" class="close-x" onclick={() => (request = null)}>×</button>
      </header>

      <label class="field">
        <span>Title</span>
        <input class="input" bind:value={request.title} required />
      </label>
      <fieldset class="border border-dashed border-line p-2 flex flex-col gap-1 text-sm">
        <legend class="text-xs text-ink-soft px-1">Serve this page as</legend>
        <label class="check"><input type="radio" bind:group={request.asDraft} value={false} /> Published — visible to the public</label>
        <label class="check"><input type="radio" bind:group={request.asDraft} value={true} /> Draft — editors only until published</label>
      </fieldset>
      <label class="field">
        <span>Date</span>
        <input class="input" type="date" bind:value={request.date} />
      </label>
      <label class="field">
        <span>Tags (comma-separated)</span>
        <input class="input" bind:value={request.tags} />
      </label>
      {#if admin}
        <label class="check">
          <input type="checkbox" bind:checked={request.isHome} />
          Home page <span class="hint">— the front door; the crown moves with this switch</span>
        </label>
      {/if}

      <p class="text-xs text-ink-soft m-0">Save writes the draft under the same conflict guard the editor used; a published page bakes live with the rendered body. Slug changes belong to the ✎ rename, not here.</p>
      {#if request.error}<p class="alert alert-err m-0" role="alert">{request.error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (request = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={request.saving}>{request.saving ? 'Baking…' : 'Save + bake'}</button>
      </footer>
    </form>
  </div>
{/if}
