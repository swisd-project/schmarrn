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

export function session(): Promise<SessionInfo> {
  return fetch('/api/session').then(
    (r) =>
      json<SessionInfo>(r).then((s) => {
        csrf = s.csrf;
        return s;
      })
  );
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

export function removeAccount(user: string): Promise<void> {
  return fetch(`/api/account/${encodeURIComponent(user)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}
