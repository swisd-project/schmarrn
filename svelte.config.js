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
    // GitHub Pages hosts the dashboard under /schmarrn/ (deploy workflow sets BASE_PATH).
    // Local dev and the FTP host use no base path.
    paths: { base: process.env.BASE_PATH || '' }
  }
};

export default config;
