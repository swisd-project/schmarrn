/*
 * 1. Relative path: src/public/edit.ts
 * 2. Description: The live-page editor (p6-era UX: editing happens ON the served
 *                 page). PHP injects this bundle ONLY for authenticated sessions,
 *                 wrapped in [data-sm-edit-root]. Interaction model = the pinned
 *                 canvas model, on the real page: click a block → floating menu →
 *                 modal → saved. One management door (user-designed 2026-11-04): a
 *                 crisp white rect at the right middle — collapsed it is a thin
 *                 tab with a grey triangle; click expands a neat side menu with
 *                 Backend (opens the admin overlay, carrying this page) and
 *                 Log out. The editor carries no overlay of its own.
 * 3. Expects: [data-sm-edit-root] with data-type/data-slug; /api session + draft +
 *             publish + media routes; bridge/assets/edit.esm.js (built, committed).
 * 4. Provides: `npm run build:edit` → bridge/assets/edit.esm.js via scripts/build-edit.mjs.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { splitFrontmatter, splitBlocks, joinPage, attr, directiveInner, type Block } from '../lib/blocks';
import { renderMdms } from '../lib/render';
import { backHref } from '../lib/back';

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
/* the ONE management door, user-corrected 2026-11-04: "the closed rect should be
   as tall as the opened rect" — so the menu stays IN FLOW at all times and only
   its VISIBILITY toggles (no reflow, no height change): the rect's height is
   the open height from the start, pure auto — it follows its children; only
   the WIDTH slides (measured hop in JS, transition below). Collapsed: thin
   rect, grey triangle, items waiting invisibly. Open: same rect, wider. */
.sm-admin-root {
  position:fixed; right:0; top:50%; transform:translateY(-50%); padding:.45rem;
  overflow:hidden; background:#fff; border:2px solid #e3dccd; border-right:none;
  border-radius:6px 0 0 6px; box-shadow:0 1px 4px rgba(0,0,0,.10);
  transition:width .15s ease; cursor:pointer;
  font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif; font-size:12.5px;
}
.sm-admin-root.open { cursor:auto; }
.sm-admin-tri { display:block; width:0; height:0; margin:.15rem auto .45rem;
  border-top:6px solid transparent; border-bottom:6px solid transparent;
  border-right:7px solid #767676; pointer-events:none;
}
.sm-admin-root.open .sm-admin-tri { transform:rotate(180deg); } /* open = points right (state flip, no tween) */
.sm-admin-menu { visibility:hidden; display:flex; flex-direction:column; gap:.15rem; }
.sm-admin-root.open .sm-admin-menu { visibility:visible; }
/* the retail band: the shelf between the tabs and Log out — same item law,
   one hairline to mark where the menu ends and the market begins */
.sm-admin-retail { display:flex; flex-direction:column; gap:.15rem; margin-top:.25rem; padding-top:.3rem; border-top:1px solid #e3dccd; }
.sm-admin-menu button {
  display:block; width:100%; text-align:left; white-space:nowrap;
  background:none; border:none; padding:.5rem .65rem; border-radius:4px;
  font:inherit; font-size:12.5px; font-weight:600; color:#767676; cursor:pointer;
}
.sm-admin-menu button:hover { background:#f4efe6; color:#4f4a42; }
.sm-edit-layer { position:fixed; inset:0; background:rgba(40,25,15,.45); z-index:9999; display:grid; place-items:center; }
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
  // The plugins' canvas insertables (p6 editorBlocks) — lazily fetched with
  // the page. Load failure leaves an empty shelf, never a dead editor.
  let pluginBlocks: readonly { name: string; label: string; template: string; modal: string }[] = [];
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
    const session = await apiJson<{ authenticated: boolean; role: string; csrf: string }>('/bridge/session');
    if (!session.authenticated) throw new Error('Not logged in.');
    csrf = session.csrf;
    role = session.role;
    // The page the user SEES is the published one — edit from it, never from a surprise draft.
    raw = await fetch(`/bridge/page/${type}/${slug}`).then((r) => {
      if (!r.ok) throw new Error(`API ${r.status}`);
      return r.text();
    });
    // The draft endpoint answers with the sidecar's hash in headers — the conflict
    // sentinel. A waiting draft is announced, never silently clobbered without note.
    const draft = await fetch(`/bridge/draft/${type}/${slug}`);
    if (!draft.ok) throw new Error(`API ${draft.status}`);
    hadDraft = draft.headers.get('X-Has-Draft') === '1';
    baseHash = draft.headers.get('X-Content-Hash') ?? '';
    blocks = splitBlocks(splitFrontmatter(raw).body);
    renderBody();
    mountChrome();
    // The plugins' menu row rides the same boot — off, it stays quiet.
    void fetch('/bridge/editor-blocks')
      .then((r) => (r.ok ? r.json() : []))
      .then((defs) => {
        pluginBlocks = Array.isArray(defs)
          ? defs.filter((d: unknown): d is { name: string; label: string; template: string; modal: string } =>
              !!d && typeof d === 'object'
              && typeof (d as { name?: unknown }).name === 'string'
              && typeof (d as { label?: unknown }).label === 'string'
              && typeof (d as { template?: unknown }).template === 'string')
          : [];      })
      .catch(() => { pluginBlocks = []; });
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
      // The plugin fences speak their own preview: a ```stop-motion block
      // wears its animated stage in the canvas — the md keeps the config,
      // the editor keeps an honest live monitor of the published behaviour.
      const fenceCode = holder.querySelector('code[class*="language-stop-motion"]');
      if (fenceCode !== null && fenceCode.textContent !== null && fenceCode.textContent.trim() !== '') {
        mountStopPreview(holder, fenceCode.textContent.trim());
      }
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
      const put = await fetch(`/bridge/draft/${type}/${slug}`, {
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
      await apiJson(`/bridge/draft/${type}/${slug}/publish`, {
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

  // ——— chrome: float menu, modals, toast ———

  let floatMenu: HTMLElement | null = null;

  function mountChrome(): void {
    // The ONE management door (user-designed twice over, 2026-11-04): ONE rect.
    // The tab itself is the drawer — click it and it slides wider, revealing
    // the items inside (overflow-hidden clips; nothing else moves). Items:
    // Backend (carries ?back=<this page>) and Log out. Outside clicks collapse.
    const door = document.createElement('div');
    door.className = 'sm-admin-root';
    door.setAttribute('role', 'button');
    door.setAttribute('tabindex', '0');
    door.setAttribute('aria-label', 'Admin drawer — overlay tabs and Log out');
    door.setAttribute('aria-expanded', 'false');
    let open = false;

    const toggle = () => applyOpen(!open);
    door.addEventListener('click', toggle);
    door.addEventListener('keydown', (e) => {
      if ((e.key === 'Enter' || e.key === ' ') && (e.target as HTMLElement).closest('button') === null) {
        e.preventDefault();
        toggle();
      }
    });
    door.append(el('span', { class: 'sm-admin-tri' }));

    const menu = document.createElement('div');
    menu.className = 'sm-admin-menu';
    menu.setAttribute('role', 'menu');
    // The drawer's items ARE the overlay's tabs — each opens /_ pre-selected
    // on that tab (editors only get Contents; admin tabs stay admin-only).
    const TAB_ITEMS: readonly (readonly [string, string])[] = role === 'admin'
      ? [['contents', 'Contents'], ['settings', 'Settings'], ['plugins', 'Plugins'], ['templates', 'Templates'], ['updates', 'Updates'], ['users', 'Users']]
      : [['contents', 'Contents']];
    for (const [tab, label] of TAB_ITEMS) {
      menu.append(el('button', {
        type: 'button',
        role: 'menuitem',
        onclick: (e) => {
          e.stopPropagation(); // opening the overlay is navigation, not a toggle
          window.location.href = backHref(window.location.pathname, window.location.hash, tab);
        }
      }, [label]));
    }
    menu.append(
      el('button', {
        type: 'button',
        role: 'menuitem',
        onclick: async (e) => {
          e.stopPropagation();
          try {
            await apiJson('/bridge/logout', { method: 'POST' });
          } catch {
            // the session dies server-side anyway — leave the page regardless
          }
          window.location.reload();
        }
      }, ['Log out'])
    );
    door.append(menu);
    document.body.append(door);

    // ——— the retail band (2026-11-07, user law): between the tabs and Log
    // out, installed plugins and the active template may place quality-of-life
    // edit handles — manifest-declared {label, href}, served by /bridge/drawer.
    // Fail-silent: a dark shelf leaves the drawer bare, never broken. The band
    // lands late (one fetch), so the door re-measures when it arrives — a
    // stale openW would clip the new items mid-word. ———
    void apiJson<{ handles?: { label?: unknown; href?: unknown }[] }>('/bridge/drawer')
      .then((shelf) => {
        const band = document.createElement('div');
        band.className = 'sm-admin-retail';
        for (const handle of Array.isArray(shelf.handles) ? shelf.handles : []) {
          const label = typeof handle?.label === 'string' ? handle.label : '';
          const href = typeof handle?.href === 'string' ? handle.href : '';
          if (label === '' || !(href.startsWith('/') || /^https?:\/\//.test(href))) {
            continue; // the lid already filtered; the belt has suspenders
          }
          band.append(el('button', {
            type: 'button',
            role: 'menuitem',
            onclick: (e) => {
              e.stopPropagation(); // a handle is navigation, not a toggle
              window.location.href = href;
            }
          }, [label]));
        }
        const floor = menu.lastElementChild;
        if (band.childElementCount > 0 && floor !== null) {
          menu.insertBefore(band, floor); // Log out stays the bottom item
          measure();
        }
      })
      .catch(() => undefined);

    // Measure ONLY after the drawer is mounted and fully populated — the
    // previous build measured a DETACHED element (offsetWidth 0, computed
    // padding NaN, triangle not yet appended) and wrote "0px"/"NaNpx" widths,
    // which the browser ignored → the door was born wide and never collapsed.
    // The drawer's two widths, measured once from real layout: height is NEVER
    // touched (menu stays in flow, visibility toggles) so closed = opened tall.
    const doorStyle = getComputedStyle(door);
    const framePad = parseFloat(doorStyle.paddingLeft) + parseFloat(doorStyle.paddingRight)
      + parseFloat(doorStyle.borderLeftWidth) + parseFloat(doorStyle.borderRightWidth);
    const tri = door.querySelector<HTMLElement>('.sm-admin-tri');
    const closedW = Math.ceil(tri !== null ? tri.getBoundingClientRect().width : 7) + framePad;
    // The open width is a MEASURED thing, re-measurable by law: the retail band
    // lands after one fetch, and a stale openW would clip its items mid-word.
    let openW = 0;
    const measure = (): void => {
      door.style.width = 'auto';
      openW = door.offsetWidth;
      door.style.width = `${open ? openW : closedW}px`;
    };
    measure(); // the mount measurement — the old inline law, now a verb

    function applyOpen(next: boolean): void {
      open = next;
      door.classList.toggle('open', open);
      door.setAttribute('aria-expanded', open ? 'true' : 'false');
      door.style.width = `${open ? openW : closedW}px`; // height: never
    }

    document.addEventListener('click', (e) => {
      if (!open || (e.target as HTMLElement).closest('.sm-admin-root') !== null) return;
      applyOpen(false);
    });

    document.addEventListener('click', (e) => {
      const target = e.target as HTMLElement;
      if (floatMenu !== null && target.closest('.sm-float') === null && !target.closest('[data-sm-edit-root]')) {
        deselect();
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        // Escape first dismisses the open editor modal (one Escape = one door),
        // then deselects — an open layer never survives the key.
        document.querySelector('.sm-edit-layer')?.remove();
        deselect();
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
      const index = [...root.children].indexOf(holder);
      const block = blocks[index];
      // A fence the plugins own gets its own modal: a ```stop-motion text
      // block opens the frames manager, not the raw text box (block marked).
      if (block?.kind === 'text' && /^```stop-motion\b/.test(block.source)) {
        selected = index;
        [...root.children].forEach((child, i) => {
          if (i === index) child.setAttribute('data-sm-selected', '1');
          else child.removeAttribute('data-sm-selected');
        });
        openStopMotionModal(index, false);
        return;
      }
      select(index, click);
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
    const addMore = el('div', { class: `sm-float-row${isEmpty ? '' : ' sm-float-add'}` }, [
      el('span', { style: 'font-size:11px;opacity:.7;align-self:center' }, ['More:']),
      el('button', { onclick: () => openModuleModal(insertAt, 'slide', true) }, ['Slide']),
      el('button', { onclick: () => openModuleModal(insertAt, 'note', true) }, ['Note']),
      el('button', { onclick: () => openModuleModal(insertAt, 'details', true) }, ['Details']),
      el('button', { onclick: () => openModuleModal(insertAt, 'more', true) }, ['Fold'])
    ]);
    menu.append(add, addMore);
    // ——— the plugins' row (p6 editorBlocks): manifest-declared insertables of
    // ——— every enabled plugin, labeled from the manifest, inserted as md
    // ——— templates the author shapes in the text modal.
    if (pluginBlocks.length > 0) {
      const addPlugins = el('div', { class: `sm-float-row${isEmpty ? '' : ' sm-float-add'}` }, [
        el('span', { style: 'font-size:11px;opacity:.7;align-self:center' }, ['Plugins:']),
      ...pluginBlocks.map((def) =>
      el('button', { title: def.name, onclick: () => (def.modal === 'stop-motion' ? openStopMotionModal(insertAt, true) : openTextModal(insertAt, true, def.template, def.label)) }, [def.label])
    )
      ]);
      menu.append(addPlugins);
    }
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
    // ONE modal at a time: an old layer left behind would go invisible-evil
    // and eat clicks forever (found by the stress probe — reopened modals
    // stacked, the hidden one won the pointer). Replacement is the law.
    document.querySelector('.sm-edit-layer')?.remove();
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

  /** The canvas preview mirror: swap the fence's code block for the stage the
   *  public page serves (same classes, same march, gaps ignored client-side —
   *  the monitor shows the folder's truth, the md keeps the config). */
  function mountStopPreview(holder: HTMLElement, payload: string): void {
    let cfg: { files?: unknown; dir?: unknown; fps?: unknown } = {};
    try { cfg = JSON.parse(payload) as typeof cfg; } catch { return; }
    const files = Array.isArray(cfg.files) ? (cfg.files as unknown[]).filter((f): f is string => typeof f === 'string') : [];
    if (files.length < 2) return; // not yet a stage — the code stays
    const fps = typeof cfg.fps === 'number' && cfg.fps > 0 && cfg.fps <= 30 ? cfg.fps : 8;
    const dir = typeof cfg.dir === 'string' && /^[a-z0-9][a-z0-9-]*$/.test(cfg.dir) ? cfg.dir : ''; // fence says or none — the filter's law
    const seq = document.querySelectorAll('style[data-sm-sm-preview]').length;

    if (document.querySelector('style[data-sm-sm-preview]') === null) {
      document.head.append(el('style', { 'data-sm-sm-preview': '1' }, [
        '.stop-motion-stage{position:relative;display:block;max-width:100%;margin:1rem 0}'
        + '.stop-motion-stage img{position:absolute;inset:0;width:100%;height:auto;display:block;margin:0}'
        + '.stop-motion-stage img[data-sm-lead]{position:static}'
        + '@media (prefers-reduced-motion: reduce){.stop-motion-stage img{animation:none!important}}'
      ]));
    }
    const dur = (files.length / fps).toFixed(4);
    const stage = el('span', { class: 'stop-motion-stage', role: 'img', 'aria-label': 'stop-motion preview, ' + files.length + ' frames' });
    files.forEach((file, i) => {
      const img = document.createElement('img');
      img.className = 'stop-motion-frame';
      if (i === 0) img.setAttribute('data-sm-lead', '');
      img.loading = i === 0 ? 'eager' : 'lazy';
      img.decoding = 'async';
      img.style.animation = `sm-editor-march-${seq} ${dur}s step-end infinite`;
      img.style.animationDelay = `-${(i / fps).toFixed(4)}s`;
      img.src = `/bridge/media/${context.type}/${context.slug}/` + (dir !== '' ? encodeURIComponent(dir) + '/' : '') + encodeURIComponent(file);
      stage.append(img);
    });
    document.head.append(el('style', { 'data-sm-sm-preview': String(seq + 1), 'data-sm-sm-keyframes': '' }, [
      `@keyframes sm-editor-march-${seq}{0%{opacity:1}`
      + (100 / files.length).toFixed(4) + '%{opacity:1}' + (100 / files.length).toFixed(4) + '%{opacity:0}100%{opacity:0}}'
    ]));
    holder.replaceChildren(stage);
  }

  // ——— the stop-motion block modal (p6 editorBlocks, mode 'stop-motion') ———  // Frames live in the page's dedicated stop-motion/ folder. The modal lists
  // FILENAMES only (numeric order — last run of digits, zero padding + gaps
  // agnostic), uploads multiply, deletes extract; Save writes the fence with
  // the explicit ordered file list. No previews — a file is a name here.

  const SM_MEDIA = 'stop-motion';

  /** The frame rank: the LAST run of digits decides the order; ties fall back
   *  to the name. 'frame-02.png' < 'frame-10.png'; '0001-x.jpg' fine; the
   *  missing 03 in the middle offends nobody — gaps are honest. */
  function frameRank(name: string): number {
    const m = /(\d+)(?=[^\d]*$)/.exec(name); // last digit run — zero padding + gaps agnostic
    return m ? parseInt(m[1], 10) : Number.MAX_SAFE_INTEGER;
  }
  function sortFrames(names: readonly string[]): string[] {
    return [...names].sort((a, b) => frameRank(a) - frameRank(b) || a.localeCompare(b));
  }

  function parseStopFence(source: string): { files: string[]; dir: string; fps: number } {
    const m = /^```stop-motion\s*\n([\s\S]*?)\n```/.exec(source.trim());
    let cfg: Record<string, unknown> = {};
    try { cfg = m ? (JSON.parse(m[1]) as Record<string, unknown>) : {}; } catch { cfg = {}; }
    return {
      files: Array.isArray(cfg.files) ? (cfg.files as unknown[]).filter((f): f is string => typeof f === 'string') : [],
      dir: typeof cfg.dir === 'string' && /^[a-z0-9][a-z0-9-]*$/.test(cfg.dir) ? cfg.dir : SM_MEDIA,
      fps: typeof cfg.fps === 'number' && cfg.fps > 0 && cfg.fps <= 30 ? cfg.fps : 8
    };
  }

  function openStopMotionModal(index: number, isNew: boolean): void {
    let folderNames: string[] = [];
    let fps = isNew ? 8 : parseStopFence(blocks[index]?.source ?? '').fps;

    const frameList = el('div', { class: 'sm-media-list', style: 'flex-direction:column;max-height:16rem' });
    const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:.2rem 0' });
    const fpsBox = el('input', { class: 'sm-input', type: 'number', min: '0.5', max: '30', step: '0.5', style: 'max-width:9rem' });
    fpsBox.value = String(fps);
    const upload = el('input', { class: 'sm-input', type: 'file', accept: '.png,.jpg,.jpeg,.webp,.gif', multiple: true, style: 'padding:.25rem' }) as HTMLInputElement;

    async function refresh(): Promise<void> {
      try {
        folderNames = (await apiJson<readonly { name: string }[]>(`/bridge/media/${type}/${slug}?folder=${SM_MEDIA}`)).map((f) => f.name);
      } catch (e) {
        err.textContent = e instanceof Error ? e.message : String(e);
        err.style.display = '';
        return;
      }
      err.style.display = 'none';
      frameList.replaceChildren(...sortFrames(folderNames).map((name) =>
        el('div', { style: 'display:flex;align-items:center;gap:.4rem' }, [
          el('code', { style: 'font-size:12px' }, [name]),
          el('button', {
            class: 'sm-btn sm-btn-danger',
            type: 'button',
            title: `Delete ${name} from the folder`,
            onclick: async () => {
              try {
                await fetch(`/bridge/media/${type}/${slug}/${encodeURIComponent(name)}?folder=${SM_MEDIA}`, {
                  method: 'DELETE', headers: { 'X-CSRF-Token': csrf }
                });
              } finally {
                await refresh();
              }
            }
          }, ['delete'])
        ])
      ));
      if (sortFrames(folderNames).length === 0) {
        frameList.append(el('p', { style: 'font-size:12px;opacity:.7;margin:0' }, ['No frames yet — upload files named with continuous numbers (01-0n), gaps tolerated.']));
      }
    }

    const form = el('form', {
      onsubmit: (ev: Event) => {
        ev.preventDefault();
        const parsedFps = parseFloat(fpsBox.value);
        const files = sortFrames(folderNames);
        if (files.length < 2) {
          err.textContent = 'A shutter needs at least two frames — upload images first.';
          err.style.display = '';
          return;
        }
        if (!Number.isFinite(parsedFps) || parsedFps < 0.5 || parsedFps > 30) {
          err.textContent = 'The frame speed is a number between 0.5 and 30 fps.';
          err.style.display = '';
          return;
        }
        err.style.display = 'none';
        const fence = '```stop-motion\n' + JSON.stringify({ files, dir: SM_MEDIA, fps: parsedFps }, null, 0) + '\n```';
        writeBlock(index, { kind: 'text', source: fence }, isNew);
      }
    }, [
      el('p', { class: 'sm-alert sm-alert-ok', style: 'margin:0 0 .5rem' }, [
        `Frames live in the page's ${SM_MEDIA}/ folder. Ordered by their continuous number (01-0n, gaps tolerated — filenames only, no previews).`
      ]),
      el('div', { style: 'margin:0;display:flex;gap:.4rem;align-items:center;flex-wrap:wrap' }, [
        upload,
        el('button', {
          class: 'sm-btn', type: 'button',
          onclick: async () => {
            err.style.display = 'none';
            for (const file of upload.files ?? []) {
              try {
                const body = new FormData();
                body.append('file', file);
                await fetch(`/bridge/media/${type}/${slug}?folder=${SM_MEDIA}`, {
                  method: 'POST', headers: { 'X-CSRF-Token': csrf }, body
                });
              } catch { /* the row keeps the failure note honest below */ }
            }
            upload.value = '';
            await refresh();
          }
        }, ['Upload'])
      ]),
      frameList,
      field('Frame speed (fps)', fpsBox),
      err,
      el('div', { style: 'display:flex;justify-content:flex-end;gap:.5rem' }, [
        el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, [isNew ? 'Add block' : 'Save'])
      ])
    ]);
    openModal(isNew ? 'Add Stop Motion' : 'Edit Stop Motion', form);
    void refresh();
  }

  function openTextModal(index: number, isNew = false, initial = '', label = ''): void {    const value = isNew ? (initial !== '' ? initial : 'New text — double the recipe.') : (blocks[index]?.source ?? '');
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
    openModal(isNew ? (initial !== '' && label !== '' ? `Add ${label}` : 'Add text block') : 'Edit text block', form);
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
        media = await apiJson<readonly { name: string; url: string }[]>(`/bridge/media/${type}/${slug}`);
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
          const stored = await apiJson<{ name: string }>(`/bridge/media/${type}/${slug}`, { method: 'POST', body });
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
          const stored = await apiJson<{ name: string }>(`/bridge/media/${type}/${slug}`, { method: 'POST', body });
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
    if (name === 'embed') {
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
      return;
    }

    // Everything else (slide / note / details / more / future wall modules):
    // the generic source editor — the full directive source in one textarea.
    // Templates for the common shapes; the round-trip is exact (AST spans).
    const templates: Record<string, string> = {
      slide: ':::slide{title="Slide title"}\n\nType the slide content here.\n\n:::',
      note: ':::note{kind="tip"}\nThe note text.\n:::',
      details: ':::details{summary="More"}\nThe hidden text.\n:::',
      more: ':::more\n:::'
    };
    const sourceBox = el('textarea', { class: 'sm-input', rows: '10', spellcheck: 'false' }, [isNew ? (templates[name] ?? `:::${name}\n:::`) : source]);
    const err = el('p', { class: 'sm-alert sm-alert-err', style: 'display:none; margin:0' });
    const form = el('form', {
      onsubmit: (e: Event) => {
        e.preventDefault();
        const next = sourceBox.value.trim();
        // The fence law: open and close, both present. The parser gets the last word.
        if (!/^:::[a-z][a-z-]*(\{[\s\S]*\})?\s*\n[\s\S]*\n:::$/.test(next)) {
          err.textContent = 'Keep the :::name{props} open and the ::: close on its own line.';
          err.style.display = '';
          return;
        }
        const parsed = /^:::([a-z][a-z-]*)/.exec(next)?.[1] ?? name;
        writeBlock(index, { kind: 'module', name: parsed, source: next }, isNew);
      }
    }, [
      el('p', { class: 'sm-alert sm-alert-ok', style: 'margin:0 0 .5rem' }, [`Module: ${name} — raw source, full control. Nesting a module inside? The outer fence needs more colons: ::::slide wraps :::note.`]),
      sourceBox,
      err,
      el('div', { style: 'display:flex;justify-content:flex-end;gap:.5rem' }, [
        el('button', { class: 'sm-btn sm-btn-primary', type: 'submit' }, [isNew ? `Add ${name}` : 'Save'])
      ])
    ]);
    openModal(isNew ? `Add ${name}` : `Edit ${name}`, form);
  }

  function writeBlock(index: number, block: Block, isNew: boolean): void {
    if (isNew) blocks.splice(index, 0, block);
    else blocks[index] = block;
    deselect();
    document.querySelector('.sm-edit-layer')?.remove();
    void saveAndPublish(joinPage(splitFrontmatter(raw), blocks), { reload: false });
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
