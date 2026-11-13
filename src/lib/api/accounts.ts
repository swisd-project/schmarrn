/*
 * 1. Relative path: src/lib/api/accounts.ts
 * 2. Description: THE ACCOUNTS DOOR — the bridge module for the accounts
 *                 context (admin): the sweep, add, remove, invite. The role
 *                 types ride with the module (the api.ts split, 2026-11-13).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: listAccounts, addAccount, removeAccount, inviteAccount +
 *              AccountRef.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

export interface AccountRef {
  readonly user: string;
  readonly role: 'admin' | 'editor';
  readonly email?: string;
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
export function removeAccount(user: string): Promise<void> {
  return fetch(`/bridge/account/${encodeURIComponent(user)}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<{ ok: boolean }>(r).then(() => undefined));
}
