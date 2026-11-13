/*
 * 1. Relative path: src/lib/types.ts
 * 2. Description: Shared dashboard types — page refs and account refs.
 * 3. Expects: API payloads as produced by bridge/lib/api.ts.
 * 4. Provides: Strict types; no any, not today, not for you.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export interface PageRef {
  readonly slug: string;
  readonly updatedAt: number;
  readonly draft?: boolean;
  readonly hasDraft?: boolean;
  readonly home?: boolean;
  readonly tags?: readonly string[];
}
export type PageIndex = Readonly<Record<string, readonly PageRef[]>>;

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

export interface AccountRef {
  readonly user: string;
  readonly role: 'admin' | 'editor';
  readonly email?: string;
}
