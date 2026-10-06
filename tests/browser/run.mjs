import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = resolve(import.meta.dirname, '../..');
if (!existsSync(join(root, 'vendor/autoload.php')) || !existsSync(join(root, 'public/build/manifest.json'))) {
  throw new Error('Install Composer dependencies and run npm run build before browser verification.');
}
if (existsSync(join(root, 'public/hot')) || existsSync(join(root, 'storage/framework/maintenance.php'))) {
  throw new Error('Stop the Vite development server and use a checkout outside maintenance mode.');
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
  // Only retained synthetic test evidence is readable; no HTTP payment initiation/processing is enabled.
  STRIPE_ACCOUNT_ID: 'acct_SYNTHETICONLY', STRIPE_MODE: 'test', STRIPE_TEST_SECRET_KEY: '', STRIPE_WEBHOOK_SECRET: '',
  STRIPE_TEST_CHECKOUT_ENABLED: 'false', STRIPE_TEST_PAYMENT_PROCESSING_ENABLED: 'false', STRIPE_TEST_FINALIZATION_ENABLED: 'false',
  VASEY_BROWSER_EXCEPTION_MARKER: randomBytes(32).toString('hex'),
  // No malware scanner, as in CI. A scanner installed on the host would otherwise run inside synchronous uploads.
  MEDIA_CLAMSCAN: join(directory, 'no-clamscan'),
  VASEY_BROWSER_DIRECTORY: directory, VASEY_BROWSER_PASSWORD: `Browser-${randomBytes(24).toString('hex')}`,
  // The ordinary suite always retains its customer fixtures, even with inherited stage variables.
  VASEY_BROWSER_RELATED_STAGE: '', VASEY_BROWSER_RELATED_MARKER: '',
  // Synthetic inquiry setup belongs exclusively to this disposable loopback installation.
  CONTACT_INQUIRIES_ENABLED: 'true',
  VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED: 'true',
  // Read/issue exact retained synthetic originals; HTTP checkout/payment processing stay disabled above.
  VASEY_TEST_DELIVERY_ACCESS_ENABLED: 'true',
  VASEY_TEST_DELIVERY_ACCESS_POLICY: JSON.stringify({ schema_version: 1, purpose: 'test_owner_delivery', version: 'test-owner-delivery-v1',
    scope: 'activated_order_owner', storage: 'private_local', verification: 'fresh_sha256', token_bytes: 32,
    authorization_ttl_seconds: 60, new_authorizations_per_order60_seconds: 3, stream_attempts: 1,
    ranges: 'disabled', pending_entitlements: 'preserve', buyer_identity: 'unverified_guest' }),
  CONTACT_INQUIRIES_PRIVACY_NOTICE: 'Synthetic browser privacy notice. Inquiries are saved privately for verification.',
  CONTACT_INQUIRIES_RETENTION_REFERENCE: 'SYNTHETIC-BROWSER-ONLY',
  CONTACT_INQUIRIES_OPERATOR_ID: '1',
  VASEY_BROWSER_INQUIRY_MARKER: randomBytes(32).toString('hex'),
};
try {
  for (const child of ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']) {
    mkdirSync(join(directory, child), { recursive: true, mode: 0o700 });
  }
  writeFileSync(env.DB_DATABASE, '', { mode: 0o600, flag: 'wx' });
  const setup = spawnSync('php', ['tests/browser/bootstrap.php'], { cwd: root, env, stdio: 'inherit', timeout: 60000 });
  if (setup.error || setup.status !== 0) throw new Error('Isolated browser fixture setup failed.');
  writeFileSync(join(directory, 'inquiry-fixture-marker.json'), JSON.stringify({
    marker: env.VASEY_BROWSER_INQUIRY_MARKER, database: env.DB_DATABASE,
    origin: env.APP_URL, operatorId: 1,
  }), { mode: 0o600, flag: 'wx' });
  writeFileSync(join(directory, 'exception-inspection-fixture-marker.json'), JSON.stringify({
    purpose: 'retained-exception-native', marker: env.VASEY_BROWSER_EXCEPTION_MARKER,
    database: env.DB_DATABASE, origin: env.APP_URL, baseOperatorId: 1, account: env.STRIPE_ACCOUNT_ID,
  }), { mode: 0o600, flag: 'wx' });
  const result = spawnSync(process.execPath, ['node_modules/@playwright/test/cli.js', 'test', ...process.argv.slice(2)], {
    // Keep one minute beyond Playwright's suite ceiling for teardown and report writes.
    cwd: root, env, stdio: 'inherit', timeout: 1620000,
  });
  if (result.error) throw new Error('Browser verification did not finish.');
  process.exitCode = result.status ?? 1;
} finally {
  rmSync(directory, { recursive: true, force: true });
}
