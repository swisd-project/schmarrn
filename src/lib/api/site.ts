/*
 * 1. Relative path: src/lib/api/site.ts
 * 2. Description: THE SITE DOOR — the bridge module for the site context:
 *                 the brand settings (+ the theme handles the Settings tab
 *                 turns), the home crown, and the site's media (the attic
 *                 sweep + the logo asset upload). Types live with their
 *                 module (the api.ts split, 2026-11-13).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: getSiteSettings, saveSiteSettings, setHomePage, listAllMedia,
 *              uploadSiteAsset + ThemeHandle, SiteSettings,
 *              SiteSettingsPatch, SiteMediaRef.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

// ——— brand settings (admin) ———

/** A theme handle — a CSS variable the active theme declares in theme.json
 *  "handles" and the Settings tab may turn. `value` is the effective one:
 *  the stored override, else the theme's default. */
export interface ThemeHandle {
  readonly var: string;
  readonly label: string;
  readonly type: 'color' | 'text';
  readonly default: string;
  readonly value: string;
}

export interface SiteSettings {
  readonly name: string;
  readonly claim: string;
  readonly logo: string;
  readonly theme: string;
  readonly home: string;
  readonly style: string;
  readonly font: string;
  readonly handles: readonly ThemeHandle[];
}

/** The settings patch. The handles ride as a map of var → value — the ACTIVE
 *  theme's overrides; an absent handle falls back to the theme's default
 *  (that is how a handle is reset). */
export type SiteSettingsPatch = Partial<Omit<SiteSettings, 'handles'>> & { handles?: Record<string, string> };

export function getSiteSettings(): Promise<SiteSettings> {
  return fetch('/bridge/site').then((r) => json<SiteSettings>(r));
}

export function saveSiteSettings(settings: SiteSettingsPatch): Promise<void> {
  return fetch('/bridge/site', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(settings)
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}

/** Crown a page as the home (or clear the crown with '') — one key of the
 *  settings PUT; the server merges the rest of Site\load() back in. The
 *  home's ONLY writer: the crown toggle in the page settings modal. */
export function setHomePage(slug: string): Promise<void> {
  return fetch('/bridge/site', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ home: slug })
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}
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
