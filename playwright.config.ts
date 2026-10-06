import { defineConfig, devices } from '@playwright/test';

// The wrapper owns a fresh database and credentials. Never reuse a running developer/production server.
if (!process.env.VASEY_BROWSER_DIRECTORY) throw new Error('Use npm run test:browser.');

export default defineConfig({
  testDir: './tests/browser',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: !!process.env.CI,
  // Bound the full suite while allowing the wrapper a minute to retain final evidence.
  globalTimeout: 26 * 60_000,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:8173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium-desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'webkit-mobile', use: { ...devices['iPhone 13'], browserName: 'webkit' } },
  ],
  webServer: {
    // Laravel reads its storage override from superglobals; cli-server omits it under GPCS.
    command: 'php -d variables_order=EGPCS -d upload_max_filesize=9M -d post_max_size=9M -S 127.0.0.1:8173 -t public tests/browser/server.php',
    url: 'http://127.0.0.1:8173/up',
    reuseExistingServer: false,
    timeout: 30_000,
  },
});
