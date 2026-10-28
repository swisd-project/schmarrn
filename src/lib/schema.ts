/*
 * 1. Relative path: src/lib/schema.ts
 * 2. Description: Strict Zod schemas for Schmarrn page frontmatter — one per content type.
 * 3. Expects: Raw YAML frontmatter objects as extracted by mdms.
 * 4. Provides: Validated, typed frontmatter. Generic PageSchema is the floor; extend per type.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */
import { z } from 'zod';

export const PageFrontmatterSchema = z.object({
  title: z.string().min(1),
  slug: z.string().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'Lowercase alphanumerics and hyphens only'),
  published: z.boolean().default(true),
  draft: z.boolean().default(false),
  date: z.coerce.date().optional(),
  tags: z.array(z.string().min(1)).default([]),
  /** Serving style: full-screen slide (v1 standard) or classical with menu. Absent = theme default. */
  style: z.enum(['slide', 'classical']).optional()
});

export type PageFrontmatter = z.infer<typeof PageFrontmatterSchema>;

/** One Zod gate per content type — schema.ts is the register. Blog went to plugin-land (v2). */
const SCHEMAS: Record<string, z.ZodType> = {
  pages: PageFrontmatterSchema
};

export function schemaFor(type: string): z.ZodType {
  const schema = SCHEMAS[type];
  return schema ?? PageFrontmatterSchema;
}

/** Human-readable issue list — "title: Required · slug: lowercase only" — never a JSON dump. */
export function formatIssues(error: z.ZodError): string {
  return error.issues.map((i) => `${i.path.length > 0 ? i.path.join('.') : 'frontmatter'}: ${i.message}`).join(' · ');
}

/** validateFrontmatter adapter for mdms: throws a human-readable error, not a Zod dump. */
export function frontmatterValidator(type: string): (data: unknown) => Record<string, unknown> {
  const schema = schemaFor(type);
  return (data: unknown) => {
    const result = schema.safeParse(data);
    if (!result.success) {
      throw new Error(formatIssues(result.error));
    }
    return result.data as Record<string, unknown>;
  };
}

/** Flip (or insert) the `draft:` flag in a frontmatter document — byte-level, no YAML parse. */
export function withDraftFlag(raw: string, on: boolean): string {
  const flag = `draft: ${on}`;
  if (/^draft:\s*(true|false)\s*$/m.test(raw)) {
    return raw.replace(/^draft:\s*(true|false)\s*$/m, flag);
  }
  return raw.replace(/^---\r?\n/, `---\n${flag}\n`);
}
