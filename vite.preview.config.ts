import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const projectRoot = fileURLToPath(new URL('.', import.meta.url));
const previewOutput = resolve(projectRoot, 'dist/design-preview');
const previewAssets = [
  'brand/vasey-audio-logo.png',
  'images/storefront-hero.jpg',
  'images/storefront-hero-mobile.jpg',
  'images/section-banner.jpg',
  'images/memberships.jpg',
  'images/video-studio.jpg',
];

// This isolated entry shares storefront components but cannot run the Laravel app.
// Copy only the approved visual assets: public/index.php and other app files must
// never enter a static preview deployment.
export default defineConfig(({ command }) => ({
  plugins: [react(), {
    name: 'approved-preview-assets',
    apply: 'build',
    closeBundle() {
      for (const asset of previewAssets) {
        const target = resolve(previewOutput, asset);
        mkdirSync(dirname(target), { recursive: true });
        copyFileSync(resolve(projectRoot, 'public', asset), target);
      }
    },
  }],
  root: 'resources/js/test',
  publicDir: command === 'serve' ? '../../../public' : false,
  build: { outDir: previewOutput, emptyOutDir: true, sourcemap: false, manifest: 'manifest.json' },
  server: { port: 5174, strictPort: true, fs: { allow: [projectRoot] } },
}));
