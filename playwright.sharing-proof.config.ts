import { defineConfig } from '@playwright/test';
import base from './playwright.config';

const marker = process.env.VASEY_BROWSER_RELATED_MARKER ?? '';
if (process.env.VASEY_BROWSER_RELATED_STAGE !== '1' || marker.length !== 64 || !/^[a-f0-9]{64}$/.test(marker)) {
  throw new Error('Use node tests/browser/run-related.mjs for the isolated related-track stage.');
}

export default defineConfig({
  ...base,
  testDir: './tests/browser-related',
  testMatch: 'operator-sharing.spec.ts',
  outputDir: 'test-results-related',
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report-related' }]],
});
