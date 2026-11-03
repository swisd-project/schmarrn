import { sveltekit } from '@sveltejs/kit/vite';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [sveltekit(), tailwindcss()],
  server: {
    proxy: {
      // Dev nudges /bridge to the PHP core — one origin in dev, no CORS drama.
      '/bridge': 'http://localhost:8080'
    }
  }
});
