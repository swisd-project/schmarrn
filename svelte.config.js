import adapter from '@sveltejs/adapter-static';

/** @type {import('@sveltejs/kit').Config} */
const config = {
  kit: {
    // SPA-style: the dashboard is one client-rendered shell (login state, live preview),
    // prerender the root only. Public content pages are NOT prerendered here — that
    // decision is Recipe A (PHP serves cached HTML) and lives in api/.
    adapter: adapter({
      fallback: 'dashboard.html',
      strict: false
    }),
    // The dashboard lives at /_ (the public site never wears a login face).
    // Local dev and FTP hosts build with base /_; the Pages deploy overrides
    // via BASE_PATH (e.g. /schmarrn).
    paths: { base: process.env.BASE_PATH || '/_' }
  }
};

export default config;
