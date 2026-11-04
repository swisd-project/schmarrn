/*
 * 1. Relative path: src/lib/api.ts
 * 2. Description: Typed fetcher for the PHP /api core. Zero stringly-typed URLs.
 * 3. Expects: The PHP front controller to be reachable at /api (dev: Vite proxy to :8080).
 * 4. Provides: pageCount, createPage, deletePage, renamePage, login, logout, session,
 *              site settings, accounts, plugins, media sweep, CSRF token relay.
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
const csrfToken = () => csrf;

async function json<T>(res: Response): Promise<T> {
  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as { error?: string } | null;
    throw new Error(body?.error ?? `API ${res.status}`);
  }
  return res.json() as Promise<T>;
}

export function pageCount(): Promise<PageIndex> {
  return fetch('/bridge/pages').then((r) => json<PageIndex>(r));
}

// ——— editorial flow ———
// (The live editor speaks to /bridge/draft + /bridge/draft/<t>/<s>/publish directly —
// raw fetch with the X-Base-Hash conflict guard. The dashboard only creates,
// deletes and renames; the guarded draft save lives in src/public/edit.ts.)

export function login(user: string, password: string): Promise<SessionInfo> {
  return fetch('/bridge/login', {
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

export function session(): Promise<SessionInfo> {
  return fetch('/bridge/session').then(
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
  readonly style: string;
}

export function getSiteSettings(): Promise<SiteSettings> {
  return fetch('/bridge/site').then((r) => json<SiteSettings>(r));
}

export function saveSiteSettings(settings: SiteSettings): Promise<void> {
  return fetch('/bridge/site', {
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
  return fetch('/bridge/plugins').then((r) => json<readonly PluginRef[]>(r));
}

/** The whole enabled map in one PUT — only installed plugins survive the core's lid. */
export function setPlugins(enabled: Readonly<Record<string, boolean>>): Promise<void> {
  return fetch('/bridge/plugins', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ enabled })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

// ——— templates (theme-contract wares, admin inventory) ———

export interface TemplateRef {
  readonly name: string;
  readonly title: string;
  readonly version: string;
  readonly description: string;
  readonly styles: readonly string[];
  readonly active: boolean;
}

export function listTemplates(): Promise<readonly TemplateRef[]> {
  return fetch('/bridge/templates').then((r) => json<readonly TemplateRef[]>(r));
}

// ——— account management (admin) ———

export function listAccounts(): Promise<readonly AccountRef[]> {
  return fetch('/bridge/accounts').then((r) => json<readonly AccountRef[]>(r));
}

export function addAccount(
  user: string,
  password: string,
  role: 'admin' | 'editor',
  email = ''
): Promise<void> {
  return fetch('/bridge/accounts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ user, password, role, email })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

/** Mail (re)issued credentials to the account's email. 503 when SMTP unconfigured. */
export function inviteAccount(user: string): Promise<void> {
  return fetch(`/bridge/account/${encodeURIComponent(user)}/invite`, {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

// (The reset/password endpoints — /bridge/reset-request, /bridge/reset-confirm,
// /bridge/me/password, PUT /bridge/account/<u> — stay live in the PHP core as the
// self-service feature; their dashboard UI doesn't exist yet. Typed wrappers
// return when that UI does.)

// ——— page media ———
// (The live editor talks to /bridge/media directly; these wrappers exist for the
// dashboard's site-wide media sweep.)

/** One file somewhere on the site — the overlay's media tab sweeps these. */
export interface SiteMediaRef {
  readonly type: string;
  readonly slug: string;
  readonly name: string;
  readonly size: number;
  readonly url: string;
}

export function listAllMedia(): Promise<readonly SiteMediaRef[]> {
  return fetch('/bridge/media').then((r) => json<readonly SiteMediaRef[]>(r));
}

/** Upload a site-level asset (the logo) — admin, CSRF-gated. Returns its public URL. */
export function uploadSiteAsset(file: File): Promise<{ name: string; url: string }> {
  const body = new FormData();
  body.append('file', file);
  return fetch('/bridge/site-assets', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() },
    body
  }).then((r) => json<{ name: string; url: string }>(r));
}

export function removeAccount(user: string): Promise<void> {
  return fetch(`/bridge/account/${encodeURIComponent(user)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}
