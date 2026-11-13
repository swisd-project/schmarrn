/*
 * 1. Relative path: src/lib/api/core.ts
 * 2. Description: THE CORE DOOR — the bridge module for the core context:
 *                 the version verdict and the self-update ride (GitHub hub
 *                 or a parked local packet). Types live with their module
 *                 (the api.ts split, 2026-11-13).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: coreStatus, uploadCorePacket, runCoreUpdate + CoreStatus.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

// ——— the core's self-update (GitHub versions or a parked local packet, admin) ———

export interface CoreStatus {
  readonly current: string;
  readonly latest: string | null;
  readonly dashboard?: string | null;
  readonly updatable?: boolean;
  readonly localVersion?: string | null;
  readonly error?: string;
  /** The release's own name ("v1.3.0 – Beauty") and the day it shipped. */
  readonly releaseName?: string | null;
  readonly published?: string | null;
  /** GitHub's contributor list — the names not on GitHub ride the dashboard. */
  readonly contributors?: readonly { readonly login: string; readonly url: string }[];
}

export function coreStatus(): Promise<CoreStatus> {
  return fetch('/bridge/core')
    .then((r) => json<CoreStatus>(r))
    .then((st) => ({
      ...st,
      // the tag's vanity "v" never crosses the client — the verdict compares
      // bare numbers; a stray "v" here made "Update available" eternal (v1.2.1)
      latest: st.latest ? st.latest.replace(/^v+/, '') : st.latest,
      localVersion: st.localVersion ? st.localVersion.replace(/^v+/, '') : st.localVersion
    }));
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
