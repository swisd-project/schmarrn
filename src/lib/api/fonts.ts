/*
 * 1. Relative path: src/lib/api/fonts.ts
 * 2. Description: THE FONT-PACK DOOR — the bridge module for the font-pack
 *                 station: the editor bakes the core Noto subsets; an admin
 *                 pulls more writing systems (script packs) onto the host on
 *                 demand. `kb` is an honest size hint; the browser downloads
 *                 a subset only when text uses its unicode-range.
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: listFontPacks, installFontPack, removeFontPack + FontPack,
 *              FontPacks.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

export interface FontPack {
  readonly pack: string;
  readonly label: string;
  readonly kb: number;
}

export interface FontPacks {
  readonly baked: FontPack;
  readonly weights: { readonly available: readonly number[]; readonly installed: readonly number[] };
  readonly installed: readonly FontPack[];
  readonly available: readonly FontPack[];
}

export function listFontPacks(): Promise<FontPacks> {
  return fetch('/bridge/fonts').then((r) => json<FontPacks>(r));
}

export function installFontPack(pack: string): Promise<FontPacks> {
  return fetch('/bridge/fonts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ action: 'install', pack })
  }).then((r) => json<FontPacks>(r));
}

export function removeFontPack(pack: string): Promise<FontPacks> {
  return fetch('/bridge/fonts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ action: 'remove', pack })
  }).then((r) => json<FontPacks>(r));
}

/** The core bake re-cut to the ticked weights (styles). */
export function setFontStyles(weights: readonly number[]): Promise<FontPacks> {
  return fetch('/bridge/fonts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ action: 'styles', weights })
  }).then((r) => json<FontPacks>(r));
}

/** What styles does a Google family offer? The dropdown's food. */
export function googleFontStyles(family: string): Promise<readonly string[]> {
  return fetch(`/bridge/font-styles?family=${encodeURIComponent(family)}`)
    .then((r) => json<{ readonly styles: readonly string[] }>(r))
    .then((d) => d.styles);
}
