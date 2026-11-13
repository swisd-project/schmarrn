<script lang="ts">
  /**
   * 1. Relative path: src/lib/components/UploadModal.svelte
   * 2. Description: THE UPLOAD DOOR (strip 5a of the p8 STEP 6 dashboard
   *                 split) — .md → modal: validate frontmatter, fix
   *                 slug/title, choose draft or publish. The parent picks
   *                 and parses the file (the file input lives in the menu)
   *                 and hands the request in; the form and the save live
   *                 here. `error` is $bindable — the menu renders it too.
   * 3. Expects: `request` — $bindable (the parsed upload); `error` —
   *             $bindable (shared with the menu's display); `oncreated` —
   *             called with the new slug (the parent refreshes + selects).
   * 4. Provides: The modal. Nothing else.
   * 5. SPDX-License-Identifier: MPL-2.0
   * 6. Copyright (c) 2026 Homahuki GmbH
   */

  import { createPage } from '$lib/api';
  import { splitFrontmatter } from '$lib/blocks';
  import { withDraftFlag } from '$lib/schema';

  type Request = {
    fileName: string;
    raw: string;
    slug: string;
    title: string;
    issues: string[];
    valid: boolean;
    asDraft: boolean;
  };

  let {
    request = $bindable<Request | null>(null),
    error = $bindable(''),
    oncreated,
  }: {
    request?: Request | null;
    error?: string;
    oncreated?: (slug: string) => void;
  } = $props();

  let busy = $state(false);

  async function save(event: SubmitEvent) {
    event.preventDefault();
    const m = request;
    if (m === null) return;
    busy = true;
    error = '';
    try {
      const split = splitFrontmatter(m.raw);
      let rawToStore: string;
      if (m.valid) {
        rawToStore = m.asDraft ? withDraftFlag(m.raw, true) : m.raw;
      } else {
        // Broken frontmatter: rebuild it from the modal fields, keep the body verbatim.
        const today = new Date().toISOString().slice(0, 10);
        const frontmatter = `---\ntitle: ${JSON.stringify(m.title)}\nslug: ${m.slug}\npublished: true\ndraft: ${m.asDraft}\ndate: ${today}\ntags: []\n---`;
        rawToStore = `${frontmatter}\n\n${split.body}`;
      }
      await createPage('pages', m.slug, rawToStore);
      request = null;
      oncreated?.(m.slug); // onto the live page — fill it there
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }
</script>

{#if request}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="fixed inset-0 bg-[rgba(40,25,15,0.45)] grid place-items-center z-50" role="dialog" aria-modal="true" aria-label="Upload markdown page" tabindex="-1" onclick={() => (request = null)}>
    <!-- svelte-ignore a11y_no_noninteractive_element_interactions -->
    <form class="modal-card" onsubmit={save} onclick={(e) => e.stopPropagation()}>
      <header class="modal-head">
        <h3>Upload — {request.fileName}</h3>
        <button type="button" class="close-x" onclick={() => (request = null)}>×</button>
      </header>

      {#if request.valid}
        <p class="alert alert-ok m-0">Frontmatter checks out. Serving as-is.</p>
      {:else}
        <p class="alert alert-warn m-0">
          The frontmatter needs attention — save will rebuild it from the fields below (body stays untouched):
        </p>
        <ul class="list-disc pl-5 my-0 text-error text-xs">
          {#each request.issues as issue}
            <li>{issue}</li>
          {/each}
        </ul>
      {/if}

      <label class="field">
        <span>Title</span>
        <input class="input" bind:value={request.title} required placeholder="Page title" />
      </label>
      <label class="field">
        <span>Slug (URL)</span>
        <input class="input" bind:value={request.slug} required pattern="[a-z0-9]+(-[a-z0-9]+)*"
          title="lowercase letters, digits, hyphens" />
      </label>

      <fieldset class="border border-dashed border-line p-2 flex flex-col gap-1 text-sm">
        <legend class="text-xs text-ink-soft px-1">Serve this page as</legend>
        <label class="check"><input type="radio" bind:group={request.asDraft} value={false} /> Published — visible to the public</label>
        <label class="check"><input type="radio" bind:group={request.asDraft} value={true} /> Draft — editors only until you publish</label>
      </fieldset>

      {#if error}<p class="alert alert-err m-0" role="alert">{error}</p>{/if}
      <footer class="modal-foot">
        <button type="button" class="btn btn-secondary" onclick={() => (request = null)}>Cancel</button>
        <button type="submit" class="btn btn-primary" disabled={busy}>{busy ? 'Folding…' : 'Create page'}</button>
      </footer>
    </form>
  </div>
{/if}
