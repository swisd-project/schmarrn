/*
 * 1. Relative path: src/lib/api/pages.ts
 * 2. Description: THE PAGES DOOR — the bridge module for the pages context:
 *                 the tree, the needle, the editorial flow (draft read/write/
 *                 publish under the X-Base-Hash guard), page create/delete/
 *                 rename, page types, the session + the first-visit setup +
 *                 the password reset. One module, one context (the api.ts
 *                 split, 2026-11-13; types live with their module).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: pageCount, searchPages, login, logout, createPage, deletePage,
 *              renamePage, listPagetypes, getDraftRaw, putDraftRaw,
 *              publishDraft, session, setupStatus, runSetup, resetRequest,
 *              resetConfirm + the context's types (PageRef, PageIndex,
 *              SessionInfo, PagetypeRef, SetupStatus).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { setCsrf, csrfToken, apiError, json } from './client';

export interface PageRef {
  readonly slug: string;
  readonly updatedAt: number;
  readonly draft?: boolean;
  readonly hasDraft?: boolean;
  readonly home?: boolean;
  readonly tags?: readonly string[];
}
export type PageIndex = Readonly<Record<string, readonly PageRef[]>>;

export interface SessionInfo {
  readonly authenticated: boolean;
  readonly user: string;
  readonly role: 'admin' | 'editor' | '';
  readonly csrf: string;
}

export function pageCount(): Promise<PageIndex> {
  return fetch('/bridge/pages').then((r) => json<PageIndex>(r));
}

/** The Contents desk's text search — authed (it reads drafts), a byte search
 *  over the editorial copies server-side; the same grouped shape as pageCount. */
export function searchPages(q: string): Promise<PageIndex> {
  return fetch(`/bridge/search?q=${encodeURIComponent(q)}`).then((r) => json<PageIndex>(r));
}

// ——— editorial flow ———
// The dashboard overlay is the ONE management surface (2026-11-04, user order:
// one burger — the editor carries no burger/panel of its own anymore). Draft
// reads/writes + publish live here under the same X-Base-Hash conflict guard the
// editor used; page locks self-expire (TTL 120s) — no release route, by design.

export function login(user: string, password: string): Promise<SessionInfo> {
  return fetch('/bridge/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ user, password })
  }).then((r) =>
    json<SessionInfo>(r).then((s) => {
      setCsrf(s.csrf);
      return s;
    })
  );
}

export function logout(): Promise<void> {
  return fetch('/bridge/logout', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() } }).then(() => undefined);
}

export function createPage(type: string, slug: string, raw: string): Promise<void> {
  return fetch(`/bridge/page/${type}/${slug}`, {
    method: 'POST',
    headers: { 'Content-Type': 'text/markdown', 'X-CSRF-Token': csrfToken() },
    body: raw
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function deletePage(type: string, slug: string): Promise<void> {
  return fetch(`/bridge/page/${type}/${slug}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function renamePage(type: string, slug: string, to: string): Promise<string> {
  return fetch(`/bridge/page/${type}/${slug}/rename`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ slug: to })
  }).then((r) => json<{ ok: boolean; slug: string }>(r).then((b) => b.slug));
}

/** Page-type scaffolds (pagetypes/*.md): the New-page modal's pre-made fills. */
export interface PagetypeRef {
  readonly name: string;
  readonly title: string;
  readonly body: string;
}

export function listPagetypes(): Promise<readonly PagetypeRef[]> {
  return fetch('/bridge/pagetypes').then((r) => json<readonly PagetypeRef[]>(r));
}

/** Raw editorial copy + its fingerprint (the X-Base-Hash the next write must echo). */
export function getDraftRaw(type: string, slug: string): Promise<{ raw: string; hash: string }> {
  return fetch(`/bridge/draft/${type}/${slug}`).then(async (r) => {
    if (!r.ok) throw await apiError(r);
    return { raw: await r.text(), hash: r.headers.get('X-Content-Hash') ?? '' };
  });
}

export function putDraftRaw(type: string, slug: string, raw: string, baseHash: string): Promise<string> {
  return fetch(`/bridge/draft/${type}/${slug}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'text/markdown', 'X-CSRF-Token': csrfToken(), 'X-Base-Hash': baseHash },
    body: raw
  }).then((r) => json<{ ok: boolean; hash: string }>(r).then((b) => b.hash));
}

/** Publish: the client's rendered HTML rides along and lands in the cache. */
export function publishDraft(type: string, slug: string, html: string): Promise<void> {
  return fetch(`/bridge/draft/${type}/${slug}/publish`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ html })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function session(): Promise<SessionInfo> {
  return fetch('/bridge/session').then(
    (r) =>
      json<SessionInfo>(r).then((s) => {
        setCsrf(s.csrf);
        return s;
      })
  );
}

// ——— first-visit setup ———

export interface SetupStatus {
  readonly needed: boolean;
  readonly themes: readonly string[];
}

export function setupStatus(): Promise<SetupStatus> {
  return fetch('/bridge/setup/status').then((r) => json<SetupStatus>(r));
}

/** Bootstrap the bakery: first admin + brand settings, auto-login on success. */
export function runSetup(payload: {
  user: string;
  password: string;
  siteName: string;
  claim?: string;
  theme?: string;
}): Promise<SessionInfo> {
  return fetch('/bridge/setup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  }).then((r) =>
    json<SessionInfo>(r).then((s) => {
      setCsrf(s.csrf);
      return s;
    })
  );
}

// ——— the password reset (login screen) ———

/** Ask for a reset token — enumeration-proof: the answer is always the same. */
export function resetRequest(user: string): Promise<{ ok: boolean; message: string }> {
  return fetch('/bridge/reset-request', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user })
  }).then((r) => json<{ ok: boolean; message: string }>(r));
}

/** Spend the token: one-time, two hours, then a new password. */
export function resetConfirm(
  user: string,
  token: string,
  password: string
): Promise<{ ok: boolean }> {
  return fetch('/bridge/reset-confirm', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user, token, password })
  }).then((r) => json<{ ok: boolean }>(r));
}
