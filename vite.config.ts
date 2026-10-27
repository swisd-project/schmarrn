import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [sveltekit()],
  server: {
    proxy: {
      // Dev nudges /api to the PHP core — one origin in dev, no CORS drama.
      '/api': 'http://localhost:8080'
    }
  }
});
