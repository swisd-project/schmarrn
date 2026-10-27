/*
 * 1. Relative path: src/lib/api.ts
 * 2. Description: Typed fetcher for the PHP /api core. Zero stringly-typed URLs.
 * 3. Expects: The PHP front controller to be reachable at /api (dev: Vite proxy to :8080).
 * 4. Provides: listPages, readPage, writePage, login, logout, session.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export interface PageRef {
  readonly slug: string;
  readonly updatedAt: number;
}
export type PageIndex = Readonly<Record<string, readonly PageRef[]>>;

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

export async function writePage(type: string, slug: string, raw: string): Promise<void> {
  await fetch(`/api/page/${type}/${slug}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'text/markdown' },
    body: raw
  }).then((r) => json<{ ok: boolean }>(r));
}

export function login(password: string): Promise<{ ok: boolean }> {
  return fetch('/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ password })
  }).then((r) => json<{ ok: boolean }>(r));
}

export function logout(): Promise<void> {
  return fetch('/api/logout', { method: 'POST' }).then(() => undefined);
}

export function session(): Promise<{ authenticated: boolean }> {
  return fetch('/api/session').then((r) => json<{ authenticated: boolean }>(r));
}
