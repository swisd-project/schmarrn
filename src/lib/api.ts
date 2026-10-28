/*
 * 1. Relative path: src/lib/api.ts
 * 2. Description: Typed fetcher for the PHP /api core. Zero stringly-typed URLs.
 * 3. Expects: The PHP front controller to be reachable at /api (dev: Vite proxy to :8080).
 * 4. Provides: listPages, readPage, writePage, login, logout, session, account CRUD,
 *              CSRF token relay (header on every mutating call).
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export interface PageRef {
  readonly slug: string;
  readonly updatedAt: number;
  readonly draft?: boolean;
  readonly hasDraft?: boolean;
}
export type PageIndex = Readonly<Record<string, readonly PageRef[]>>;

export interface SessionInfo {
  readonly authenticated: boolean;
  readonly user: string;
  readonly role: 'admin' | 'editor' | '';
  readonly csrf: string;
}

export interface AccountRef {
  readonly user: string;
  readonly role: 'admin' | 'editor';
  readonly email?: string;
}

let csrf = '';
export const csrfToken = () => csrf;

async function json<T>(res: Response): Promise<T> {
  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as { error?: string } | null;
    throw new Error(body?.error ?? `API ${res.status}`);
  }
  return res.json() as Promise<T>;
}

export function pageCount(): Promise<PageIndex> {
  return fetch('/api/pages').then((r) => json<PageIndex>(r));
}

export async function readPage(type: string, slug: string): Promise<string> {
  const res = await fetch(`/api/page/${type}/${slug}`);
  if (!res.ok) throw new Error(`API ${res.status}`);
  return res.text();
}

export function writePage(type: string, slug: string, raw: string): Promise<void> {
  return updatePage('PUT', type, slug, raw);
}

// ——— editorial copies + publishing ———

/** Raised when a guarded draft save hits a newer server copy (409). */
export class ConflictError extends Error {
  /** Fingerprint of the server's current copy — empty when unknown. */
  readonly serverHash: string;
  constructor(serverHash: string) {
    super('Draft changed elsewhere since you started editing.');
    this.name = 'ConflictError';
    this.serverHash = serverHash;
  }
}

export async function readDraft(type: string, slug: string): Promise<{ raw: string; hasDraft: boolean; hash: string }> {
  const res = await fetch(`/api/draft/${type}/${slug}`);
  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as { error?: string } | null;
    throw new Error(body?.error ?? `API ${res.status}`);
  }
  const raw = await res.text();
  return { raw, hasDraft: res.headers.get('X-Has-Draft') === '1', hash: res.headers.get('X-Content-Hash') ?? '' };
}

/** Guarded autosave. With baseHash set, a stale save throws ConflictError instead
 *  of clobbering — the flaky-internet guardrail. Omit baseHash to force-overwrite. */
export async function writeDraft(type: string, slug: string, raw: string, baseHash?: string): Promise<string> {
  const res = await fetch(`/api/draft/${type}/${slug}`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'text/markdown',
      'X-CSRF-Token': csrfToken(),
      ...(baseHash ? { 'X-Base-Hash': baseHash } : {})
    },
    body: raw
  });
  if (res.status === 409) {
    throw new ConflictError(res.headers.get('X-Current-Hash') ?? '');
  }
  const body = await json<{ ok: boolean; hash: string }>(res);
  return body.hash;
}

export function publishPage(type: string, slug: string, html?: string): Promise<void> {
  // With html: the browser-rendered body rides along (GOSPEL serving law — PHP
  // never parses Markdown). Without: publish only, fallback shell covers serving.
  const hasHtml = typeof html === 'string';
  return fetch(`/api/draft/${type}/${slug}/publish`, {
    method: 'POST',
    headers: {
      'X-CSRF-Token': csrfToken(),
      ...(hasHtml ? { 'Content-Type': 'application/json' } : {})
    },
    ...(hasHtml ? { body: JSON.stringify({ html }) } : {})
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function unpublishPage(type: string, slug: string): Promise<void> {
  return fetch(`/api/draft/${type}/${slug}/unpublish`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

async function updatePage(method: 'PUT' | 'POST', type: string, slug: string, raw: string): Promise<never | void> {
  await fetch(`/api/page/${type}/${slug}`, {
    method,
    headers: { 'Content-Type': 'text/markdown', 'X-CSRF-Token': csrfToken() },
    body: raw
  }).then((r) => json<{ ok: boolean }>(r));
}

export function login(user: string, password: string): Promise<SessionInfo> {
  return fetch('/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ user, password })
  }).then((r) =>
    json<SessionInfo>(r).then((s) => {
      csrf = s.csrf;
      return s;
    })
  );
}

export function logout(): Promise<void> {
  return fetch('/api/logout', { method: 'POST', headers: { 'X-CSRF-Token': csrfToken() } }).then(() => undefined);
}

export function createPage(type: string, slug: string, raw: string): Promise<void> {
  return updatePage('POST', type, slug, raw);
}

export function deletePage(type: string, slug: string): Promise<void> {
  return fetch(`/api/page/${type}/${slug}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function renamePage(type: string, slug: string, to: string): Promise<string> {
  return fetch(`/api/page/${type}/${slug}/rename`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ slug: to })
  }).then((r) => json<{ ok: boolean; slug: string }>(r).then((b) => b.slug));
}

export function session(): Promise<SessionInfo> {
  return fetch('/api/session').then(
    (r) =>
      json<SessionInfo>(r).then((s) => {
        csrf = s.csrf;
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
  return fetch('/api/setup/status').then((r) => json<SetupStatus>(r));
}

/** Bootstrap the bakery: first admin + brand settings, auto-login on success. */
export function runSetup(payload: {
  user: string;
  password: string;
  siteName: string;
  claim?: string;
  theme?: string;
}): Promise<SessionInfo> {
  return fetch('/api/setup', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  }).then((r) =>
    json<SessionInfo>(r).then((s) => {
      csrf = s.csrf;
      return s;
    })
  );
}

// ——— brand settings (admin) ———

export interface SiteSettings {
  readonly name: string;
  readonly claim: string;
  readonly logo: string;
  readonly theme: string;
  readonly home: string;
}

export function getSiteSettings(): Promise<SiteSettings> {
  return fetch('/api/site').then((r) => json<SiteSettings>(r));
}

export function saveSiteSettings(settings: SiteSettings): Promise<void> {
  return fetch('/api/site', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(settings)
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

// ——— plugins (admin) ———

export interface PluginRoute {
  readonly path: string;
  readonly auth: 'public' | 'user' | 'admin';
  readonly csrf: boolean;
}

export interface PluginRef {
  readonly name: string;
  readonly title: string;
  readonly version: string;
  readonly description: string;
  readonly placeholders: readonly string[];
  readonly routes: readonly PluginRoute[];
  readonly editorBlocks: readonly string[];
  readonly enabled: boolean;
  readonly error?: string;
}

export function listPlugins(): Promise<readonly PluginRef[]> {
  return fetch('/api/plugins').then((r) => json<readonly PluginRef[]>(r));
}

/** The whole enabled map in one PUT — only installed plugins survive the core's lid. */
export function setPlugins(enabled: Readonly<Record<string, boolean>>): Promise<void> {
  return fetch('/api/plugins', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ enabled })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

// ——— account management (admin) ———

export function listAccounts(): Promise<readonly AccountRef[]> {
  return fetch('/api/accounts').then((r) => json<readonly AccountRef[]>(r));
}

export function addAccount(
  user: string,
  password: string,
  role: 'admin' | 'editor',
  email = ''
): Promise<void> {
  return fetch('/api/accounts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ user, password, role, email })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function patchAccount(
  user: string,
  patch: { password?: string; role?: 'admin' | 'editor'; email?: string }
): Promise<void> {
  return fetch(`/api/account/${encodeURIComponent(user)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(patch)
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

/** Mail (re)issued credentials to the account's email. 503 when SMTP unconfigured. */
export function inviteAccount(user: string): Promise<void> {
  return fetch(`/api/account/${encodeURIComponent(user)}/invite`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export interface ResetDoors {
  requestToken(user: string): Promise<{ ok: boolean; message: string }>;
  confirmReset(user: string, token: string, password: string): Promise<void>;
}

export function requestResetToken(user: string): Promise<{ ok: boolean; message: string }> {
  return fetch('/api/reset-request', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user })
  }).then((r) => json<{ ok: boolean; message: string }>(r));
}

export function confirmReset(user: string, token: string, password: string): Promise<void> {
  return fetch('/api/reset-confirm', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user, token, password })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

export function changeOwnPassword(current: string, password: string): Promise<void> {
  return fetch('/api/me/password', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ current, password })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

// ——— edit locks ———

export interface LockStatus {
  readonly holder: string | null;
  readonly expiresIn: number;
}

export function peekLock(type: string, slug: string): Promise<LockStatus> {
  return fetch(`/api/lock/${type}/${slug}`).then((r) => json<LockStatus>(r));
}

export function acquireLock(type: string, slug: string, force = false): Promise<LockStatus> {
  return fetch(`/api/lock/${type}/${slug}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ force })
  }).then(
    (r) =>
      json<LockStatus>(r),
    (e) => {
      throw e;
    }
  );
}

export function releaseLock(type: string, slug: string): Promise<void> {
  return fetch(`/api/lock/${type}/${slug}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

// ——— page media ———

export interface MediaRef {
  readonly name: string;
  readonly size: number;
  readonly mtime: number;
}

export function listMedia(type: string, slug: string): Promise<readonly MediaRef[]> {
  return fetch(`/api/media/${type}/${slug}`).then((r) => json<readonly MediaRef[]>(r));
}

/** One file somewhere on the site — the overlay's media tab sweeps these. */
export interface SiteMediaRef {
  readonly type: string;
  readonly slug: string;
  readonly name: string;
  readonly size: number;
  readonly url: string;
}

export function listAllMedia(): Promise<readonly SiteMediaRef[]> {
  return fetch('/api/media').then((r) => json<readonly SiteMediaRef[]>(r));
}

/** Upload a site-level asset (the logo) — admin, CSRF-gated. Returns its public URL. */
export function uploadSiteAsset(file: File): Promise<{ name: string; url: string }> {
  const body = new FormData();
  body.append('file', file);
  return fetch('/api/site-assets', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() },
    body
  }).then((r) => json<{ name: string; url: string }>(r));
}

export function uploadMedia(type: string, slug: string, file: File): Promise<MediaRef> {
  const form = new FormData();
  form.append('file', file);
  return fetch(`/api/media/${type}/${slug}`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() },
    body: form
  }).then((r) => json<MediaRef>(r));
}

export const mediaUrlOf = (type: string, slug: string, name: string): string =>
  `/api/media/${type}/${slug}/${encodeURIComponent(name)}`;

export function removeAccount(user: string): Promise<void> {
  return fetch(`/api/account/${encodeURIComponent(user)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}
