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
  tags: z.array(z.string().min(1)).default([])
});

export type PageFrontmatter = z.infer<typeof PageFrontmatterSchema>;

/** Blog entries: teaser split marker is meaningful here. */
export const BlogFrontmatterSchema = PageFrontmatterSchema.extend({
  author: z.string().min(1),
  abstract: z.string().min(1)
});

export type BlogFrontmatter = z.infer<typeof BlogFrontmatterSchema>;

/** One Zod gate per content type — schema.ts is the register. */
const SCHEMAS: Record<string, z.ZodType> = {
  blog: BlogFrontmatterSchema,
  pages: PageFrontmatterSchema
};

export function schemaFor(type: string): z.ZodType {
  const schema = SCHEMAS[type];
  return schema ?? PageFrontmatterSchema;
}
