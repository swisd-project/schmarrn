/*
 * 1. Relative path: src/lib/api/client.ts
 * 2. Description: THE API FLOOR — the one shared client every bridge module
 *                 stands on: the session's CSRF state (login/session/runSetup
 *                 plant it), the status-code-preserving error reader, and the
 *                 json unwrapper. No route of its own (the api.ts split,
 *                 2026-11-13: one module per bounded context, types with
 *                 their module).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: setCsrf, csrfToken, apiError, json.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

let csrf = '';

/** login()/session()/runSetup() plant the session's token here. */
export function setCsrf(token: string): void {
  csrf = token;
}

export function csrfToken(): string {
  return csrf;
}

/** Status-code-preserving error message out of any body shape (JSON {error} or text). */
export async function apiError(res: Response): Promise<Error> {
  const body = (await res.json().catch(() => null)) as { error?: string } | null;
  return new Error(body?.error ?? res.statusText);
}

export async function json<T>(res: Response): Promise<T> {
  if (!res.ok) {
    throw await apiError(res);
  }
  return res.json() as Promise<T>;
}
