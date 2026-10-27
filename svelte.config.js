import adapter from '@sveltejs/adapter-static';

/** @type {import('@sveltejs/kit').Config} */
const config = {
  kit: {
    // SPA-style: the dashboard is one client-rendered shell (login state, live preview),
    // prerender the root only. Public content pages are NOT prerendered here — that
    // decision is Recipe A (PHP serves cached HTML) and lives in api/.
    adapter: adapter({
      fallback: 'index.html',
      strict: false
    })
  }
};

export default config;
