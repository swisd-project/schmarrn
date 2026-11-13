/*
 * 1. Relative path: src/lib/api/integrations.ts
 * 2. Description: THE INTEGRATIONS DOOR — the bridge module for the
 *                 integrations context: the GitHub account (status, connect,
 *                 test, disconnect) and the SMTP oven (status, test, connect,
 *                 disconnect — 2026-11-12 user order). Types live with their
 *                 module (the api.ts split, 2026-11-13).
 * 3. Expects: The PHP /api core at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: githubStatus, githubConnect, githubTest, githubDisconnect,
 *              mailStatus, mailTest, mailConnect, mailDisconnect +
 *              GithubStatus, MailStatus, MailConfig.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

import { csrfToken, json } from './client';

// ——— the GitHub door (Integrations card) ———

export interface GithubStatus {
  readonly connected: boolean;
  readonly login: string | null;
}

/** The account's face — connected? whose? */
export function githubStatus(): Promise<GithubStatus> {
  return fetch('/bridge/github').then((r) => json<GithubStatus>(r));
}

/** Hand the core a Personal Access Token — the account opens. */
export function githubConnect(token: string): Promise<GithubStatus> {
  return fetch('/bridge/github/connect', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ token })
  }).then((r) => json<GithubStatus>(r));
}

/** TEST a token — GitHub's verdict, nothing kept. */
export function githubTest(
  token: string
): Promise<{ ok: boolean; login: string | null; error: string }> {
  return fetch('/bridge/github/test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({ token })
  }).then((r) => json<{ ok: boolean; login: string | null; error: string }>(r));
}

// ——— the SMTP oven (2026-11-12, user order) ———

export interface MailStatus {
  configured: boolean;
  host: string;
  port: number;
  secure: string;
  fromEmail: string;
  fromName: string;
}

export interface MailConfig {
  host: string;
  port: number;
  user: string;
  pass: string;
  secure: string;
  fromEmail: string;
  fromName: string;
}

/** What is plumbed in — the shape, never the password. */
export function mailStatus(): Promise<MailStatus> {
  return fetch('/bridge/mail/status').then((r) => json<MailStatus>(r));
}

/** TEST a candidate oven — the line dials, nothing is kept. */
export function mailTest(cfg: MailConfig): Promise<{ ok: boolean; error: string }> {
  return fetch('/bridge/mail/test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(cfg)
  }).then((r) => json<{ ok: boolean; error: string }>(r));
}

/** Plumb the oven in — the config lands in the git-ignored local file. */
export function mailConnect(cfg: MailConfig): Promise<MailStatus> {
  return fetch('/bridge/mail/connect', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify(cfg)
  }).then((r) => json<MailStatus>(r));
}

/** Let the oven go. */
export function mailDisconnect(): Promise<MailStatus> {
  return fetch('/bridge/mail/disconnect', {
    method: 'POST',
    headers: { 'X-CSRF-Token': csrfToken() }
  }).then((r) => json<MailStatus>(r));
}

/** Let the account go. */
export function githubDisconnect(): Promise<GithubStatus> {
  return fetch('/bridge/github/disconnect', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
    body: JSON.stringify({})
  }).then((r) => json<GithubStatus>(r));
}

