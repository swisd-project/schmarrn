/*
 * 1. Relative path: src/lib/api/wares.ts
 * 2. Description: THE WARES DOOR — the bridge module for the wares context:
 *                 the Vitrine shelf (registry fetch + state merge), the
 *                 install ride, the theme templates (activate/delete), and
 *                 the registry's own look/install (the GitHub-side ware
 *                 door). Types live with their module (api.ts split,
 *                 2026-11-13).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: listWares, installWare, listTemplates, activateTemplate,
 *              deleteTemplate, githubInspect, githubInstall + WareRef,
 *              VitrineShelf, TemplateRef, GithubWare, GithubLook.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

// ——— the Vitrine (public wares showcase: fetch + install, admin) ———

export interface WareRef {
  readonly kind: 'plugin' | 'template';
  readonly name: string;
  readonly title: string;
  readonly version: string;
  readonly description: string;
  readonly documentation?: string;
  readonly tags?: readonly string[];
  readonly requiresSchmarrn: string;
  readonly path: string;
  readonly requires: readonly string[];
  readonly state: 'installable' | 'installed' | 'update';
  readonly installedVersion?: string | null;
  readonly missing: readonly string[];
  /** The ware's shelf image — the registry's own preview file, served raw. */
  readonly previewUrl?: string;
  /** The styles a template ware offers (the registry mirrors the manifest). */
  readonly styles?: readonly string[];
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
  readonly defaultStyle?: string;
  readonly default: boolean;
  readonly active: boolean;
  /** The theme's shelf image (preview.png & friends) — null when it ships none. */
  readonly preview: string | null;
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


export interface GithubWare {
  readonly kind: 'plugin' | 'template';
  /** The ware's folder inside the repo ('' = the repo root). */
  readonly path: string;
  readonly name: string | null;
  readonly title: string;
  readonly version: string | null;
  readonly description: string;
  readonly error?: string;
}

export interface GithubLook {
  readonly repo?: string;
  readonly private?: boolean;
  readonly branch?: string;
  readonly description?: string;
  readonly wares?: readonly GithubWare[];
  readonly error?: string;
}

/** LOOK at a repo — is it a Schmarrn ware, and does its manifest answer? */
export function githubInspect(repo: string): Promise<GithubLook> {
  return fetch(`/bridge/github/inspect?repo=${encodeURIComponent(repo)}`).then((r) => json<GithubLook>(r));
}

/** STAGE a repo — the code is lided and copied, never run; it lands disabled. */
export function githubInstall(
  repo: string,
  kind: 'plugin' | 'template',
  path = ''
): Promise<{ name: string; kind: string; version: string; repo: string; enabled: boolean }> {
  return fetch('/bridge/github/install', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ repo, kind, path })
  }).then((r) => json<{ name: string; kind: string; version: string; repo: string; enabled: boolean }>(r));
}
