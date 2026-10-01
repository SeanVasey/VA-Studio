import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

// Fixed dedicated selection: no caller can redirect the ready fixture into the default full set.
if (process.argv.length !== 2) throw new Error('The isolated related-track runner accepts no selection or configuration arguments.');
const root = resolve(import.meta.dirname, '../..');
if (!existsSync(join(root, 'vendor/autoload.php')) || !existsSync(join(root, 'public/build/manifest.json'))) {
  throw new Error('Install Composer dependencies and run npm run build before related-track browser verification.');
}
if (existsSync(join(root, 'public/hot')) || existsSync(join(root, 'storage/framework/maintenance.php'))) {
  throw new Error('Stop the Vite development server and use a checkout outside maintenance mode.');
}
if (!existsSync('/usr/bin/clamscan') || realpathSync('/usr/bin/clamscan') !== '/usr/bin/clamscan') {
  throw new Error('The related-track stage requires the real supported /usr/bin/clamscan and current signatures.');
}

const directory = mkdtempSync(join(tmpdir(), 'vasey-browser-'));
const env = {
  ...process.env,
  APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8173', ASSET_URL: '',
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  APP_CONFIG_CACHE: join(directory, 'config.php'), APP_ROUTES_CACHE: join(directory, 'routes.php'),
  APP_EVENTS_CACHE: join(directory, 'events.php'), APP_LOCALE: 'en',
  LARAVEL_STORAGE_PATH: directory,
  DB_CONNECTION: 'sqlite', DB_DATABASE: join(directory, 'database.sqlite'), DB_URL: '', DB_FOREIGN_KEYS: 'true',
  SESSION_DRIVER: 'file', SESSION_ENCRYPT: 'true', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: 'null',
  SESSION_COOKIE: `vasey_browser_${randomBytes(8).toString('hex')}`, CACHE_STORE: 'file',
  QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'single', LOG_LEVEL: 'error',
  FILESYSTEM_DISK: 'local', STRIPE_WEBHOOK_ENABLED: 'false',
  STRIPE_TEST_CHECKOUT_ENABLED: 'false', STRIPE_TEST_PAYMENT_PROCESSING_ENABLED: 'false', STRIPE_TEST_FINALIZATION_ENABLED: 'false',
  // HTTP/default bootstrap retain the established no-scanner boundary.
  MEDIA_CLAMSCAN: join(directory, 'no-clamscan'),
  VASEY_BROWSER_DIRECTORY: directory, VASEY_BROWSER_PASSWORD: `Browser-${randomBytes(24).toString('hex')}`,
  CONTACT_INQUIRIES_ENABLED: 'true',
  CONTACT_INQUIRIES_PRIVACY_NOTICE: 'Synthetic browser privacy notice. Inquiries are saved privately for verification.',
  CONTACT_INQUIRIES_RETENTION_REFERENCE: 'SYNTHETIC-BROWSER-ONLY',
  CONTACT_INQUIRIES_OPERATOR_ID: '1',
  VASEY_BROWSER_INQUIRY_MARKER: randomBytes(32).toString('hex'),
  VASEY_BROWSER_RELATED_STAGE: '1', VASEY_BROWSER_RELATED_MARKER: randomBytes(32).toString('hex'),
};

try {
  for (const child of ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']) {
    mkdirSync(join(directory, child), { recursive: true, mode: 0o700 });
  }
  writeFileSync(env.DB_DATABASE, '', { mode: 0o600, flag: 'wx' });
  const setup = spawnSync('php', ['tests/browser/bootstrap.php'], { cwd: root, env, stdio: 'inherit', timeout: 60000 });
  if (setup.error || setup.status !== 0) throw new Error('Isolated related-track bootstrap failed.');
  const fixturesBefore = readFileSync(join(directory, 'fixtures.json'));
  writeFileSync(join(directory, 'related-track-fixture-marker.json'), JSON.stringify({
    marker: env.VASEY_BROWSER_RELATED_MARKER, database: env.DB_DATABASE,
    origin: env.APP_URL, operatorId: 1,
  }), { mode: 0o600, flag: 'wx' });
  const preparation = spawnSync('/usr/bin/timeout', ['--signal=TERM', '--kill-after=15s', '600s', 'php', 'tests/browser/prepare-related-tracks.php', 'prepare'], {
    cwd: root, env: { ...env, MEDIA_CLAMSCAN: '/usr/bin/clamscan' }, stdio: 'inherit', timeout: 620000,
  });
  if (preparation.error || preparation.status !== 0) throw new Error('Genuine related-track preparation failed; browser acceptance was not started.');
  if (!fixturesBefore.equals(readFileSync(join(directory, 'fixtures.json')))) {
    throw new Error('Related-track preparation changed the default fixture contract.');
  }
  const result = spawnSync(process.execPath, ['node_modules/@playwright/test/cli.js', 'test', '--config=playwright.sharing-proof.config.ts'], {
    cwd: root, env, stdio: 'inherit', timeout: 600000,
  });
  if (result.error) throw new Error('Related-track browser verification did not finish.');
  process.exitCode = result.status ?? 1;
} finally {
  rmSync(directory, { recursive: true, force: true });
}
