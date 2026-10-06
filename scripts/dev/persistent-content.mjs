import { createHash, randomBytes } from 'node:crypto';
import { existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve, sep } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createServer } from 'node:net';
import { spawn } from 'node:child_process';

export const root = resolve(import.meta.dirname, '../..');
export const origin = 'http://127.0.0.1:8175';
const refusal = 'Persistent workspace safety checks failed; retained files were not reset or removed.';
const requiredDirectories = ['app', 'app/private', 'framework', 'framework/views', 'framework/sessions', 'framework/cache', 'framework/cache/data', 'logs', 'public', 'tmp'];

function requireSafe(condition) {
  if (!condition) throw new Error(refusal);
}

// A supplied durable path is never normalized into a different path or followed through a link.
export function canonicalPath(path, { missingLeaf = false } = {}) {
  requireSafe(typeof path === 'string' && path.startsWith(sep) && resolve(path) === path && path !== sep && !/[\x00-\x1f\x7f]/.test(path));
  let cursor = '';
  const parts = path.slice(1).split(sep);
  for (let index = 0; index < parts.length; index++) {
    requireSafe(parts[index] !== '' && parts[index] !== '.' && parts[index] !== '..');
    cursor += `${sep}${parts[index]}`;
    try { requireSafe(!lstatSync(cursor).isSymbolicLink()); }
    catch (error) {
      if (error.code === 'ENOENT' && missingLeaf && index === parts.length - 1) return path;
      throw new Error(refusal);
    }
  }
  requireSafe(realpathSync(path) === path);
  return path;
}

function owned(stat) {
  return typeof process.getuid === 'function' && String(stat.uid) === String(process.getuid());
}

export function safeAncestors(directory) {
  canonicalPath(directory);
  let cursor = sep;
  for (const part of ['', ...directory.slice(1).split(sep)]) {
    if (part !== '') cursor = join(cursor, part);
    const entry = lstatSync(cursor);
    requireSafe(entry.isDirectory() && ((entry.mode & 0o022) === 0 || (entry.uid === 0 && (entry.mode & 0o1000) !== 0)));
  }
  const parent = statSync(directory);
  requireSafe(owned(parent) && (parent.mode & 0o022) === 0);
}

function privateFile(path) {
  canonicalPath(path);
  const stat = lstatSync(path);
  requireSafe(stat.isFile() && stat.nlink === 1 && owned(stat) && (stat.mode & 0o7777) === 0o600 && stat.size <= 16384);
  return stat;
}

export function schemaHash(checkout = root) {
  const hash = createHash('sha256');
  const directory = join(checkout, 'database/migrations');
  for (const name of readdirSync(directory).filter(name => name.endsWith('.php')).sort()) {
    hash.update(name).update('\0').update(readFileSync(join(directory, name))).update('\0');
  }
  hash.update(readFileSync(join(checkout, 'composer.lock')));
  hash.update(readFileSync(join(checkout, 'public/build/manifest.json')));
  return hash.digest('hex');
}

export function validateCheckout(checkout = root) {
  for (const path of ['vendor/autoload.php', 'public/build/manifest.json']) {
    if (!existsSync(join(checkout, path))) throw new Error('Install locked Composer dependencies and build the storefront before using persistent-content.');
  }
  requireSafe(!existsSync(join(checkout, 'public/hot')) && !existsSync(join(checkout, 'storage/framework/maintenance.php')));
  canonicalPath(join(checkout, 'public/build'));
  canonicalPath(join(checkout, 'public/build/manifest.json'));
  requireSafe(lstatSync(join(checkout, 'public/build')).isDirectory() && lstatSync(join(checkout, 'public/build/manifest.json')).isFile());
}

function directoryGuard(directory, checkout) {
  canonicalPath(directory);
  safeAncestors(dirname(directory));
  const actualCheckout = realpathSync(checkout);
  requireSafe(directory !== actualCheckout && !directory.startsWith(`${actualCheckout}${sep}`) && !actualCheckout.startsWith(`${directory}${sep}`));
  const stat = statSync(directory, { bigint: true });
  requireSafe(stat.isDirectory() && owned(stat) && (stat.mode & 0o7777n) === 0o700n);
  for (const child of requiredDirectories) {
    canonicalPath(join(directory, child));
    const entry = lstatSync(join(directory, child));
    requireSafe(entry.isDirectory() && owned(entry) && (entry.mode & 0o7777) === 0o700);
  }
  const pending = [directory];
  let inspected = 0;
  while (pending.length > 0) {
    const parent = pending.pop();
    for (const name of readdirSync(parent)) {
      requireSafe(++inspected <= 100000);
      const child = join(parent, name);
      const entry = lstatSync(child);
      requireSafe(owned(entry) && !entry.isSymbolicLink());
      if (entry.isDirectory()) {
        requireSafe((entry.mode & 0o7777) === 0o700);
        pending.push(child);
      } else {
        // Laravel's compiled views use owner-executable mode; no group/world/special bits are permitted.
        requireSafe(entry.isFile() && entry.nlink === 1 && (entry.mode & 0o7077) === 0 && (entry.mode & 0o400) !== 0);
      }
    }
  }
  requireSafe(!readdirSync(directory).some(name => name.startsWith('.env')));
  for (const name of ['config.php', 'routes.php', 'events.php', 'disabled-ffmpeg', 'disabled-ffprobe', 'disabled-prlimit', 'disabled-clamscan']) {
    requireSafe(!existsSync(join(directory, name)) && !(() => { try { return lstatSync(join(directory, name)).isSymbolicLink(); } catch { return false; } })());
  }
  return { dev: String(stat.dev), ino: String(stat.ino) };
}

export function createWorkspace(directory, checkout = root) {
  validateCheckout(checkout);
  canonicalPath(directory, { missingLeaf: true });
  safeAncestors(dirname(directory));
  requireSafe(!existsSync(directory));
  const parent = statSync(dirname(directory));
  requireSafe(parent.isDirectory() && owned(parent) && (parent.mode & 0o022) === 0);
  const actualCheckout = realpathSync(checkout);
  requireSafe(!directory.startsWith(`${actualCheckout}${sep}`) && !actualCheckout.startsWith(`${directory}${sep}`) && directory !== actualCheckout);
  // Never recursively delete this directory, including when preparation fails.
  mkdirSync(directory, { mode: 0o700 });
  for (const child of requiredDirectories) mkdirSync(join(directory, child), { recursive: true, mode: 0o700 });
  for (const file of ['database.sqlite', 'lease']) writeFileSync(join(directory, file), '', { mode: 0o600, flag: 'wx' });
  const identity = {
    schema_version: 1, installation_id: randomBytes(32).toString('hex'), origin, directory,
    directory_identity: directoryGuard(directory, checkout), checkout: actualCheckout, schema_hash: schemaHash(checkout),
    app_key: `base64:${randomBytes(32).toString('base64')}`, session_cookie: `vasey_content_${randomBytes(8).toString('hex')}`, state: 'initializing',
  };
  writeFileSync(join(directory, 'identity.json'), JSON.stringify(identity), { mode: 0o600, flag: 'wx' });
  return { directory, identity, checkout };
}

export function readWorkspace(directory, checkout = root, { allowInitializing = false } = {}) {
  validateCheckout(checkout);
  const directoryIdentity = directoryGuard(directory, checkout);
  privateFile(join(directory, 'identity.json'));
  privateFile(join(directory, 'lease'));
  const db = lstatSync(join(directory, 'database.sqlite'));
  canonicalPath(join(directory, 'database.sqlite'));
  requireSafe(db.isFile() && db.nlink === 1 && owned(db) && (db.mode & 0o7777) === 0o600);
  let identity;
  try { identity = JSON.parse(readFileSync(join(directory, 'identity.json'), 'utf8')); }
  catch { throw new Error(refusal); }
  requireSafe(Object.keys(identity).sort().join(',') === 'app_key,checkout,directory,directory_identity,installation_id,origin,schema_hash,schema_version,session_cookie,state');
  requireSafe(identity.schema_version === 1 && identity.origin === origin && identity.directory === directory
    && identity.checkout === realpathSync(checkout) && identity.schema_hash === schemaHash(checkout)
    && identity.directory_identity?.dev === directoryIdentity.dev && identity.directory_identity?.ino === directoryIdentity.ino
    && Object.keys(identity.directory_identity).sort().join(',') === 'dev,ino'
    && /^[a-f0-9]{64}$/.test(identity.installation_id) && /^base64:[A-Za-z0-9+/]{43}=$/.test(identity.app_key)
    && /^vasey_content_[a-f0-9]{16}$/.test(identity.session_cookie)
    && (identity.state === 'ready' || (allowInitializing && identity.state === 'initializing')));
  return { directory, identity, checkout };
}

export function isolatedEnvironment(workspace, inherited = process.env) {
  const { directory, identity } = workspace;
  const env = {};
  for (const name of ['PATH', 'SystemRoot', 'WINDIR']) if (inherited[name]) env[name] = inherited[name];
  Object.assign(env, {
    TMPDIR: join(directory, 'tmp'), TMP: join(directory, 'tmp'), TEMP: join(directory, 'tmp'), TZ: 'UTC',
    APP_NAME: 'VASEY.AUDIO PRIVATE CONTENT', APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: origin,
    APP_KEY: identity.app_key, APP_PREVIOUS_KEYS: '', APP_LOCALE: 'en', APP_FALLBACK_LOCALE: 'en', ASSET_URL: '',
    APP_CONFIG_CACHE: join(directory, 'config.php'), APP_ROUTES_CACHE: join(directory, 'routes.php'),
    APP_EVENTS_CACHE: join(directory, 'events.php'), APP_PACKAGES_CACHE: join(directory, 'packages.php'),
    APP_SERVICES_CACHE: join(directory, 'services.php'), LARAVEL_STORAGE_PATH: directory,
    VIEW_COMPILED_PATH: join(directory, 'framework/views'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: join(directory, 'database.sqlite'), DB_URL: '', DB_FOREIGN_KEYS: 'true',
    SESSION_DRIVER: 'file', SESSION_ENCRYPT: 'true', SESSION_SECURE_COOKIE: 'false', SESSION_HTTP_ONLY: 'true',
    SESSION_SAME_SITE: 'strict', SESSION_DOMAIN: 'null', SESSION_COOKIE: identity.session_cookie,
    CACHE_STORE: 'file', QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array', LOG_CHANNEL: 'single', LOG_LEVEL: 'error',
    FILESYSTEM_DISK: 'local', BROADCAST_CONNECTION: 'log',
    STRIPE_MODE: 'test', STRIPE_ACCOUNT_ID: '', STRIPE_TEST_SECRET_KEY: '', STRIPE_WEBHOOK_SECRET: '',
    STRIPE_WEBHOOK_ENABLED: 'false', STRIPE_TEST_CHECKOUT_ENABLED: 'false', STRIPE_TEST_PAYMENT_PROCESSING_ENABLED: 'false',
    STRIPE_TEST_FINALIZATION_ENABLED: 'false', VASEY_TEST_CONTRACT_ISSUANCE_ENABLED: 'false',
    VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED: 'false', VASEY_TEST_DELIVERY_ACCESS_ENABLED: 'false',
    VASEY_TEST_PURCHASE_CLAIMS_ENABLED: 'false', VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED: 'false',
    VASEY_TEST_CUSTOMER_IDENTITY_ENABLED: 'false', CONTACT_INQUIRIES_ENABLED: 'false', CONTACT_TEST_ORDER_INQUIRIES_ENABLED: 'false',
    MEDIA_FFMPEG: join(directory, 'disabled-ffmpeg'), MEDIA_FFPROBE: join(directory, 'disabled-ffprobe'),
    MEDIA_PRLIMIT: join(directory, 'disabled-prlimit'), MEDIA_CLAMSCAN: join(directory, 'disabled-clamscan'),
    MEDIA_TAG_PATH: '', MEDIA_TAG_SHA256: '', VASEY_CONTENT_DIRECTORY: directory,
    VASEY_CONTENT_ID: identity.installation_id, VASEY_CONTENT_CHECKOUT: identity.checkout,
    VASEY_CONTENT_SCHEMA_HASH: identity.schema_hash, VASEY_CONTENT_LEASE: randomBytes(32).toString('hex'),
  });
  return env;
}

async function stopChild(child) {
  if (!child?.pid || child.exitCode !== null || child.signalCode !== null) return;
  await new Promise(resolveStop => {
    const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
    child.once('exit', () => { clearTimeout(timer); resolveStop(); });
    child.kill('SIGTERM');
  });
}

export async function acquireLease(workspace, env) {
  const child = spawn('php', ['scripts/dev/persistent-content-bootstrap.php', 'lease'], { cwd: workspace.checkout, env, stdio: ['pipe', 'pipe', 'ignore'] });
  try {
    await new Promise((resolveLease, reject) => {
      let received = '';
      const timer = setTimeout(() => reject(new Error('Persistent workspace lease could not be acquired.')), 10000);
      const finish = error => { clearTimeout(timer); error ? reject(error) : resolveLease(); };
      child.once('error', () => finish(new Error('PHP could not start. Install PHP 8.4+ and its SQLite extensions.')));
      child.once('exit', () => finish(new Error('Persistent workspace is already in use or failed its safety checks.')));
      child.stdout.on('data', data => { received += data; if (received === 'LEASED\n') finish(); });
    });
    return { child, async release() { child.stdin.end(); await stopChild(child); } };
  } catch (error) { await stopChild(child); throw error; }
}

async function runPHP(workspace, env, command, { interactive = false, signal } = {}) {
  const child = spawn('php', ['scripts/dev/persistent-content-bootstrap.php', command], {
    cwd: workspace.checkout, env, stdio: interactive ? 'inherit' : 'ignore',
  });
  const abort = () => { stopChild(child).catch(() => {}); };
  signal?.addEventListener('abort', abort, { once: true });
  const timer = interactive ? undefined : setTimeout(abort, 60000);
  try {
    const code = await new Promise((resolveExit, reject) => {
      child.once('error', () => reject(new Error('PHP could not start. Install PHP 8.4+ and its SQLite extensions.')));
      child.once('exit', resolveExit);
      if (signal?.aborted) abort();
    });
    if (code !== 0 || signal?.aborted) throw new Error('Persistent workspace operation failed; retained data was not reset or removed.');
  } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); await stopChild(child); }
}

export function requireFreePort() {
  return new Promise((resolveFree, reject) => {
    const probe = createServer();
    probe.once('error', () => reject(new Error('Port 8175 is in use. The existing listener will not be reused or stopped.')));
    probe.listen({ host: '127.0.0.1', port: 8175, exclusive: true }, () => probe.close(resolveFree));
  });
}

export async function initialize(directory, { checkout = root, output = process.stdout, signal } = {}) {
  const workspace = createWorkspace(directory, checkout);
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    await runPHP(workspace, env, 'initialize', { signal });
    readWorkspace(directory, checkout);
    output.write('Persistent private workspace initialized with an empty catalog. Create your operator with the operator command. Files remain when stopped.\n');
  } finally { await lease.release(); }
}

export async function provisionOperator(directory, { checkout = root, signal } = {}) {
  const workspace = readWorkspace(directory, checkout);
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  try { await runPHP(workspace, env, 'operator', { interactive: true, signal }); }
  finally { await lease.release(); }
}

export async function launch(directory, { checkout = root, output = process.stdout, signal } = {}) {
  const workspace = readWorkspace(directory, checkout);
  await requireFreePort();
  if (signal?.aborted) return;
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  let child;
  let interrupted = false;
  const abort = () => { interrupted = true; stopChild(child).catch(() => {}); };
  signal?.addEventListener('abort', abort, { once: true });
  try {
    await runPHP(workspace, env, 'verify', { signal });
    if (interrupted) return;
    child = spawn('php', ['-d', 'upload_max_filesize=9M', '-d', 'post_max_size=9M', '-S', '127.0.0.1:8175', '-t', 'public', 'scripts/dev/persistent-content-bootstrap.php'], {
      cwd: checkout, env, stdio: 'ignore',
    });
    let failed = false;
    child.once('error', () => { failed = true; });
    lease.child.once('exit', abort);
    let ready = false;
    const deadline = Date.now() + 15000;
    while (!interrupted && !failed && child.exitCode === null && child.signalCode === null && Date.now() < deadline) {
      try {
        const response = await fetch(`${origin}/admin/login`, { redirect: 'manual', signal: AbortSignal.timeout(1000) });
        ready = response.status === 200 && response.headers.get('X-Vasey-Private-Content') === workspace.identity.installation_id;
        await response.body?.cancel();
        if (ready) break;
      } catch { /* Await only this newly spawned loopback server. */ }
      await new Promise(resolveDelay => setTimeout(resolveDelay, 100));
    }
    if (interrupted) return;
    if (!ready) throw new Error('Persistent private HTTP startup failed. Retained data was not reset or removed.');
    output.write(`\nPRIVATE CONTENT — durable local authoring\nStorefront: ${origin}\nAdmin: ${origin}/admin\nSign in using your separately provisioned operator.\nPayments, customer enrollment, mail, media processing, workers and scheduler are disabled.\nKeep originals and a backup of this workspace, including its key. Ctrl+C stops the server and retains all authored data.\nSee docs/verification/persistent-content-onboarding.md.\n`);
    await new Promise(resolveExit => {
      if (child.exitCode !== null || child.signalCode !== null) resolveExit();
      else child.once('exit', resolveExit);
    });
    if (!interrupted) throw new Error('Persistent private server stopped unexpectedly. Retained data was not reset or removed.');
  } finally {
    signal?.removeEventListener('abort', abort);
    lease.child.removeListener('exit', abort);
    await stopChild(child);
    await lease.release();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const args = process.argv.slice(2);
  if (args.length !== 3 || !['init', 'start', 'operator'].includes(args[0]) || args[1] !== '--directory') {
    console.error('Usage: node scripts/dev/persistent-content.mjs init|start|operator --directory /absolute/private/directory');
    process.exitCode = 1;
  } else {
    const controller = new AbortController();
    const stop = () => controller.abort();
    process.once('SIGINT', stop); process.once('SIGTERM', stop);
    try {
      const operation = { init: initialize, start: launch, operator: provisionOperator }[args[0]];
      await operation(args[2], { signal: controller.signal });
    } catch (error) { console.error(error.code ? refusal : error.message); process.exitCode = 1; }
    finally { process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop); }
  }
}
