/*
 * 1. Relative path: src/lib/api.ts
 * 2. Description: THE BARREL — the api.ts split (2026-11-13): one bridge
 *                 module per bounded context, types live with their module;
 *                 this file keeps every import path alive (the components
 *                 and the editor import from '$lib/api' unchanged).
 *                 client = the floor (csrf/error/json); pages; site;
 *                 accounts; plugins; wares; core; integrations.
 * 3. Expects: The PHP front controller at /bridge (dev: Vite proxy to :8080).
 * 4. Provides: Everything the context modules export — unchanged surface.
 * 5. SPDX-License-Identifier: MPL-2.0
 * 6. Copyright (c) 2026 Homahuki GmbH
 */

export * from './api/client';
export * from './api/pages';
export * from './api/site';
export * from './api/accounts';
export * from './api/plugins';
export * from './api/wares';
export * from './api/core';
export * from './api/integrations';



