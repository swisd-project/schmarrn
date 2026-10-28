import { sveltekit } from '@sveltejs/kit/vite';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [sveltekit(), tailwindcss()],
  server: {
    proxy: {
      // Dev nudges /api to the PHP core — one origin in dev, no CORS drama.
      '/api': 'http://localhost:8080',
      // The design workbench lives on the PHP side too.
      '/design': 'http://localhost:8080'
    }
  }
});
