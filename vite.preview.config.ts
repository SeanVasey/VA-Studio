import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Development-only visual review. This entry is excluded from the production build.
export default defineConfig({
  plugins: [react()],
  root: 'resources/js/test',
  publicDir: '../../../public',
  server: { port: 5174, fs: { allow: [fileURLToPath(new URL('.', import.meta.url))] } },
});
