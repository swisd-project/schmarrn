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

/** Status-code-preserving error message out of any body shape (JSON {error} or text). */
async function apiError(res: Response): Promise<Error> {
  const body = (await res.json().catch(() => null)) as { error?: string } | null;
  return new Error(body?.error ?? res.statusText);
}

async function json<T>(res: Response): Promise<T> {
  if (!res.ok) {
    throw await apiError(res);
  }
  return res.json() as Promise<T>;
}

export function pageCount(): Promise<PageIndex> {
  return fetch('/bridge/pages').then((r) => json<PageIndex>(r));
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
  readonly requires?: readonly string[];
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

// ——— the Vitrine (public wares showcase: fetch + install, admin) ———

export interface WareRef {
  readonly kind: 'plugin' | 'template';
  readonly name: string;
  readonly title: string;
  readonly version: string;
  readonly description: string;
  readonly requiresSchmarrn: string;
  readonly path: string;
  readonly requires: readonly string[];
  readonly state: 'installable' | 'installed' | 'update';
  readonly installedVersion?: string | null;
  readonly missing: readonly string[];
}

export interface VitrineShelf {
  readonly wares: readonly WareRef[];
  readonly host?: string;
  readonly error?: string;
}

/** The merged shelf: registry wares + local install state. Registry dark → error carries the note. */
export function listWares(): Promise<VitrineShelf> {
  return fetch('/bridge/wares').then((r) => json<VitrineShelf>(r));
}

export function installWare(
  name: string
): Promise<{ ok: boolean; name: string; kind: string; version: string }> {
  return fetch('/bridge/wares/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ name })
  }).then((r) => json<{ ok: boolean; name: string; kind: string; version: string }>(r));
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

export function activateTemplate(name: string): Promise<{ ok: boolean; theme: string }> {
  return fetch('/bridge/template/activate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ name })
  }).then((r) => json<{ ok: boolean; theme: string }>(r));
}

export function deleteTemplate(name: string): Promise<{ ok: boolean; name: string }> {
  return fetch('/bridge/template/delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ name })
  }).then((r) => json<{ ok: boolean; name: string }>(r));
}

// ——— the core's self-update (GitHub versions or a parked local packet, admin) ———

export interface CoreStatus {
  readonly current: string;
  readonly latest: string | null;
  readonly localVersion?: string | null;
  readonly error?: string;
}

export function coreStatus(): Promise<CoreStatus> {
  return fetch('/bridge/core').then((r) => json<CoreStatus>(r));
}

/** Park a locally downloaded update packet — the outage handle. Admin, CSRF-gated. */
export function uploadCorePacket(file: File): Promise<{ ok: boolean; version: string; bytes: number }> {
  const body = new FormData();
  body.append('file', file);
  return fetch('/bridge/core/packet', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() },
    body
  }).then((r) => json<{ ok: boolean; version: string; bytes: number }>(r));
}

export function runCoreUpdate(source: 'hub' | 'local' = 'hub'): Promise<{
  ok: boolean;
  from: string;
  to: string;
  entries: number;
  newPagetypes: number;
  dashboard: boolean;
  note: string;
}> {
  return fetch('/bridge/core/update', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ source })
  }).then((r) => json<{
    ok: boolean;
    from: string;
    to: string;
    entries: number;
    newPagetypes: number;
    dashboard: boolean;
    note: string;
  }>(r));
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
