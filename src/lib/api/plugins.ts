/*
 * 1. Relative path: src/lib/api/plugins.ts
 * 2. Description: THE PLUGINS DOOR — the bridge module for the plugins
 *                 context: the roster, the enable map, delete, the settings
 *                 draft, the plugin's own PANEL (descriptor + actions).
 *                 Types live with their module (the api.ts split, 2026-11-13).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: listPlugins, setPlugins, deletePlugin, savePluginSettings,
 *              fetchPluginPanel, pluginPanelAction + PluginRoute, PluginPanel,
 *              PluginRef, PluginSetting.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

/** THE EDITOR-BLOCKS CONTRACT (the shared-contract ride, 2026-11-13): the
 *  shape every ENABLED plugin's manifest insertable rides from
 *  GET /bridge/editor-blocks into the live-page editor's insert menu — one
 *  type, both sides read it, the editor's runtime filter keeps its job.
 *  `modal` is optional (the manifest may omit it — the editor falls back to
 *  the plain text modal). */
export interface EditorBlockDef {
  readonly name: string;
  readonly label: string;
  readonly template: string;
  readonly modal?: string;
}

// ——— plugins (admin) ———

export interface PluginRoute {
  readonly path: string;
  readonly auth: 'public' | 'user' | 'admin';
  readonly csrf: boolean;
}

/** The plugin's own PANEL (2026-11-12): the core renders what the plugin
 *  hands over and understands none of it. The plugin answers its declared
 *  panel route — GET hands the descriptor, POST performs one action. */
export interface PluginPanel {
  readonly title?: string;
  readonly note?: string;
  readonly status?: { readonly label: string; readonly value: string };
  readonly actions?: readonly {
    readonly id: string;
    readonly label: string;
    readonly modal?: { readonly label: string; readonly type?: string; readonly placeholder?: string };
  }[];
  readonly list?: {
    readonly label?: string;
    readonly columns?: readonly { readonly key: string; readonly label: string }[];
    readonly rows?: readonly {
      readonly id?: string;
      readonly cells?: Readonly<Record<string, string>>;
      readonly actions?: readonly { readonly id: string; readonly label: string }[];
    }[];
  };
  /** A one-time reveal (the plugin's own words, its own secret). */
  readonly show?: { readonly label: string; readonly value: string };
}

export function fetchPluginPanel(name: string, route: string): Promise<PluginPanel> {
  return fetch(`/bridge/plugin/${name}/${route}`).then((r) => json<PluginPanel>(r));
}

export function pluginPanelAction(
  name: string,
  route: string,
  body: { action: string; id?: string; value?: string }
): Promise<PluginPanel> {
  return fetch(`/bridge/plugin/${name}/${route}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(body)
  }).then((r) => json<PluginPanel>(r));
}

export interface PluginRef {
  readonly name: string;
  readonly title: string;
  readonly version: string;
  readonly description: string;
  readonly documentation?: string;
  readonly tags?: readonly string[];
  readonly placeholders: readonly string[];
  readonly routes: readonly PluginRoute[];
  readonly editorBlocks: readonly string[];
  readonly enabled: boolean;
  readonly error?: string;
  readonly requires?: readonly string[];
  /** The plugin's shelf image (assets/preview.png & friends) — null when it ships none. */
  readonly preview?: string | null;
  /** Manifest-declared settings fields ({key,label,type}) — the settings tab's word. */
  readonly settings?: readonly PluginSetting[];
  /** The stored values — one row per declared key. */
  readonly settingsValues?: Readonly<Record<string, string | boolean>>;
  /** The plugin's own panel (null when it declares none). */
  readonly panel?: { readonly route: string } | null;
}

export interface PluginSetting {
  readonly key: string;
  readonly label: string;
  readonly type: 'text' | 'secret' | 'toggle';
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

/** Delete an installed plugin — its folder, its lock line and its switch leave.
 *  No plugin is undeletable (the preinstalled default included). */
export function deletePlugin(name: string): Promise<{ ok: boolean; name: string }> {
  return fetch('/bridge/plugin/delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ name })
  }).then((r) => json<{ ok: boolean; name: string }>(r));
}

/** Save a plugin's declared settings — manifest-lidded on the core side;
 *  foreign keys are dropped, values typed (toggle → bool, text/secret → string). */
export function savePluginSettings(
  name: string,
  settings: Record<string, string | boolean>
): Promise<{ ok: boolean; settings: Record<string, string | boolean> }> {
  return fetch('/bridge/plugin/settings', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ name, settings })
  }).then((r) => json<{ ok: boolean; settings: Record<string, string | boolean> }>(r));
}
