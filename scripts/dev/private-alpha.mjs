import { randomBytes } from 'node:crypto';
import { existsSync, lstatSync, mkdirSync, mkdtempSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createServer } from 'node:net';
import { spawn } from 'node:child_process';

export const origin = 'http://127.0.0.1:8174';
export const root = resolve(import.meta.dirname, '../..');
export const email = 'synthetic-alpha-operator@example.test';

export function validateCheckout(checkout) {
  for (const path of ['vendor/autoload.php', 'public/build/manifest.json']) {
    if (!existsSync(join(checkout, path))) throw new Error('Install Composer dependencies and run npm run build before alpha:local.');
  }
  for (const path of ['public/hot', 'storage/framework/maintenance.php']) {
    if (existsSync(join(checkout, path))) throw new Error('Stop the Vite server and use a checkout outside maintenance mode.');
  }
}

export function createSandbox(parent = tmpdir(), inherited = process.env) {
  const directory = realpathSync(mkdtempSync(join(realpathSync(parent), 'vasey-alpha-')));
  const password = `Alpha-${randomBytes(24).toString('hex')}`;
  const marker = randomBytes(32).toString('hex');
  // Application, provider, PHP configuration and proxy values are never inherited.
  const env = {};
  for (const name of ['PATH', 'SystemRoot', 'WINDIR']) if (inherited[name]) env[name] = inherited[name];
  Object.assign(env, {
    TMPDIR: realpathSync(parent), TMP: realpathSync(parent), TEMP: realpathSync(parent), TZ: 'UTC',
    APP_NAME: 'VASEY.AUDIO PRIVATE ALPHA', APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: origin,
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`, APP_LOCALE: 'en', APP_FALLBACK_LOCALE: 'en', ASSET_URL: '',
    APP_CONFIG_CACHE: join(directory, 'config.php'), APP_ROUTES_CACHE: join(directory, 'routes.php'),
    APP_EVENTS_CACHE: join(directory, 'events.php'), APP_PACKAGES_CACHE: join(directory, 'packages.php'),
    APP_SERVICES_CACHE: join(directory, 'services.php'), LARAVEL_STORAGE_PATH: directory,
    VIEW_COMPILED_PATH: join(directory, 'framework/views'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: join(directory, 'database.sqlite'), DB_URL: '', DB_FOREIGN_KEYS: 'true',
    SESSION_DRIVER: 'file', SESSION_ENCRYPT: 'true', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: 'null',
    SESSION_COOKIE: `vasey_alpha_${randomBytes(8).toString('hex')}`, CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array', LOG_CHANNEL: 'single', LOG_LEVEL: 'error',
    FILESYSTEM_DISK: 'local', BROADCAST_CONNECTION: 'log',
    STRIPE_MODE: 'test', STRIPE_ACCOUNT_ID: '', STRIPE_TEST_SECRET_KEY: '', STRIPE_WEBHOOK_SECRET: '',
    STRIPE_WEBHOOK_ENABLED: 'false', STRIPE_TEST_CHECKOUT_ENABLED: 'false',
    STRIPE_TEST_PAYMENT_PROCESSING_ENABLED: 'false', STRIPE_TEST_FINALIZATION_ENABLED: 'false',
    VASEY_TEST_CONTRACT_ISSUANCE_ENABLED: 'false', VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED: 'false',
    VASEY_TEST_DELIVERY_ACCESS_ENABLED: 'false', CONTACT_INQUIRIES_ENABLED: 'false',
    MEDIA_FFMPEG: join(directory, 'disabled-ffmpeg'), MEDIA_FFPROBE: join(directory, 'disabled-ffprobe'),
    MEDIA_PRLIMIT: join(directory, 'disabled-prlimit'), MEDIA_CLAMSCAN: join(directory, 'disabled-clamscan'),
    MEDIA_TAG_PATH: '', MEDIA_TAG_SHA256: '',
    VASEY_ALPHA_DIRECTORY: directory, VASEY_ALPHA_MARKER: marker, VASEY_ALPHA_PASSWORD: password,
  });
  try {
    for (const child of ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private', 'public']) {
      mkdirSync(join(directory, child), { recursive: true, mode: 0o700 });
    }
    writeFileSync(env.DB_DATABASE, '', { mode: 0o600, flag: 'wx' });
    writeFileSync(join(directory, 'identity.json'), JSON.stringify({ marker, origin, state: 'fresh' }), { mode: 0o600, flag: 'wx' });
    return { directory, password, marker, env };
  } catch (error) {
    rmSync(directory, { recursive: true, force: true });
    throw error;
  }
}

export function removeSandbox(sandbox) {
  // Remove only the entry we created; Node does not recurse through a replaced symlink.
  if (existsSync(sandbox.directory) || (() => { try { return lstatSync(sandbox.directory).isSymbolicLink(); } catch { return false; } })()) {
    rmSync(sandbox.directory, { recursive: true, force: true });
  }
}

export function requireFreePort() {
  return new Promise((resolveFree, reject) => {
    const probe = createServer();
    probe.once('error', () => reject(new Error('Port 8174 is in use. Stop that server before alpha:local; it will not be reused or stopped.')));
    probe.listen({ host: '127.0.0.1', port: 8174, exclusive: true }, () => probe.close(resolveFree));
  });
}

async function stopChild(child) {
  if (!child?.pid || child.exitCode !== null || child.signalCode !== null) return;
  await new Promise(resolveStop => {
    const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
    child.once('exit', () => { clearTimeout(timer); resolveStop(); });
    child.kill('SIGTERM');
  });
}

export async function launch({ checkout = root, output = process.stdout, signal } = {}) {
  validateCheckout(checkout);
  await requireFreePort();
  if (signal?.aborted) return;
  const sandbox = createSandbox();
  let child;
  let interrupted = false;
  let stopping;
  const abort = () => { interrupted = true; stopping = stopChild(child); stopping.catch(() => {}); };
  signal?.addEventListener('abort', abort, { once: true });
  try {
    await new Promise((resolveSetup, reject) => {
      child = spawn('php', ['scripts/dev/private-alpha-bootstrap.php', 'prepare'], { cwd: checkout, env: sandbox.env, stdio: 'ignore' });
      const timer = setTimeout(() => { child.kill('SIGTERM'); reject(new Error('Private alpha preparation timed out.')); }, 60000);
      child.once('error', () => { clearTimeout(timer); reject(new Error('PHP could not start. Install PHP 8.4+ and its SQLite extensions.')); });
      child.once('exit', code => { clearTimeout(timer); code === 0 ? resolveSetup() : reject(new Error('Private alpha preparation failed; no server was started.')); });
    });
    if (interrupted) return;
    child = spawn('php', ['-d', 'upload_max_filesize=9M', '-d', 'post_max_size=9M', '-S', '127.0.0.1:8174', '-t', 'public', 'scripts/dev/private-alpha-bootstrap.php'], {
      cwd: checkout, env: sandbox.env, stdio: 'ignore',
    });
    let spawnFailed = false;
    child.once('error', () => { spawnFailed = true; });
    let ready = false;
    const deadline = Date.now() + 15000;
    while (!interrupted && !spawnFailed && child.exitCode === null && child.signalCode === null && Date.now() < deadline) {
      try {
        const response = await fetch(`${origin}/admin/login`, { redirect: 'manual', signal: AbortSignal.timeout(1000) });
        ready = response.status === 200 && response.headers.get('X-Vasey-Private-Alpha') === sandbox.marker;
        await response.body?.cancel();
        if (ready) break;
      } catch { /* The owned server may still be starting. */ }
      await new Promise(resolveDelay => setTimeout(resolveDelay, 100));
    }
    if (interrupted) return;
    if (!ready) throw new Error('Private alpha HTTP startup failed; no credentials were displayed.');
    output.write(`\nPRIVATE ALPHA — disposable synthetic data only\nStorefront: ${origin}\nAdmin: ${origin}/admin\nEmail: ${email}\nTemporary password: ${sandbox.password}\n\nEdit the synthetic drafts and try site-content changes. The catalog starts empty.\nPayments, email, media processing and background workers are disabled.\nCtrl+C stops this server and deletes its data. See docs/private-alpha.md.\n`);
    await new Promise(resolveExit => {
      if (child.exitCode !== null || child.signalCode !== null) resolveExit();
      else child.once('exit', resolveExit);
    });
    if (!interrupted) throw new Error('Private alpha server stopped unexpectedly.');
  } finally {
    signal?.removeEventListener('abort', abort);
    await stopping;
    await stopChild(child);
    removeSandbox(sandbox);
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  if (process.argv.length !== 2) {
    console.error('Usage: node scripts/dev/private-alpha.mjs (no host, database or credential overrides)');
    process.exitCode = 1;
  } else {
    const controller = new AbortController();
    const stop = () => controller.abort();
    process.once('SIGINT', stop);
    process.once('SIGTERM', stop);
    try { await launch({ signal: controller.signal }); }
    catch (error) { if (!controller.signal.aborted) { console.error(error.message); process.exitCode = 1; } }
    finally { process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop); }
  }
}
