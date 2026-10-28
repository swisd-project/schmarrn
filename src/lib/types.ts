/*
 * 1. Relative path: src/lib/types.ts
 * 2. Description: Shared dashboard types — page refs, editor state.
 * 3. Expects: API payloads as produced by api/lib/api.ts.
 * 4. Provides: Strict types; no any, not today, not for you.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export interface PageRef {
  readonly slug: string;
  readonly updatedAt: number;
  readonly draft?: boolean;
}
export type PageIndex = Readonly<Record<string, readonly PageRef[]>>;

export interface SelectedPage {
  readonly type: string;
  readonly slug: string;
}

export interface AccountRef {
  readonly user: string;
  readonly role: 'admin' | 'editor';
  readonly email?: string;
}
