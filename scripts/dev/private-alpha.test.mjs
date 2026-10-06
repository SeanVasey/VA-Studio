import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, readlinkSync, realpathSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { request } from 'node:http';
import { spawn, spawnSync } from 'node:child_process';
import { createSandbox, launch, origin, removeSandbox, requireFreePort, root, validateCheckout } from './private-alpha.mjs';

function temporary(fn) {
  const directory = mkdtempSync(join(realpathSync(tmpdir()), 'alpha-safety-'));
  return Promise.resolve().then(() => fn(directory)).finally(() => rmSync(directory, { recursive: true, force: true }));
}

function snapshot(path) {
  if (!existsSync(path)) return null;
  if (lstatSync(path).isSymbolicLink()) return { link: readlinkSync(path) };
  if (statSync(path).isDirectory()) return Object.fromEntries(readdirSync(path).sort().map(name => [name, snapshot(join(path, name))]));
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function attribute(value) {
  const named = { quot: '"', apos: "'", amp: '&', lt: '<', gt: '>' };
  return value.replace(/&(?:#(\d+)|#x([\da-f]+)|(quot|apos|amp|lt|gt));/gi,
    (_match, decimal, hex, name) => name ? named[name.toLowerCase()] : String.fromCodePoint(Number.parseInt(decimal ?? hex, decimal ? 10 : 16)));
}

async function verifyOperatorEdit(password) {
  const cookies = new Map();
  async function visit(path, options = {}) {
    const response = await fetch(new URL(path, origin), { ...options, redirect: 'manual', headers: {
      Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; '), ...options.headers,
    } });
    for (const raw of response.headers.getSetCookie()) {
      const first = raw.split(';')[0]; const at = first.indexOf('='); cookies.set(first.slice(0, at), first.slice(at + 1));
    }
    return response;
  }
  function component(html, name) {
    const snapshots = [...html.matchAll(/wire:snapshot="([^\"]+)"/g)].map(match => attribute(match[1]));
    const found = snapshots.find(raw => JSON.parse(raw).memo.name.includes(name)); assert.ok(found, name); return found;
  }
  async function call(html, retained, updates, method, params) {
    const token = html.match(/<meta name="csrf-token" content="([^\"]+)"/);
    const endpoint = html.match(/data-update-uri="([^\"]+)"/); assert.ok(token); assert.ok(endpoint);
    const response = await visit(attribute(endpoint[1]), { method: 'POST', headers: {
      'Content-Type': 'application/json', Accept: 'application/json', 'X-Livewire': '',
    }, body: JSON.stringify({ _token: attribute(token[1]), components: [{ snapshot: retained, updates, calls: [{ path: '', method, params }] }] }) });
    assert.equal(response.status, 200); const result = await response.json();
    assert.deepEqual(JSON.parse(result.components[0].snapshot).memo.errors, []);
    return result.components[0];
  }
  const login = await visit('/admin/login'); assert.equal(login.status, 200); const loginHtml = await login.text();
  const authenticated = await call(loginHtml, component(loginHtml, 'Login'), {
    'data.email': 'synthetic-alpha-operator@example.test', 'data.password': password,
  }, 'authenticate', []);
  assert.equal(authenticated.effects.redirect, `${origin}/admin`);
  const tracks = await visit('/admin/tracks'); assert.equal(tracks.status, 200); const tracksHtml = await tracks.text();
  const mounted = await call(tracksHtml, component(tracksHtml, 'ManageTracks'), {}, 'mountAction', ['edit', {}, { recordKey: '1', table: true }]);
  const title = 'SYNTHETIC ALPHA — HTTP verified edit';
  await call(tracksHtml, mounted.snapshot, { 'mountedActions.0.data.title': title }, 'callMountedAction', []);
  const fresh = await visit('/admin/tracks'); assert.equal(fresh.status, 200); assert.ok((await fresh.text()).includes(title));
}

test('sandbox ignores inherited production connections, policies and PHP injection values', () => temporary(parent => {
  const inherited = { PATH: process.env.PATH, APP_ENV: 'production', APP_KEY: 'SENTINEL', DB_URL: 'mysql://SENTINEL',
    AWS_SECRET_ACCESS_KEY: 'SENTINEL', STRIPE_TEST_SECRET_KEY: 'SENTINEL', STRIPE_TEST_CHECKOUT_ENABLED: 'true',
    VASEY_TEST_ORDER_POLICY: 'SENTINEL', SESSION_CONNECTION: 'mysql', DB_QUEUE_CONNECTION: 'mysql',
    PHP_CLI_SERVER_WORKERS: '8', PHPRC: '/SENTINEL', PHP_INI_SCAN_DIR: '/SENTINEL', HTTP_PROXY: 'SENTINEL' };
  const first = createSandbox(parent, inherited);
  const second = createSandbox(parent, inherited);
  try {
    assert.equal(first.env.APP_ENV, 'local');
    assert.equal(first.env.DB_URL, '');
    assert.equal(first.env.STRIPE_TEST_SECRET_KEY, '');
    assert.equal(first.env.STRIPE_TEST_CHECKOUT_ENABLED, 'false');
    for (const key of ['AWS_SECRET_ACCESS_KEY', 'VASEY_TEST_ORDER_POLICY', 'SESSION_CONNECTION', 'DB_QUEUE_CONNECTION', 'PHP_CLI_SERVER_WORKERS', 'PHPRC', 'PHP_INI_SCAN_DIR', 'HTTP_PROXY']) assert.equal(first.env[key], undefined);
    assert.equal(first.env.LARAVEL_STORAGE_PATH, first.directory);
    for (const key of ['CONFIG', 'ROUTES', 'EVENTS', 'PACKAGES', 'SERVICES']) assert.ok(first.env[`APP_${key}_CACHE`].startsWith(`${first.directory}/`));
    assert.equal(statSync(first.directory).mode & 0o777, 0o700);
    assert.equal(statSync(first.env.DB_DATABASE).mode & 0o777, 0o600);
    assert.equal(statSync(first.env.DB_DATABASE).size, 0);
    assert.notEqual(first.password, second.password);
    assert.notEqual(first.env.APP_KEY, second.env.APP_KEY);
    assert.notEqual(first.env.SESSION_COOKIE, second.env.SESSION_COOKIE);
  } finally { removeSandbox(first); removeSandbox(second); }
}));

test('canonical temporary parent works through a macOS-style symlink', () => temporary(parent => {
  const target = join(parent, 'real'); mkdirSync(target);
  const alias = join(parent, 'alias'); symlinkSync(target, alias, 'dir');
  const sandbox = createSandbox(alias);
  assert.equal(sandbox.directory, realpathSync(sandbox.directory));
  assert.ok(sandbox.directory.startsWith(`${target}/`));
  removeSandbox(sandbox);
}));

test('cleanup never follows a substituted directory symlink', () => temporary(parent => {
  const sandbox = createSandbox(parent);
  const outside = join(parent, 'keep'); mkdirSync(outside); writeFileSync(join(outside, 'sentinel'), 'keep');
  rmSync(sandbox.directory, { recursive: true }); symlinkSync(outside, sandbox.directory, 'dir');
  removeSandbox(sandbox);
  assert.equal(readFileSync(join(outside, 'sentinel'), 'utf8'), 'keep');
}));

test('missing build, active Vite and maintenance are refused without changing checkout', () => temporary(checkout => {
  assert.throws(() => validateCheckout(checkout), /Install Composer/);
  mkdirSync(join(checkout, 'vendor'), { recursive: true }); writeFileSync(join(checkout, 'vendor/autoload.php'), '');
  mkdirSync(join(checkout, 'public/build'), { recursive: true }); writeFileSync(join(checkout, 'public/build/manifest.json'), '{}');
  writeFileSync(join(checkout, 'public/hot'), 'SENTINEL');
  assert.throws(() => validateCheckout(checkout), /Stop the Vite/);
  assert.equal(readFileSync(join(checkout, 'public/hot'), 'utf8'), 'SENTINEL');
  rmSync(join(checkout, 'public/hot'));
  mkdirSync(join(checkout, 'storage/framework'), { recursive: true }); writeFileSync(join(checkout, 'storage/framework/maintenance.php'), 'SENTINEL');
  assert.throws(() => validateCheckout(checkout), /maintenance/);
}));

test('occupied port is refused without stopping or reusing the listener', async () => {
  const listener = createServer(socket => socket.end('existing'));
  await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(8174, '127.0.0.1', resolve); });
  try { await assert.rejects(requireFreePort(), /in use/); assert.equal(listener.listening, true); }
  finally { await new Promise(resolve => listener.close(resolve)); }
});

async function fakeRuntime(parent, mode, fn) {
  const checkout = join(parent, 'checkout');
  for (const child of ['vendor', 'public/build', 'scripts/dev']) mkdirSync(join(checkout, child), { recursive: true });
  writeFileSync(join(checkout, 'vendor/autoload.php'), ''); writeFileSync(join(checkout, 'public/build/manifest.json'), '{}');
  const bin = join(parent, 'bin'); mkdirSync(bin);
  writeFileSync(join(bin, 'php'), `#!${process.execPath}\nconst http = require('node:http');\n${mode === 'ignore-term' ? "process.on('SIGTERM',()=>{});" : ''}\nif (process.argv.includes('prepare')) { ${mode === 'setup-wait' ? 'setInterval(()=>{},1000);' : 'process.exit(0);'} } else { ${mode === 'server-fail' ? 'process.exit(1);' : `http.createServer((req,res)=>{ res.setHeader('X-Vasey-Private-Alpha', ${mode === 'wrong-marker' ? "'wrong'" : 'process.env.VASEY_ALPHA_MARKER'}); res.end('synthetic'); }).listen(8174,'127.0.0.1');`} }\n`, { mode: 0o700 });
  const oldPath = process.env.PATH; const oldTmp = process.env.TMPDIR;
  process.env.PATH = bin; process.env.TMPDIR = parent;
  try { await fn(checkout); }
  finally { process.env.PATH = oldPath; if (oldTmp === undefined) delete process.env.TMPDIR; else process.env.TMPDIR = oldTmp; }
  assert.equal(readdirSync(parent).some(name => name.startsWith('vasey-alpha-')), false);
  await requireFreePort();
}

test('interruption during preparation terminates its child and removes all sandbox data', () => temporary(parent => fakeRuntime(parent, 'setup-wait', async checkout => {
  const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 150);
  try { await assert.rejects(launch({ checkout, signal: controller.signal }), /preparation failed/); }
  finally { clearTimeout(timer); }
})));

test('HTTP process failure never prints credentials and cleans up', () => temporary(parent => fakeRuntime(parent, 'server-fail', async checkout => {
  let output = '';
  await assert.rejects(launch({ checkout, output: { write: value => { output += value; } } }), /HTTP startup failed/);
  assert.equal(output, '');
})));

test('a foreign readiness marker cannot disclose credentials', () => temporary(parent => fakeRuntime(parent, 'wrong-marker', async checkout => {
  const controller = new AbortController(); let output = '';
  const timer = setTimeout(() => controller.abort(), 450);
  try { await launch({ checkout, signal: controller.signal, output: { write: value => { output += value; } } }); }
  finally { clearTimeout(timer); }
  assert.equal(output, '');
})));

test('credentials appear only for owned readiness and stop cleans its server and data', () => temporary(parent => fakeRuntime(parent, 'ready', async checkout => {
  const controller = new AbortController(); let output = '';
  await launch({ checkout, signal: controller.signal, output: { write: value => { output += value; controller.abort(); } } });
  assert.match(output, /PRIVATE ALPHA.*disposable synthetic/s);
  assert.match(output, /Temporary password: Alpha-[a-f0-9]{48}/);
})));

test('shutdown escalates for an owned server that ignores SIGTERM', { timeout: 8000 }, () => temporary(parent => fakeRuntime(parent, 'ignore-term', async checkout => {
  const controller = new AbortController();
  await launch({ checkout, signal: controller.signal, output: { write: () => controller.abort() } });
})));

const php = spawnSync('php', ['-r', 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_sqlite") ? 0 : 1);'], { stdio: 'ignore' });
const nativeAvailable = php.status === 0 && existsSync(join(root, 'vendor/autoload.php'));
test('native PHP bootstrap prerequisites are required by CI', () => {
  if (process.env.PRIVATE_ALPHA_REQUIRE_PHP === '1') assert.equal(nativeAvailable, true, 'PHP 8.4+, pdo_sqlite and installed Composer dependencies are required, not skipped.');
});

test('native PHP refuses production, external paths, config caches and provider policy before migration', { skip: !nativeAvailable && 'PHP 8.4+/SQLite/Composer unavailable; not runtime evidence' }, () => temporary(parent => {
  for (const change of [
    env => { env.APP_ENV = 'production'; }, env => { env.DB_DATABASE = join(parent, 'preserved.sqlite'); },
    env => { env.STRIPE_TEST_CHECKOUT_ENABLED = 'true'; }, env => { env.VASEY_TEST_ORDER_POLICY = '{"sentinel":true}'; },
    env => { writeFileSync(env.APP_CONFIG_CACHE, '<?php throw new Exception("SENTINEL");'); },
    env => { writeFileSync(join(env.VASEY_ALPHA_DIRECTORY, '.env.local'), 'APP_ENV=production'); },
  ]) {
    const sandbox = createSandbox(parent); writeFileSync(join(parent, 'preserved.sqlite'), 'PRESERVE');
    try {
      change(sandbox.env);
      const result = spawnSync('php', ['scripts/dev/private-alpha-bootstrap.php', 'prepare'], { cwd: root, env: sandbox.env, encoding: 'utf8', timeout: 10000 });
      assert.equal(result.status, 1, result.stderr);
      assert.equal(statSync(join(sandbox.directory, 'database.sqlite')).size, 0);
      assert.equal(readFileSync(join(parent, 'preserved.sqlite'), 'utf8'), 'PRESERVE');
      assert.doesNotMatch(result.stdout + result.stderr, /SENTINEL|PRESERVE/);
    } finally { removeSandbox(sandbox); }
  }
}));

test('native preparation and HTTP preserve isolation, real draft evidence and unsafe-path refusal', { skip: !nativeAvailable && 'PHP 8.4+/SQLite/Composer unavailable; not runtime evidence' }, () => temporary(async parent => {
  const sandbox = createSandbox(parent);
  let server;
  const sentinel = join(parent, 'outside.txt');
  const publicLink = join(root, 'public', `alpha-safety-${sandbox.marker}.txt`);
  try {
    const checkoutBefore = { environment: snapshot(join(root, '.env')), caches: snapshot(join(root, 'bootstrap/cache')), public: snapshot(join(root, 'public')) };
    const result = spawnSync('php', ['scripts/dev/private-alpha-bootstrap.php', 'prepare'], { cwd: root, env: sandbox.env, encoding: 'utf8', timeout: 60000 });
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual({ environment: snapshot(join(root, '.env')), caches: snapshot(join(root, 'bootstrap/cache')), public: snapshot(join(root, 'public')) }, checkoutBefore);
    const query = '$db = new PDO("sqlite:".getenv("DB_DATABASE")); echo json_encode(["users"=>$db->query("SELECT name,email,is_admin FROM users")->fetchAll(PDO::FETCH_ASSOC),"tracks"=>$db->query("SELECT title,status FROM tracks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),"provisioned"=>$db->query("SELECT count(*) FROM audit_events WHERE action = \'access.operator.created\'")->fetchColumn(),"media"=>$db->query("SELECT count(*) FROM media_assets")->fetchColumn(),"offers"=>$db->query("SELECT count(*) FROM offers")->fetchColumn(),"orders"=>$db->query("SELECT count(*) FROM orders")->fetchColumn()]);';
    const readback = spawnSync('php', ['-r', query], { cwd: root, env: sandbox.env, encoding: 'utf8' });
    assert.equal(readback.status, 0, readback.stderr);
    const data = JSON.parse(readback.stdout);
    assert.deepEqual(data.users, [{ name: 'Synthetic Alpha Operator', email: 'synthetic-alpha-operator@example.test', is_admin: 1 }]);
    assert.equal(data.tracks.length, 2); assert.ok(data.tracks.every(track => track.status === 'draft' && track.title.startsWith('SYNTHETIC ALPHA')));
    assert.equal(Number(data.provisioned), 1); for (const key of ['media', 'offers', 'orders']) assert.equal(Number(data[key]), 0);
    const before = readFileSync(sandbox.env.DB_DATABASE);
    const again = spawnSync('php', ['scripts/dev/private-alpha-bootstrap.php', 'prepare'], { cwd: root, env: sandbox.env, encoding: 'utf8', timeout: 10000 });
    assert.equal(again.status, 1); assert.deepEqual(readFileSync(sandbox.env.DB_DATABASE), before);
    assert.doesNotMatch(result.stdout + result.stderr, new RegExp(sandbox.password));
    await requireFreePort();
    writeFileSync(sentinel, 'PRIVATE-OUTSIDE-SENTINEL'); symlinkSync(sentinel, publicLink);
    server = spawn('php', ['-S', '127.0.0.1:8174', '-t', 'public', 'scripts/dev/private-alpha-bootstrap.php'], { cwd: root, env: sandbox.env, stdio: 'ignore' });
    let ready = false;
    for (let attempt = 0; attempt < 100 && server.exitCode === null; attempt++) {
      try {
        const response = await fetch(`${origin}/up`, { redirect: 'manual', signal: AbortSignal.timeout(1000) });
        ready = response.status === 200 && response.headers.get('X-Vasey-Private-Alpha') === sandbox.marker;
        await response.body?.cancel();
        if (ready) break;
      } catch { /* Await only our newly spawned server. */ }
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.equal(ready, true, 'Real isolated Laravel HTTP boot must succeed.');
    let loginHtml;
    for (const path of ['/admin/login', '/brand/theme.css']) {
      const response = await fetch(`${origin}${path}`, { redirect: 'manual' });
      assert.equal(response.status, 200, path);
      if (path === '/admin/login') loginHtml = await response.text(); else await response.body?.cancel();
    }
    const assets = [...loginHtml.matchAll(/\b(?:src|href)="([^\"]*\/(?:css|js)\/filament\/[^\"]+)"/g)].map(match => match[1].replaceAll('&amp;', '&'));
    assert.ok(assets.length > 0, 'Real admin asset URLs are required.');
    for (const asset of assets) {
      const url = new URL(asset, origin); assert.equal(url.origin, origin);
      const response = await fetch(url, { redirect: 'manual' }); assert.equal(response.status, 200, url.pathname); await response.body?.cancel();
    }
    if (existsSync(join(root, 'public/build/manifest.json'))) {
      const build = await fetch(`${origin}/build/manifest.json`); assert.equal(build.status, 200); await build.body?.cancel();
    }
    const guest = await fetch(`${origin}/admin/tracks`, { redirect: 'manual' });
    assert.equal(guest.status, 302); assert.equal(new URL(guest.headers.get('location'), origin).pathname, '/admin/login'); await guest.body?.cancel();
    const catalog = await fetch(`${origin}/api/catalog`); assert.equal(catalog.status, 200);
    const empty = await catalog.json(); assert.deepEqual(empty.tracks, []); assert.equal(empty.commerceEnabled, false);
    const update = loginHtml.match(/data-update-uri="([^\"]+)"/); assert.ok(update);
    const updateUrl = new URL(update[1].replaceAll('&amp;', '&'), origin); assert.equal(updateUrl.origin, origin);
    const csrf = await fetch(updateUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ components: [] }), redirect: 'manual' });
    assert.equal(csrf.status, 419); await csrf.body?.cancel();
    for (const path of ['/index.php', '/%69ndex.php', '/index.php/admin', '/storage/private.txt', '/.env', `/${publicLink.split('/').at(-1)}`]) {
      const response = await fetch(`${origin}${path}`, { redirect: 'manual' });
      assert.equal(response.status, 404, path); assert.doesNotMatch(await response.text(), /PRIVATE-OUTSIDE-SENTINEL/);
    }
    // Node's fetch replaces a supplied Host; use a native request to send the adversarial header on the wire.
    const foreign = await new Promise((resolve, reject) => {
      const outgoing = request(`${origin}/up`, { headers: { Host: 'outside.example.test' } }, response => {
        response.resume(); response.once('end', () => resolve(response));
      });
      outgoing.once('error', reject); outgoing.end();
    });
    assert.equal(foreign.statusCode, 503); assert.equal(foreign.headers['x-vasey-private-alpha'], undefined);
    assert.equal(readFileSync(sentinel, 'utf8'), 'PRIVATE-OUTSIDE-SENTINEL');
    await verifyOperatorEdit(sandbox.password);
    const persisted = spawnSync('php', ['-r', '$db = new PDO("sqlite:".getenv("DB_DATABASE")); echo json_encode([$db->query("SELECT title,metadata_version FROM tracks WHERE id=1")->fetch(PDO::FETCH_ASSOC),$db->query("SELECT count(*) FROM audit_events WHERE action = \'catalog.track.metadata_updated\'")->fetchColumn()]);'], { cwd: root, env: sandbox.env, encoding: 'utf8' });
    assert.equal(persisted.status, 0, persisted.stderr);
    assert.deepEqual(JSON.parse(persisted.stdout), [{ title: 'SYNTHETIC ALPHA — HTTP verified edit', metadata_version: 2 }, 1]);
  } finally {
    if (server?.pid && server.exitCode === null && server.signalCode === null) {
      await new Promise(resolve => { server.once('exit', resolve); server.kill('SIGTERM'); });
    }
    rmSync(publicLink, { force: true }); removeSandbox(sandbox);
    assert.throws(() => lstatSync(publicLink), { code: 'ENOENT' });
  }
}));
