import type { Handle } from '@sveltejs/kit';

export const handle: Handle = async ({ event, resolve }) => {
  if (event.url.pathname.startsWith('/api')) {
    // In dev the Vite proxy ferries these to PHP; in prod they're real files.
    // Never render a dashboard route for them.
    return resolve(event);
  }
  return resolve(event);
};
