/*
 * 1. Relative path: src/public/edit.ts
 * 2. Description: The live-page editor (p6-era UX: editing happens ON the served
 *                 page). PHP injects this bundle ONLY for authenticated sessions,
 *                 wrapped in [data-sm-edit-root]. Interaction model = the pinned
 *                 canvas model, on the real page: click a block → floating menu →
 *                 modal → saved. The burger (bottom-right) opens the page overlay:
 *                 settings (title/slug/style/date/tags — save PUBLISHES, so the
 *                 "served as" switch is instant), manage link, logout.
 * 3. Expects: [data-sm-edit-root] with data-type/data-slug; /api session + draft +
 *             publish + media routes; api/assets/edit.esm.js (built, committed).
 * 4. Provides: `npm run build:edit` → api/assets/edit.esm.js via scripts/build-edit.mjs.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { splitFrontmatter, splitBlocks, joinPage, attr, directiveInner, type Block } from '../lib/blocks';
import { renderMdms } from '../lib/render';

// ——— tiny DOM helpers (no framework on the public page) ———

function el<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attrs: Record<string, string | boolean | ((e: Event) => void)> = {},
  children: (Node | string)[] = []
): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (typeof value === 'function') (node as HTMLElement).addEventListener(key.replace(/^on/, ''), value as EventListener);
    else if (value === true) node.setAttribute(key, '');
    else if (value !== false) node.setAttribute(key, String(value));
  }
  for (const child of children) node.append(child);
  return node;
}

/** The design system, distilled for the public page (tokens + the classes we use). */
const EDIT_CSS = `
:root {
  --color-batter:#fff8ef; --color-crust:#f6e7d7; --color-line:#e5d5c0;
  --color-jam:#e2543e; --color-neon-pink:#ff2e88; --color-neon-blue:#00cfff;
  --color-ink:#3a2e26; --color-ink-soft:#6f5d4f;
  --color-ok:#4d8f5f; --color-warn:#c98a1b; --color-error:#c23a2b;
  --light:rgba(255,255,255,.95); --shade:rgba(58,46,38,.16); --shade-strong:rgba(58,46,38,.28);
  --raise-mg:-5px -5px 12px var(--light),6px 6px 14px var(--shade);
  --hover-lift:3px 4px 10px var(--shade);
  --press:inset 3px 3px 8px var(--shade),inset -3px -3px 8px var(--light);
  --float-shadow:var(--raise-mg),0 18px 44px rgba(58,46,38,.22);
  --lift:.16s cubic-bezier(.34,1.3,.64,1);
}
.sm-edit * { box-sizing:border-box; }
[data-sm-edit-root] { outline:none; }
[data-sm-edit-root]>* { position:relative; cursor:pointer; }
[data-sm-edit-root]>*:hover::after {
  content:''; position:absolute; inset:-2px; pointer-events:none;
  outline:2px dashed rgba(0,207,255,.55); outline-offset:2px;
}
[data-sm-edit-root]>[data-sm-selected] { outline:3px solid var(--color-neon-blue); outline-offset:3px; }
.sm-edit-btn {
  position:fixed; right:1.25rem; bottom:1.25rem; z-index:9998;
  width:3rem; height:3rem; display:grid; place-items:center; gap:0;
  background:var(--color-batter); border:none; border-radius:4px; cursor:pointer;
  box-shadow:var(--raise-mg); transition:box-shadow var(--lift),transform var(--lift);
}
.sm-edit-btn:hover { transform:translateY(-2px); box-shadow:var(--hover-lift); }
.sm-edit-btn span { display:block; width:1.25rem; height:2px; background:var(--color-ink); margin:5px 0; }
.sm-edit-layer { position:fixed; inset:0; background:rgba(40,25,15,.45); z-index:9999; display:grid; place-items:center; }
.sm-edit-overlay {
  position:fixed; inset:0; z-index:9999; overflow:auto;
  background:linear-gradient(145deg,#fffdf7,var(--color-batter) 45%,#f9ecd9);
  display:flex; justify-content:center; align-items:flex-start;
  padding:3rem 1.25rem 4rem;
  color:var(--color-ink); font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; font-size:14px;
}
.sm-edit-overlay-inner { width:min(34rem,100%); display:flex; flex-direction:column; gap:1rem; }
.sm-edit-head { display:flex; justify-content:space-between; align-items:center; }
.sm-edit-head h2 { margin:0; font-size:20px; font-weight:800; }
.sm-section {
  background:#fffdf7; border-radius:4px; box-shadow:var(--raise-mg);
  padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.7rem;
}
.sm-section h3 { margin:0; font-size:15px; font-weight:800; }
.sm-modal {
  background:var(--color-batter); border-radius:4px; box-shadow:var(--float-shadow);
  width:min(34rem,92vw); max-height:84vh; overflow:auto;
  display:flex; flex-direction:column; gap:.7rem; padding:1rem 1.25rem;
  color:var(--color-ink); font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; font-size:14px;
}
.sm-modal h3 { margin:0; font-weight:800; font-size:18px; }
.sm-modal-head { display:flex; justify-content:space-between; align-items:center; }
.sm-field { display:flex; flex-direction:column; gap:.25rem; }
.sm-field>span { font-size:13px; font-weight:700; }
.sm-input {
  font:inherit; color:var(--color-ink); background:var(--color-batter);
  border:none; border-radius:4px; padding:.5rem .75rem; width:100%;
  box-shadow:var(--press); transition:box-shadow var(--lift);
}
.sm-input:focus { outline:none; box-shadow:var(--press),0 0 0 3px rgba(0,207,255,.25); }
textarea.sm-input { resize:vertical; min-height:8rem; font-family:ui-monospace,monospace; }
.sm-btn {
  display:inline-flex; align-items:center; gap:.5rem; font:inherit; font-weight:700; font-size:13px;
  padding:.5rem 1rem; border-radius:4px; border:none; cursor:pointer; text-decoration:none;
  color:var(--color-ink); transition:box-shadow var(--lift),transform var(--lift),filter var(--lift);
}
.sm-btn:hover:not(:disabled) { transform:translateY(-2px); box-shadow:var(--hover-lift); }
.sm-btn:active:not(:disabled) { transform:translateY(0); box-shadow:none; }
.sm-btn:disabled { opacity:.45; cursor:not-allowed; transform:none; box-shadow:none; }
.sm-btn-primary { background:linear-gradient(145deg,#f06a52,var(--color-jam) 55%,#c94630); color:#fff; }
.sm-btn-primary:hover:not(:disabled) { filter:brightness(1.06); }
.sm-btn-secondary { background:linear-gradient(145deg,#fffdf7,var(--color-batter) 55%,#f5e7d2); }
.sm-btn-danger { background:linear-gradient(145deg,#ff55a0,var(--color-neon-pink) 55%,#d9006a); color:#fff; }
.sm-alert { padding:.5rem .75rem; border-radius:4px; font-size:13px; }
.sm-alert-err { background:rgba(194,58,43,.12); color:var(--color-error); }
.sm-alert-ok { background:rgba(77,143,95,.14); color:var(--color-ok); }
.sm-alert-warn { background:rgba(201,138,27,.14); color:var(--color-warn); }
.sm-edit-starter {
  min-height: 9rem; display: grid; place-items: center;
  border: 2px dashed var(--color-line); border-radius: 4px;
  color: var(--color-ink-soft); font-style: italic;
  transition: border-color var(--lift), color var(--lift);
}
.sm-edit-starter:hover { border-color: var(--color-neon-blue); color: var(--color-ink); }
.sm-float {
  position:fixed; z-index:9997; display:flex; flex-direction:column; gap:.25rem;
  background:var(--color-ink); color:#fff; padding:.35rem; border-radius:4px;
  box-shadow:0 8px 20px rgba(58,46,38,.3);
  font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
}
.sm-float-row { display:flex; gap:.25rem; }
.sm-float button {
  font:inherit; font-size:12px; color:#fff; background:transparent;
  border:none; padding:.3rem .55rem; cursor:pointer; border-radius:3px;
  transition:background .12s ease;
}
.sm-float button:hover { background:rgba(255,255,255,.16); }
.sm-float button.sm-danger { color:var(--color-neon-pink); }
.sm-float .sm-float-add { border-top:1px solid rgba(255,255,255,.2); padding-top:.3rem; }
.sm-media-list { display:flex; flex-wrap:wrap; gap:.35rem; max-height:7rem; overflow:auto; }
.sm-media-list button {
  font:inherit; font-size:11px; padding:.25rem .5rem; cursor:pointer;
  background:var(--color-crust); border:none; border-radius:3px; color:var(--color-ink);
}
.sm-media-list button:hover { background:var(--color-line); }
.sm-toast { position:fixed; top:1rem; left:50%; transform:translateX(-50%); z-index:10000; max-width:90vw; }
`;

// ——— state ———

const root = document.querySelector<HTMLElement>('[data-sm-edit-root]');
if (root !== null) void boot(root);

async function boot(root: HTMLElement): Promise<void> {
  const style = el('style', {}, [EDIT_CSS]);
  document.head.append(style);

  const type = root.dataset.type ?? 'pages';
  const slug = root.dataset.slug ?? '';
  const context = { type, slug };

  let csrf = '';
  let role = '';
  let raw = '';
  let baseHash = '';
  let blocks: Block[] = [];
  let selected: number | null = null;
  let busy = false;
  let hadDraft = false;

  // ——— API helpers (session + CSRF live here, not in a shared fetcher) ———

  async function apiJson<T>(path: string, init: RequestInit = {}): Promise<T> {
    const res = await fetch(path, {
      ...init,
      headers: { ...(init.headers ?? {}), ...(csrf !== '' ? { 'X-CSRF-Token': csrf } : {}) }
    });
    if (res.status === 401) throw new Error('Session expired — log in again at /_.');
    const body = (await res.json().catch(() => null)) as { error?: string } | null;
    if (!res.ok) throw new Error(body?.error ?? `API ${res.status}`);
    return body as T;
  }

  async function loadPage(): Promise<void> {
    const session = await apiJson<{ authenticated: boolean; role: string; csrf: string }>('/api/session');
    if (!session.authenticated) throw new Error('Not logged in.');
    csrf = session.csrf;
    role = session.role;
    // The page the user SEES is the published one — edit from it, never from a surprise draft.
    raw = await fetch(`/api/page/${type}/${slug}`).then((r) => {
      if (!r.ok) throw new Error(`API ${r.status}`);
      return r.text();
    });
    // The draft endpoint answers with the sidecar's hash in headers — the conflict
    // sentinel. A waiting draft is announced, never silently clobbered without note.
    const draft = await fetch(`/api/draft/${type}/${slug}`);
    if (!draft.ok) throw new Error(`API ${draft.status}`);
    hadDraft = draft.headers.get('X-Has-Draft') === '1';
    baseHash = draft.headers.get('X-Content-Hash') ?? '';
    blocks = splitBlocks(splitFrontmatter(raw).body);
    renderBody();
    mountChrome();
    if (hadDraft) toast('warn', 'An unpublished draft exists — saving here replaces it with the published page + your edit.');
  }

  // ——— rendering ———

  function renderBody(): void {
    const split = splitFrontmatter(raw);
    blocks = splitBlocks(split.body);
    root.innerHTML = '';
    if (blocks.length === 0) {
      // The empty page is itself the target: click it and the insert menu appears.
      const starter = el('div', {
        'data-sm-block': '1',
        'data-sm-empty': '1',
        class: 'sm-edit-starter'
      }, [
        el('p', {}, ['Empty page — click here to add your first block.'])
      ]);
      root.append(starter);
      return;
    }
    for (const block of blocks) {
      const holder = el('div', { 'data-sm-block': '1' });
      holder.innerHTML = renderMdms(block.source, context).fullHtml;
      root.append(holder);
    }
    selected = null;
  }

  function currentBody(): string {
    // Body ONLY — the frontmatter is rebuilt by the caller. Gluing the old
    // frontmatter back here double-stamped every settings save (the bug that
    // printed the whole frontmatter onto the page).
    return blocks.map((b) => b.source).join('\n\n');
  }

  // ——— save + publish (one act — the page IS the editor) ———

  async function saveAndPublish(nextRaw: string, opts: { reload: boolean; navigate?: string }): Promise<void> {
    if (busy) return;
    busy = true;
    try {
      const put = await fetch(`/api/draft/${type}/${slug}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'text/markdown', 'X-CSRF-Token': csrf, 'X-Base-Hash': baseHash },
        body: nextRaw
      });
      if (put.status === 409) {
        toast('err', 'This page changed elsewhere while you edited — nothing was clobbered. Reload the page to take the server copy.');
        return;
      }
      if (!put.ok) {
        const body = (await put.json().catch(() => null)) as { error?: string } | null;
        throw new Error(body?.error ?? `API ${put.status}`);
      }
      baseHash = ((await put.json()) as { hash: string }).hash;
      raw = nextRaw;
      await apiJson(`/api/draft/${type}/${slug}/publish`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ html: renderMdms(nextRaw, context).fullHtml })
      });
      if (opts.navigate !== undefined) {
        window.location.href = opts.navigate;
        return;
      }
      if (opts.reload) {
        window.location.reload();
        return;
      }
      renderBody();
      toast('ok', 'Saved and live.');
    } catch (e) {
      toast('err', e instanceof Error ? e.message : String(e));
    } finally {
      busy = false;
    }
  }

  // ——— chrome: burger, overlay, float menu, modals, toast ———

  let burger: HTMLButtonElement | null = null;
  let panel: HTMLElement | null = null;
  let floatMenu: HTMLElement | null = null;

  function mountChrome(): void {
    burger = el('button', {
      class: 'sm-edit-btn',
      title: 'Page menu — settings, manage, logout',
      'aria-label': 'Page menu',
      onclick: () => (panel === null ? openOverlay() : closeOverlay())
    });
    for (let i = 0; i < 3; i += 1) burger.append(el('span'));
    document.body.append(burger);

    document.addEventListener('click', (e) => {
      const target = e.target as HTMLElement;
      if (floatMenu !== null && target.closest('.sm-float') === null && !target.closest('[data-sm-edit-root]')) {
        deselect();
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        deselect();
        closeOverlay();
      }
    });

    root.addEventListener('click', (e) => {
      const holder = (e.target as HTMLElement).closest<HTMLElement>('[data-sm-block]');
      if (holder === null) return;
      e.stopPropagation();
      const click = e as MouseEvent;
      if (holder.dataset.smEmpty !== undefined) {
        // The empty page: no block to select — the menu is insert-only, at index 0.
        showFloatMenu(click.clientX, click.clientY, null, true);
        return;
      }
      select([...root.children].indexOf(holder), click);
    });
  }

  function select(index: number, click: MouseEvent): void {
    if (busy) return;
    selected = index;
    [...root.children].forEach((child, i) => {
      if (i === index) child.setAttribute('data-sm-selected', '1');
      else child.removeAttribute('data-sm-selected');
    });
    showFloatMenu(click.clientX, click.clientY, index);
  }

  function deselect(): void {
    selected = null;
    [...root.children].forEach((child) => child.removeAttribute('data-sm-selected'));
    floatMenu?.remove();
    floatMenu = null;
  }

  function showFloatMenu(x: number, y: number, index: number | null, isEmpty = false): void {
    floatMenu?.remove();
    const insertAt = isEmpty ? 0 : (index as number) + 1;
    const menu = el('div', { class: 'sm-float' });
    if (!isEmpty && index !== null) {
      const block = blocks[index];
      const isModule = block.kind === 'module';
      menu.append(el('div', { class: 'sm-float-row' }, [
        el('button', { onclick: () => (isModule ? openModuleModal(index) : openTextModal(index)) }, [isModule ? 'Edit module' : 'Edit text']),
        el('button', { title: 'Move up', disabled: index === 0, onclick: () => void move(index, -1) }, ['↑']),
        el('button', { title: 'Move down', disabled: index === blocks.length - 1, onclick: () => void move(index, 1) }, ['↓']),
        el('button', { class: 'sm-danger', onclick: () => void remove(index) }, ['Delete'])
      ]));
    }
    const add = el('div', { class: `sm-float-row${isEmpty ? '' : ' sm-float-add'}` }, [
      el('span', { style: 'font-size:11px;opacity:.7;align-self:center' }, [isEmpty ? 'Insert:' : 'Add:']),
      el('button', { onclick: () => openTextModal(insertAt, true) }, ['Text']),
      el('button', { onclick: () => openModuleModal(insertAt, 'image', true) }, ['Image']),
      el('button', { onclick: () => openModuleModal(insertAt, 'video', true) }, ['Video']),
      el('button', { onclick: () => openModuleModal(insertAt, 'embed', true) }, ['Embed'])
    ]);
    menu.append(add);
    // Keep the menu on-screen.
    const left = Math.min(x, window.innerWidth - 240);
    const top = Math.min(y + 8, window.innerHeight - 120);
    menu.style.left = `${Math.max(8, left)}px`;
    menu.style.top = `${Math.max(8, top)}px`;
    document.body.append(menu);
    floatMenu = menu;
  }

  async function move(index: number, delta: number): Promise<void> {
    const target = index + delta;
    if (target < 0 || target >= blocks.length) return;
    const [block] = blocks.splice(index, 1);
    blocks.splice(target, 0, block);
    deselect();
    await saveAndPublish(joinPage(splitFrontmatter(raw), blocks), { reload: false });
  }

  async function remove(index: number): Promise<void> {
    if (!window.confirm('Delete this block? It goes live immediately.')) return;
    blocks.splice(index, 1);
    deselect();
    await saveAndPublish(joinPage(splitFrontmatter(raw), blocks), { reload: false });
  }

  // ——— modals ———

  function openModal(title: string, form: HTMLFormElement): { overlay: HTMLElement; close: () => void } {
    const overlay = el('div', { class: 'sm-edit-layer', role: 'dialog', 'aria-modal': 'true' });
    const card = el('div', { class: 'sm-modal' });
    const head = el('div', { class: 'sm-modal-head' }, [
      el('h3', {}, [title]),
      el('button', {
        class: 'sm-btn sm-btn-secondary',
        type: 'button',
        onclick: () => overlay.remove()
      }, ['×'])
    ]);
    card.append(head, form);
    overlay.append(card);
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.remove();
    });
    document.body.append(overlay);
    const first = form.querySelector<HTMLInputElement | HTMLTextAreaElement>('input, textarea, select');
    first?.focus();
    return { overlay, close: () => overlay.remove() };
  }

  function field(label: string, input: HTMLElement): HTMLElement {
    return el('label', { class: 'sm-field' }, [el('span', {}, [label]), input]);
  }

  function openTextModal(index: number, isNew = false): void {
    const value = isNew ? 'New text — double the recipe.' : (blocks[index]?.source ?? '');
    const area = el('textarea', { class: 'sm-input' });
    area.value = value;
    const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });
    const form = el('form', {
      onsubmit: (e: Event) => {
        e.preventDefault();
        const source = area.value.trim() === '' ? '' : area.value;
        if (source === '') {
          err.textContent = 'A text block cannot be empty — delete it instead.';
          err.style.display = '';
          return;
        }
        writeBlock(index, { kind: 'text', source }, isNew);
      }
    }, [
      field('Markdown', area),
      err,
      el('div', { style: 'display:flex;justify-content:flex-end;gap:.5rem' }, [
        el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, [isNew ? 'Add block' : 'Save'])
      ])
    ]);
    openModal(isNew ? 'Add text block' : 'Edit text block', form);
  }

  async function openModuleModal(index: number, forcedName?: string, isNew = false): Promise<void> {
    const block = blocks[index];
    const name = forcedName ?? (block?.kind === 'module' ? (block.name ?? '') : '');
    const source = isNew ? '' : (block?.source ?? '');
    const inner = isNew ? '' : directiveInner(source);
    const attrOf = (key: string): string =>
      new RegExp(`${key}="([^"]*)"`).exec(source)?.[1] ?? '';

    let media: readonly { name: string; url: string }[] = [];
    async function loadMedia(): Promise<void> {
      try {
        media = await apiJson<readonly { name: string; url: string }[]>(`/api/media/${type}/${slug}`);
      } catch {
        media = [];
      }
    }

    if (name === 'image') {
      const file = el('input', { class: 'sm-input', value: attrOf('file'), placeholder: 'filename or https://…' });
      const caption = el('input', { class: 'sm-input', value: attrOf('caption') });
      const alt = el('input', { class: 'sm-input', value: inner });
      const upload = el('input', { type: 'file', style: 'display:none' });
      const list = el('div', { class: 'sm-media-list' });
      const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });

      function renderMedia(): void {
        list.innerHTML = '';
        for (const item of media) {
          list.append(el('button', {
            type: 'button',
            onclick: () => {
              file.value = item.name;
            }
          }, [item.name]));
        }
      }

      upload.addEventListener('change', async () => {
        const f = upload.files?.[0];
        if (!f) return;
        const body = new FormData();
        body.append('file', f);
        try {
          const stored = await apiJson<{ name: string }>(`/api/media/${type}/${slug}`, { method: 'POST', body });
          await loadMedia();
          renderMedia();
          file.value = stored.name;
        } catch (e) {
          toast('err', e instanceof Error ? e.message : String(e));
        }
        upload.value = '';
      });

      await loadMedia();
      renderMedia();

      const form = el('form', {
        onsubmit: (e: Event) => {
          e.preventDefault();
          if (file.value.trim() === '') {
            err.textContent = 'Pick or upload a file first.';
            err.style.display = '';
            return;
          }
          const attrs = `file="${attr(file.value.trim())}"${caption.value.trim() !== '' ? ` caption="${attr(caption.value.trim())}"` : ''}`;
          const next = `:::image{${attrs}}${alt.value.trim() !== '' ? `\n${alt.value.trim()}\n` : '\n'}:::`;
          writeBlock(index, { kind: 'module', name: 'image', source: next }, isNew);
        }
      }, [
        field('File (page media or https URL)', file),
        el('div', { style: 'display:flex;gap:.5rem;align-items:center' }, [
          el('button', { class: 'sm-btn sm-btn-secondary', type: 'button', onclick: () => upload.click() }, ['Upload…']),
          upload
        ]),
        list,
        field('Caption', caption),
        field('Alt text', alt),
        err,
        el('div', { style: 'display:flex;justify-content:flex-end;gap:.5rem' }, [
          el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, [isNew ? 'Add image' : 'Save'])
        ])
      ]);
      openModal(isNew ? 'Add image' : 'Edit image', form);
      return;
    }

    if (name === 'video') {
      const url = el('input', { class: 'sm-input', value: attrOf('url'), placeholder: 'https://…' });
      const file = el('input', { class: 'sm-input', value: attrOf('file'), placeholder: 'uploaded filename' });
      const upload = el('input', { type: 'file', style: 'display:none' });
      const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });
      upload.addEventListener('change', async () => {
        const f = upload.files?.[0];
        if (!f) return;
        const body = new FormData();
        body.append('file', f);
        try {
          const stored = await apiJson<{ name: string }>(`/api/media/${type}/${slug}`, { method: 'POST', body });
          file.value = stored.name;
        } catch (e) {
          toast('err', e instanceof Error ? e.message : String(e));
        }
        upload.value = '';
      });
      const form = el('form', {
        onsubmit: (e: Event) => {
          e.preventDefault();
          if (file.value.trim() === '' && url.value.trim() === '') {
            err.textContent = 'Give it a video — upload a file or paste an http(s) URL.';
            err.style.display = '';
            return;
          }
          const next = file.value.trim() !== ''
            ? `:::video{file="${attr(file.value.trim())}"}\n:::`
            : `:::video{url="${attr(url.value.trim())}"}\n:::`;
          writeBlock(index, { kind: 'module', name: 'video', source: next }, isNew);
        }
      }, [
        field('Video URL', url),
        field('…or uploaded file', file),
        el('div', { style: 'display:flex;gap:.5rem;align-items:center' }, [
          el('button', { class: 'sm-btn sm-btn-secondary', type: 'button', onclick: () => upload.click() }, ['Upload…']),
          upload
        ]),
        err,
        el('div', { style: 'display:flex;justify-content:flex-end;gap:.5rem' }, [
          el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, [isNew ? 'Add video' : 'Save'])
        ])
      ]);
      openModal(isNew ? 'Add video' : 'Edit video', form);
      return;
    }

    // embed
    const url = el('input', { class: 'sm-input', value: attrOf('url'), placeholder: 'https://…' });
    const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });
    const form = el('form', {
      onsubmit: (e: Event) => {
        e.preventDefault();
        if (!/^https?:\/\//i.test(url.value.trim())) {
          err.textContent = 'Embeds need an http(s) URL.';
          err.style.display = '';
          return;
        }
        writeBlock(index, { kind: 'module', name: 'embed', source: `:::embed{url="${attr(url.value.trim())}"}\n:::` }, isNew);
      }
    }, [
      field('Embed URL', url),
      err,
      el('div', { style: 'display:flex;justify-content:flex-end;gap:.5rem' }, [
        el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, [isNew ? 'Add embed' : 'Save'])
      ])
    ]);
    openModal(isNew ? 'Add embed' : 'Edit embed', form);
  }

  function writeBlock(index: number, block: Block, isNew: boolean): void {
    if (isNew) blocks.splice(index, 0, block);
    else blocks[index] = block;
    deselect();
    document.querySelector('.sm-edit-layer')?.remove();
    void saveAndPublish(joinPage(splitFrontmatter(raw), blocks), { reload: false });
  }

  // ——— the burger overlay: FULL SCREEN, houses page settings + site template + new page ———

  function peek(key: string): string {
    const m = new RegExp(`^${key}:\\s*(.*)$`, 'm').exec(splitFrontmatter(raw).frontmatter);
    return m?.[1]?.trim().replace(/^["']|["']$/g, '') ?? '';
  }

  function openOverlay(): void {
    if (panel !== null) return;
    const title = el('input', { class: 'sm-input', value: peek('title') });
    const slugInput = el('input', { class: 'sm-input', value: peek('slug') });
    const style = el('select', { class: 'sm-input' });
    for (const [value, label] of [['', 'theme default'], ['classical', 'classical'], ['slide', 'slide']] as const) {
      const option = el('option', { value }, [label]);
      if (peek('style') === value) option.setAttribute('selected', '');
      style.append(option);
    }
    const date = el('input', { class: 'sm-input', type: 'date', value: peek('date') });
    const tags = el('input', { class: 'sm-input', value: peek('tags').replace(/^\[|\]$/g, '') });
    const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });

    const settingsForm = el('form', {
      onsubmit: (e: Event) => {
        e.preventDefault();
        const nextSlug = slugInput.value.trim();
        if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(nextSlug)) {
          err.textContent = 'Slug: lowercase letters, digits and hyphens only.';
          err.style.display = '';
          return;
        }
        if (title.value.trim() === '') {
          err.textContent = 'The title cannot be empty.';
          err.style.display = '';
          return;
        }
        const tagList = tags.value.split(',').map((t) => t.trim()).filter(Boolean);
        const lines = [
          '---',
          `title: ${JSON.stringify(title.value.trim())}`,
          `slug: ${nextSlug}`,
          `published: ${peek('published') === 'false' ? 'false' : 'true'}`,
          `draft: ${peek('draft') === 'true' ? 'true' : 'false'}`,
          `date: ${date.value !== '' ? date.value : new Date().toISOString().slice(0, 10)}`,
          `tags: [${tagList.map((t) => JSON.stringify(t)).join(', ')}]`
        ];
        if (style.value !== '') lines.push(`style: ${style.value}`);
        lines.push('---');
        const next = `${lines.join('\n')}\n\n${currentBody()}`;
        const navigate = nextSlug !== slug
          ? (type === 'pages' ? `/${nextSlug}` : `/${type}/${nextSlug}`)
          : undefined;
        closeOverlay();
        void saveAndPublish(next, { reload: navigate === undefined, navigate });
      }
    }, [
      field('Title', title),
      field('Slug', slugInput),
      field('Served as (this page only — the site template lives below)', style),
      field('Date', date),
      field('Tags (comma-separated)', tags),
      err,
      el('button', { class: 'sm-btn sm-btn-primary', type: 'submit', style: 'align-self:flex-start' }, ['Save + publish'])
    ]);

    const overlay = el('div', { class: 'sm-edit-overlay', role: 'dialog', 'aria-modal': 'true' });
    const inner = el('div', { class: 'sm-edit-overlay-inner' });
    inner.append(el('div', { class: 'sm-edit-head' }, [
      el('h2', {}, ['Page settings']),
      el('button', { class: 'sm-btn sm-btn-secondary', onclick: () => closeOverlay() }, ['×'])
    ]));
    inner.append(el('div', { class: 'sm-section' }, [settingsForm]));

    // Admins get the SITE dial here — the global template, no backend trip.
    if (role === 'admin') {
      const siteStyle = el('select', { class: 'sm-input' });
      for (const [value, label] of [['', 'theme default'], ['classical', 'classical'], ['slide', 'slide']] as const) {
        siteStyle.append(el('option', { value }, [label]));
      }
      const siteErr = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });
      const siteSection = el('div', { class: 'sm-section' }, [
        el('h3', {}, ['Site — the template for the WHOLE site']),
        field('Template (every page, site-wide)', siteStyle),
        siteErr,
        el('button', {
          class: 'sm-btn sm-btn-primary',
          type: 'button',
          style: 'align-self:flex-start',
          onclick: async () => {
            try {
              const current = await apiJson<Record<string, string>>('/api/site');
              await apiJson('/api/site', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...current, style: siteStyle.value })
              });
              siteErr.className = 'sm-alert sm-alert-ok';
              siteErr.textContent = 'Saved — the whole site serves this template now.';
              siteErr.style.display = '';
              window.location.reload();
            } catch (e) {
              siteErr.className = 'sm-alert sm-alert-err';
              siteErr.textContent = e instanceof Error ? e.message : String(e);
              siteErr.style.display = '';
            }
          }
        }, ['Save site template'])
      ]);
      void fetch('/api/site')
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`API ${r.status}`))))
        .then((s: Record<string, string>) => {
          siteStyle.value = s.style ?? '';
        })
        .catch(() => {
          siteStyle.value = '';
        });
      inner.append(siteSection);
    }

    // The actions row: new page (the plus), logout, and the deep backend for the rest.
    const actions = el('div', { class: 'sm-section' }, [
      el('h3', {}, ['Anything else?']),
      el('div', { style: 'display:flex;gap:.5rem;flex-wrap:wrap' }, [
        el('button', { class: 'sm-btn sm-btn-primary', onclick: () => openNewPage() }, ['＋ New page']),
        el('button', {
          class: 'sm-btn sm-btn-secondary',
          onclick: async () => {
            try {
              await apiJson('/api/logout', { method: 'POST' });
            } finally {
              window.location.reload();
            }
          }
        }, ['Log out']),
        el('a', { class: 'sm-btn sm-btn-secondary', href: '/_' }, ['Manage site'])
      ])
    ]);
    inner.append(actions);
    overlay.append(inner);
    // Click somewhere on the bg → the overlay goes away.
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeOverlay();
    });
    document.body.append(overlay);
    panel = overlay;
  }

  function closeOverlay(): void {
    panel?.remove();
    panel = null;
  }

  // ——— the plus: a new page from a page-type scaffold ———

  interface PageType {
    readonly name: string;
    readonly title: string;
    readonly body: string;
  }

  let pageTypes: readonly PageType[] = [];

  function openNewPage(): void {
    const title = el('input', { class: 'sm-input', placeholder: 'The page title' });
    const slugInput = el('input', { class: 'sm-input', placeholder: 'the-page-slug' });
    const typeSelect = el('select', { class: 'sm-input' });
    const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });

    void apiJson<readonly PageType[]>('/api/pagetypes')
      .then((types) => {
        pageTypes = types;
        for (const t of types) typeSelect.append(el('option', { value: t.name }, [t.title]));
      })
      .catch((e: unknown) => {
        err.textContent = e instanceof Error ? e.message : String(e);
        err.style.display = '';
      });

    const form = el('form', {
      onsubmit: (e: Event) => {
        e.preventDefault();
        const nextSlug = slugInput.value.trim();
        const pageTitle = title.value.trim();
        if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(nextSlug)) {
          err.textContent = 'Slug: lowercase letters, digits and hyphens only.';
          err.style.display = '';
          return;
        }
        if (pageTitle === '') {
          err.textContent = 'The title cannot be empty.';
          err.style.display = '';
          return;
        }
        const scaffold = pageTypes.find((t) => t.name === typeSelect.value);
        const body = scaffold !== undefined ? scaffold.body : '';
        const lines = [
          '---',
          `title: ${JSON.stringify(pageTitle)}`,
          `slug: ${nextSlug}`,
          'published: true',
          'draft: false',
          `date: ${new Date().toISOString().slice(0, 10)}`,
          'tags: []',
          '---'
        ];
        const nextRaw = `${lines.join('\n')}\n\n${body}`.trimEnd() + '\n';
        void (async () => {
          try {
            await fetch(`/api/page/pages/${nextSlug}`, {
              method: 'POST',
              headers: { 'Content-Type': 'text/markdown', 'X-CSRF-Token': csrf },
              body: nextRaw
            }).then(async (r) => {
              if (!r.ok) {
                const body = (await r.json().catch(() => null)) as { error?: string } | null;
                throw new Error(body?.error ?? `API ${r.status}`);
              }
            });
            document.querySelector('.sm-edit-layer')?.remove();
            closeOverlay();
            window.location.href = `/${nextSlug}`;
          } catch (e2) {
            err.textContent = e2 instanceof Error ? e2.message : String(e2);
            err.style.display = '';
          }
        })();
      }
    }, [
      field('Title', title),
      field('Slug', slugInput),
      field('Page type — a pre-made page to fill', typeSelect),
      err,
      el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, ['Create page'])
    ]);
    openModal('New page', form);
  }

  // ——— toast ———

  let toastTimer: ReturnType<typeof setTimeout> | undefined;
  function toast(kind: 'ok' | 'err' | 'warn', message: string): void {
    document.querySelector('.sm-toast')?.remove();
    const node = el('div', { class: `sm-toast sm-alert sm-alert-${kind}`, role: 'alert' }, [message]);
    document.body.append(node);
    clearTimeout(toastTimer);
    if (kind !== 'err') toastTimer = setTimeout(() => node.remove(), 3500);
  }

  await loadPage().catch((e: unknown) => {
    toast('err', e instanceof Error ? e.message : String(e));
  });
}
