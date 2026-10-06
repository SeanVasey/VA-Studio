import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { chmodSync, cpSync, existsSync, globSync, linkSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, renameSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { createServer } from 'node:net';
import { request } from 'node:http';
import { spawn, spawnSync } from 'node:child_process';
import { acquireLease, canonicalPath, createWorkspace, initialize, isolatedEnvironment, launch, origin, readWorkspace, requireFreePort, root, schemaHash, validateCheckout } from './persistent-content.mjs';

function temporary(fn) {
  const parent = mkdtempSync(join(realpathSync(tmpdir()), 'persistent-content-test-'));
  chmodSync(parent, 0o700);
  return Promise.resolve().then(() => fn(parent)).finally(() => rmSync(parent, { recursive: true, force: true }));
}

function hash(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function retained(workspace) {
  return {
    identity: hash(join(workspace.directory, 'identity.json')),
    database: hash(join(workspace.directory, 'database.sqlite')),
    private: existsSync(join(workspace.directory, 'app/private/retained-test-content')) ? hash(join(workspace.directory, 'app/private/retained-test-content')) : null,
  };
}

const phpAvailable = spawnSync('php', ['-r', 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION >= 4 && extension_loaded("pdo_sqlite") && extension_loaded("mbstring") && extension_loaded("intl") && function_exists("posix_geteuid") ? 0 : 1);'], { encoding: 'utf8' }).status === 0
  && existsSync(join(root, 'vendor/autoload.php')) && existsSync(join(root, 'public/build/manifest.json'));

function native(name, fn) {
  test(name, async t => {
    if (!phpAvailable && process.env.PERSISTENT_CONTENT_REQUIRE_PHP !== '1') {
      t.skip('Native PHP8.4/SQLite, locked Composer dependencies and built assets are unavailable.');
      return;
    }
    assert.equal(phpAvailable, true, 'PERSISTENT_CONTENT_REQUIRE_PHP=1 requires a real supported runtime.');
    await temporary(fn);
  });
}

function php(workspace, env, command, input) {
  return spawnSync('php', ['scripts/dev/persistent-content-bootstrap.php', command], {
    cwd: workspace.checkout, env, encoding: 'utf8', input, timeout: 20000,
  });
}

function sql(workspace, query) {
  const result = spawnSync('php', ['-r', '$db = new PDO("sqlite:".getenv("DB_DATABASE")); echo json_encode($db->query($argv[1])->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);', query], {
    env: isolatedEnvironment(workspace), cwd: root, encoding: 'utf8', timeout: 10000,
  });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

async function readyWorkspace(parent) {
  const directory = join(parent, 'content');
  const output = [];
  await initialize(directory, { output: { write: value => output.push(value) } });
  const workspace = readWorkspace(directory);
  assert.doesNotMatch(output.join(''), /base64:|[a-f0-9]{64}|password:/i);
  return workspace;
}

async function operator(workspace) {
  const password = 'Nonbinding-Local-Operator-987654321';
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    const result = php(workspace, env, 'operator', `Native Private Operator\npersistent-operator@example.test\n${password}\n`);
    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /Operator created/);
    assert.doesNotMatch(result.stdout + result.stderr, new RegExp(password));
    assert.doesNotMatch(readFileSync(join(workspace.directory, 'identity.json'), 'utf8'), /persistent-operator|Nonbinding-Local-Operator/);
    return password;
  } finally { await lease.release(); }
}

async function withServer(workspace, fn) {
  const controller = new AbortController();
  let announce;
  let fail;
  const ready = new Promise((resolveReady, reject) => { announce = resolveReady; fail = reject; });
  let output = '';
  const running = launch(workspace.directory, { signal: controller.signal, output: { write: value => { output += value; announce(); } } });
  running.catch(fail);
  const deadline = setTimeout(() => fail(new Error('Owned HTTP startup did not complete.')), 20000);
  try {
    await ready;
    assert.match(output, /durable local authoring/);
    assert.match(output, /media processing, workers and scheduler are disabled/);
    assert.doesNotMatch(output, /base64:|password:|[a-f0-9]{64}/i);
    await fn();
  } finally {
    clearTimeout(deadline);
    controller.abort();
    await running;
  }
  await requireFreePort();
}

function attribute(value) {
  const named = { quot: '"', apos: "'", amp: '&', lt: '<', gt: '>' };
  return value.replace(/&(?:#(\d+)|#x([\da-f]+)|(quot|apos|amp|lt|gt));/gi,
    (_match, decimal, hex, name) => name ? named[name.toLowerCase()] : String.fromCodePoint(Number.parseInt(decimal ?? hex, decimal ? 10 : 16)));
}

function operatorClient() {
  const cookies = new Map();
  async function visit(path, options = {}) {
    const response = await fetch(new URL(path, origin), { ...options, redirect: 'manual', headers: {
      Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; '), ...options.headers,
    } });
    for (const raw of response.headers.getSetCookie()) {
      const first = raw.split(';')[0]; const at = first.indexOf('='); cookies.set(first.slice(0, at), first.slice(at + 1));
      if (first.startsWith('vasey_content_')) assert.match(raw, /HttpOnly/i);
      assert.match(raw, /SameSite=strict/i);
    }
    return response;
  }
  function component(html, name) {
    const snapshots = [...html.matchAll(/wire:snapshot="([^\"]+)"/g)].map(match => attribute(match[1]));
    const found = snapshots.find(raw => JSON.parse(raw).memo.name.includes(name)); assert.ok(found, name); return found;
  }
  async function call(html, snapshot, updates, method, params) {
    const token = html.match(/<meta name="csrf-token" content="([^\"]+)"/);
    const endpoint = html.match(/data-update-uri="([^\"]+)"/); assert.ok(token); assert.ok(endpoint);
    const response = await visit(attribute(endpoint[1]), { method: 'POST', headers: {
      'Content-Type': 'application/json', Accept: 'application/json', 'X-Livewire': '',
    }, body: JSON.stringify({ _token: attribute(token[1]), components: [{ snapshot, updates, calls: [{ path: '', method, params }] }] }) });
    assert.equal(response.status, 200); const result = await response.json();
    assert.deepEqual(JSON.parse(result.components[0].snapshot).memo.errors, []);
    return result.components[0];
  }
  return {
    visit,
    async signIn(password) {
      const login = await visit('/admin/login'); assert.equal(login.status, 200); const html = await login.text();
      const authenticated = await call(html, component(html, 'Login'), {
        'data.email': 'persistent-operator@example.test', 'data.password': password,
      }, 'authenticate', []);
      assert.equal(authenticated.effects.redirect, `${origin}/admin`);
    },
    async createTrack() {
      const tracks = await visit('/admin/tracks'); assert.equal(tracks.status, 200); const html = await tracks.text();
      const mounted = await call(html, component(html, 'ManageTracks'), {}, 'mountAction', ['create', {}, {}]);
      await call(html, mounted.snapshot, {
        'mountedActions.0.data.title': 'NONBINDING native persistent draft', 'mountedActions.0.data.slug': 'native-persistent-draft',
        'mountedActions.0.data.artist': 'Private fixture operator', 'mountedActions.0.data.description': 'Retained test metadata, no recording or rights claim.',
      }, 'callMountedAction', []);
    },
    async editTrack() {
      const tracks = await visit('/admin/tracks'); assert.equal(tracks.status, 200); const html = await tracks.text();
      const mounted = await call(html, component(html, 'ManageTracks'), {}, 'mountAction', ['edit', {}, { recordKey: '1', table: true }]);
      await call(html, mounted.snapshot, { 'mountedActions.0.data.title': 'NONBINDING native retained edit' }, 'callMountedAction', []);
    },
  };
}

test('new workspaces retain separate protected identities without operator credentials or catalog fixtures', () => temporary(parent => {
  const first = createWorkspace(join(parent, 'first'));
  const second = createWorkspace(join(parent, 'second'));
  assert.notEqual(first.identity.app_key, second.identity.app_key);
  assert.notEqual(first.identity.installation_id, second.identity.installation_id);
  assert.notEqual(first.identity.session_cookie, second.identity.session_cookie);
  assert.equal(first.identity.state, 'initializing');
  assert.equal(first.identity.schema_hash, schemaHash());
  for (const workspace of [first, second]) {
    assert.equal(lstatSync(workspace.directory).mode & 0o7777, 0o700);
    for (const name of ['identity.json', 'lease', 'database.sqlite']) assert.equal(lstatSync(join(workspace.directory, name)).mode & 0o7777, 0o600);
    assert.equal(statSync(join(workspace.directory, 'database.sqlite')).size, 0);
    assert.equal(readdirSync(join(workspace.directory, 'app/private')).length, 0);
    assert.throws(() => readWorkspace(workspace.directory), /safety checks/);
  }
}));

test('every current configuration variable and PHP/proxy override is isolated from the caller', () => temporary(parent => {
  const workspace = createWorkspace(join(parent, 'content'));
  const inherited = { PATH: process.env.PATH, PHP_INI_SCAN_DIR: 'ATTACK', PHPRC: 'ATTACK', NODE_OPTIONS: 'ATTACK', HTTP_PROXY: 'ATTACK', HTTPS_PROXY: 'ATTACK' };
  for (const file of globSync(join(root, 'config/*.php'))) {
    for (const match of readFileSync(file, 'utf8').matchAll(/env\(['"]([^'"]+)['"]/g)) inherited[match[1]] = 'ATTACK';
  }
  const env = isolatedEnvironment(workspace, inherited);
  assert.equal(env.PATH, process.env.PATH);
  assert.equal(Object.values(env).includes('ATTACK'), false);
  for (const key of ['HTTP_PROXY', 'HTTPS_PROXY', 'NODE_OPTIONS', 'PHPRC', 'PHP_INI_SCAN_DIR', 'VASEY_TEST_PRICING_POLICY', 'VASEY_TEST_CUSTOMER_IDENTITY_TRANSPORT']) assert.equal(env[key], undefined, key);
  assert.equal(env.APP_KEY, workspace.identity.app_key);
  assert.equal(env.DB_DATABASE, join(workspace.directory, 'database.sqlite'));
  assert.equal(env.APP_URL, origin);
  assert.equal(env.CONTACT_TEST_ORDER_INQUIRIES_ENABLED, 'false');
  assert.equal(env.VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED, 'false');
}));

test('noncanonical, linked, exposed and checkout-owned initialization paths are refused without overwrites', () => temporary(parent => {
  for (const path of ['relative', '/', `${parent}/`, `${parent}/./new`, `${parent}/../new`]) assert.throws(() => canonicalPath(path, { missingLeaf: true }), /safety checks/);
  const target = join(parent, 'target'); mkdirSync(target, { mode: 0o700 });
  const alias = join(parent, 'alias'); symlinkSync(target, alias, 'dir');
  assert.throws(() => createWorkspace(join(alias, 'new')), /safety checks/);
  assert.throws(() => createWorkspace(alias), /safety checks/);
  writeFileSync(join(target, 'sentinel'), 'RETAIN', { mode: 0o600 });
  assert.throws(() => createWorkspace(target), /safety checks/);
  assert.equal(readFileSync(join(target, 'sentinel'), 'utf8'), 'RETAIN');
  chmodSync(target, 0o777);
  assert.throws(() => createWorkspace(join(target, 'exposed')), /safety checks/);
  assert.equal(existsSync(join(target, 'exposed')), false);
  assert.throws(() => createWorkspace(join(root, 'storage', 'persistent-test-must-not-create')), /safety checks/);
  assert.equal(existsSync(join(root, 'storage', 'persistent-test-must-not-create')), false);
}));

test('a substituted directory inode and private child link cannot resume an installation', () => temporary(parent => {
  const workspace = createWorkspace(join(parent, 'content'));
  const copy = join(parent, 'copy'); cpSync(workspace.directory, copy, { recursive: true, preserveTimestamps: true });
  const original = join(parent, 'original'); renameSync(workspace.directory, original); renameSync(copy, workspace.directory);
  assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/);
  rmSync(workspace.directory, { recursive: true }); renameSync(original, workspace.directory);
  const outside = join(parent, 'outside'); mkdirSync(outside, { mode: 0o700 }); writeFileSync(join(outside, 'sentinel'), 'KEEP', { mode: 0o600 });
  symlinkSync(outside, join(workspace.directory, 'app/private/escape'), 'dir');
  assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/);
  assert.equal(readFileSync(join(outside, 'sentinel'), 'utf8'), 'KEEP');
}));

test('nonsticky writable ancestors and a newly exposed parent refuse init and resume without changing bytes', () => temporary(parent => {
  const exposed = join(parent, 'exposed'); mkdirSync(exposed, { mode: 0o700 }); chmodSync(exposed, 0o777);
  const ownedParent = join(exposed, 'owned'); mkdirSync(ownedParent, { mode: 0o700 });
  assert.throws(() => createWorkspace(join(ownedParent, 'content')), /safety checks/);
  assert.equal(existsSync(join(ownedParent, 'content')), false);
  const protectedParent = join(parent, 'protected'); mkdirSync(protectedParent, { mode: 0o700 });
  const workspace = createWorkspace(join(protectedParent, 'content'));
  const before = retained(workspace);
  chmodSync(protectedParent, 0o777);
  assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/);
  assert.deepEqual(retained(workspace), before);
}));

test('world access, hard links, poisoned environment/cache and changed schema identities refuse without reset', () => temporary(parent => {
  const workspace = createWorkspace(join(parent, 'content'));
  const before = retained(workspace);
  const identity = join(workspace.directory, 'identity.json');
  chmodSync(identity, 0o644); assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/); chmodSync(identity, 0o600);
  const alias = join(parent, 'identity-link'); linkSync(identity, alias);
  assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/); rmSync(alias);
  for (const file of ['.env', '.env.local', 'config.php', 'routes.php', 'events.php', 'disabled-clamscan']) {
    writeFileSync(join(workspace.directory, file), 'POISON', { mode: 0o600 });
    assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/, file);
    rmSync(join(workspace.directory, file));
  }
  const original = readFileSync(identity);
  const changed = JSON.parse(original); changed.schema_hash = '0'.repeat(64);
  writeFileSync(identity, JSON.stringify(changed));
  assert.throws(() => readWorkspace(workspace.directory, root, { allowInitializing: true }), /safety checks/);
  writeFileSync(identity, original);
  assert.deepEqual(retained(workspace), before);
}));

test('occupied port is refused without replacing or stopping its listener', async () => {
  const listener = createServer(socket => socket.end('EXISTING'));
  await new Promise((resolveListen, reject) => { listener.once('error', reject); listener.listen(8175, '127.0.0.1', resolveListen); });
  try { await assert.rejects(requireFreePort(), /in use/); assert.equal(listener.listening, true); }
  finally { await new Promise(resolveClose => listener.close(resolveClose)); }
});

test('a linked build root or manifest is refused before reading or copying external public assets', () => temporary(parent => {
  const checkout = join(parent, 'checkout');
  mkdirSync(join(checkout, 'vendor'), { recursive: true }); writeFileSync(join(checkout, 'vendor/autoload.php'), '');
  mkdirSync(join(checkout, 'public'), { recursive: true });
  const outside = join(parent, 'outside'); mkdirSync(outside); writeFileSync(join(outside, 'manifest.json'), 'EXTERNAL-SENTINEL');
  symlinkSync(outside, join(checkout, 'public/build'), 'dir');
  assert.throws(() => validateCheckout(checkout), /safety checks/);
  rmSync(join(checkout, 'public/build')); mkdirSync(join(checkout, 'public/build'));
  symlinkSync(join(outside, 'manifest.json'), join(checkout, 'public/build/manifest.json'));
  assert.throws(() => validateCheckout(checkout), /safety checks/);
  assert.equal(readFileSync(join(outside, 'manifest.json'), 'utf8'), 'EXTERNAL-SENTINEL');
}));

native('actual initialization migrates an empty catalog with no account, sale or default legal fixtures', async parent => {
  const workspace = await readyWorkspace(parent);
  for (const table of ['users', 'tracks', 'license_templates', 'license_versions', 'orders', 'audit_events']) {
    assert.deepEqual(sql(workspace, `SELECT COUNT(*) AS total FROM ${table}`), [{ total: 0 }]);
  }
  assert.ok(Number(sql(workspace, 'SELECT COUNT(*) AS total FROM migrations')[0].total) > 0);
  assert.equal(workspace.identity.state, 'ready');
  assert.equal(existsSync(join(workspace.directory, 'public/build/manifest.json')), true);
  assert.equal(hash(join(workspace.directory, 'public/build/manifest.json')), hash(join(root, 'public/build/manifest.json')));
});

native('a failed partial initialization preserves its key and bytes and cannot initialize or serve again', async parent => {
  const workspace = createWorkspace(join(parent, 'content'));
  writeFileSync(join(workspace.directory, 'database.sqlite'), 'PARTIAL-RETAINED-SENTINEL');
  const before = retained(workspace);
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    const result = php(workspace, env, 'initialize');
    assert.equal(result.status, 1);
    assert.match(result.stderr, /not reset or removed/);
    assert.equal(result.stdout, '');
  } finally { await lease.release(); }
  assert.deepEqual(retained(workspace), before);
  await assert.rejects(initialize(workspace.directory), /safety checks/);
  await assert.rejects(launch(workspace.directory), /safety checks/);
  assert.deepEqual(retained(workspace), before);
});

native('the OS lease rejects concurrent operations and a stale token cannot authorize a boot', async parent => {
  const workspace = await readyWorkspace(parent);
  const env = isolatedEnvironment(workspace);
  const before = retained(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    const second = isolatedEnvironment(workspace);
    await assert.rejects(acquireLease(workspace, second), /already in use/);
    assert.equal(readFileSync(join(workspace.directory, 'lease'), 'utf8'), env.VASEY_CONTENT_LEASE);
    assert.equal(php(workspace, env, 'verify').status, 0);
  } finally { await lease.release(); }
  writeFileSync(join(workspace.directory, 'lease'), env.VASEY_CONTENT_LEASE);
  assert.equal(php(workspace, env, 'verify').status, 1);
  assert.deepEqual(retained(workspace), before);
});

native('actual hidden operator provisioning stores a password hash and exactly one audited account', async parent => {
  const workspace = await readyWorkspace(parent);
  const initialIdentity = hash(join(workspace.directory, 'identity.json'));
  const password = await operator(workspace);
  const users = sql(workspace, 'SELECT name,email,password,is_admin,email_verified_at FROM users');
  assert.equal(users.length, 1);
  assert.equal(users[0].is_admin, 1);
  assert.equal(users[0].email, 'persistent-operator@example.test');
  assert.notEqual(users[0].password, password);
  assert.match(users[0].password, /^\$2[aby]\$/);
  assert.ok(users[0].email_verified_at);
  assert.deepEqual(sql(workspace, 'SELECT action,subject_id FROM audit_events'), [{ action: 'access.operator.created', subject_id: 1 }]);
  assert.equal(hash(join(workspace.directory, 'identity.json')), initialIdentity);
  assert.deepEqual(sql(workspace, 'SELECT COUNT(*) AS total FROM tracks'), [{ total: 0 }]);
});

native('real HTTP keeps empty/private routes, CSRF and foreign-host/storage boundaries with disabled commerce', async parent => {
  const workspace = await readyWorkspace(parent);
  const sentinel = join(parent, 'private-sentinel.txt'); writeFileSync(sentinel, 'PRIVATE-CONTENT-SENTINEL', { mode: 0o600 });
  const publicLink = join(root, 'public/persistent-content-test-escape.txt');
  symlinkSync(sentinel, publicLink);
  try {
    await withServer(workspace, async () => {
      const login = await fetch(`${origin}/admin/login`); assert.equal(login.status, 200);
      assert.equal(login.headers.get('X-Vasey-Private-Content'), workspace.identity.installation_id);
      assert.equal(login.headers.get('X-Robots-Tag'), 'noindex, nofollow');
      const html = await login.text();
      const assets = [...html.matchAll(/\b(?:src|href)="([^\"]*\/(?:css|js)\/filament\/[^\"]+)"/g)].map(match => attribute(match[1]));
      assert.ok(assets.length > 0);
      for (const asset of new Set(assets)) { const response = await fetch(new URL(asset, origin)); assert.equal(response.status, 200, asset); await response.body?.cancel(); }
      const guest = await fetch(`${origin}/admin/tracks`, { redirect: 'manual' }); assert.equal(guest.status, 302); await guest.body?.cancel();
      const catalog = await fetch(`${origin}/api/catalog`); assert.equal(catalog.status, 200);
      const empty = await catalog.json(); assert.deepEqual(empty.tracks, []); assert.equal(empty.commerceEnabled, false);
      const guestClient = operatorClient();
      const guestLogin = await guestClient.visit('/admin/login'); const guestHtml = await guestLogin.text();
      const guestToken = guestHtml.match(/<meta name="csrf-token" content="([^\"]+)"/); assert.ok(guestToken);
      const checkout = await guestClient.visit('/checkout', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ _token: attribute(guestToken[1]) }) });
      assert.equal(checkout.status, 503); await checkout.body?.cancel();
      const update = html.match(/data-update-uri="([^\"]+)"/); assert.ok(update);
      const csrf = await fetch(new URL(attribute(update[1]), origin), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ components: [] }), redirect: 'manual' });
      assert.equal(csrf.status, 419); await csrf.body?.cancel();
      for (const path of ['/index.php', '/%69ndex.php', '/storage/private-sentinel.txt', '/.env', '/identity.json', '/database.sqlite', '/persistent-content-test-escape.txt']) {
        const response = await fetch(`${origin}${path}`, { redirect: 'manual' }); assert.equal(response.status, 404, path);
        assert.doesNotMatch(await response.text(), /PRIVATE-CONTENT-SENTINEL|base64:/);
      }
      const foreign = await new Promise((resolveResponse, reject) => {
        const outgoing = request(`${origin}/up`, { headers: { Host: 'outside.example.test' } }, response => { response.resume(); response.once('end', () => resolveResponse(response)); });
        outgoing.once('error', reject); outgoing.end();
      });
      assert.equal(foreign.statusCode, 503);
      assert.equal(foreign.headers['x-vasey-private-content'], undefined);
    });
    assert.equal(readFileSync(sentinel, 'utf8'), 'PRIVATE-CONTENT-SENTINEL');
  } finally { rmSync(publicLink, { force: true }); }
});

native('real signed Livewire creation and edits, private bytes, key, database and session survive stop and restart', async parent => {
  const workspace = await readyWorkspace(parent);
  const password = await operator(workspace);
  const client = operatorClient();
  const content = join(workspace.directory, 'app/private/retained-test-content');
  writeFileSync(content, 'NONBINDING private source bytes remain across actual restart.\n', { mode: 0o600 });
  await withServer(workspace, async () => {
    await client.signIn(password);
    await client.createTrack();
    assert.deepEqual(sql(workspace, 'SELECT title,slug,status,metadata_version FROM tracks'), [{
      title: 'NONBINDING native persistent draft', slug: 'native-persistent-draft', status: 'draft', metadata_version: 1,
    }]);
  });
  const first = retained(workspace);
  const rows = sql(workspace, 'SELECT * FROM tracks');
  const audits = sql(workspace, 'SELECT * FROM audit_events ORDER BY id');
  await withServer(readWorkspace(workspace.directory), async () => {
    const tracks = await client.visit('/admin/tracks'); assert.equal(tracks.status, 200); const html = await tracks.text();
    assert.match(html, /NONBINDING native persistent draft/);
    const catalog = await fetch(`${origin}/api/catalog`); assert.deepEqual((await catalog.json()).tracks, []);
    assert.deepEqual(sql(workspace, 'SELECT * FROM tracks'), rows);
    assert.deepEqual(sql(workspace, 'SELECT * FROM audit_events ORDER BY id'), audits);
  });
  assert.deepEqual(retained(workspace), first, 'Start/stop must not rewrite the key, database or private content.');
  await withServer(readWorkspace(workspace.directory), async () => { await client.editTrack(); });
  assert.deepEqual(sql(workspace, 'SELECT title,metadata_version FROM tracks'), [{ title: 'NONBINDING native retained edit', metadata_version: 2 }]);
  assert.deepEqual(sql(workspace, 'SELECT action FROM audit_events ORDER BY id'), [
    { action: 'access.operator.created' }, { action: 'catalog.track.created' }, { action: 'catalog.track.metadata_updated' },
  ]);
  assert.equal(hash(join(workspace.directory, 'identity.json')), first.identity);
  assert.equal(hash(content), first.private);
  assert.ok(existsSync(workspace.directory));
});

native('an actual HTTP router refuses after the owning lease process dies even with its retained token', async parent => {
  const workspace = await readyWorkspace(parent);
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  await requireFreePort();
  const server = spawn('php', ['-S', '127.0.0.1:8175', '-t', 'public', 'scripts/dev/persistent-content-bootstrap.php'], { cwd: root, env, stdio: 'ignore' });
  try {
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
      try { const response = await fetch(`${origin}/admin/login`, { signal: AbortSignal.timeout(1000) }); ready = response.status === 200; await response.body?.cancel(); if (ready) break; } catch { /* Await owned server. */ }
      await new Promise(resolveDelay => setTimeout(resolveDelay, 50));
    }
    assert.equal(ready, true);
    lease.child.kill('SIGKILL');
    await new Promise(resolveExit => lease.child.exitCode !== null || lease.child.signalCode !== null ? resolveExit() : lease.child.once('exit', resolveExit));
    assert.equal(readFileSync(join(workspace.directory, 'lease'), 'utf8'), env.VASEY_CONTENT_LEASE, 'This simulates a genuine retained token after broker death.');
    const refused = await fetch(`${origin}/admin/login`);
    assert.equal(refused.status, 503);
    assert.equal(refused.headers.get('X-Vasey-Private-Content'), null);
    assert.equal(await refused.text(), 'Private content workspace is unavailable.');
  } finally {
    if (server.exitCode === null && server.signalCode === null) { server.kill('SIGTERM'); await new Promise(resolveExit => server.once('exit', resolveExit)); }
    await lease.release();
  }
  await requireFreePort();
  assert.equal(readWorkspace(workspace.directory).identity.app_key, workspace.identity.app_key);
});

native('tampered direct PHP configuration refuses before migrations or writes', async parent => {
  const workspace = await readyWorkspace(parent);
  const env = isolatedEnvironment(workspace);
  const before = retained(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    for (const [key, value] of [['DB_URL', 'sqlite:/outside.sqlite'], ['APP_URL', 'https://outside.example.test'], ['SESSION_ENCRYPT', 'false'], ['MAIL_MAILER', 'smtp'], ['VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED', 'true'], ['STRIPE_TEST_CHECKOUT_ENABLED', 'true'], ['APP_CONFIG_CACHE', '/outside/config.php']]) {
      const result = php(workspace, { ...env, [key]: value }, 'verify');
      assert.equal(result.status, 1, key); assert.equal(result.stdout, '', key); assert.match(result.stderr, /not reset or removed/);
      assert.deepEqual(retained(workspace), before, key);
    }
  } finally { await lease.release(); }
});

native('direct PHP refuses newly exposed parent and ancestor paths before boot and preserves all retained bytes', async parent => {
  const ownedParent = join(parent, 'owned'); mkdirSync(ownedParent, { mode: 0o700 });
  const workspace = await readyWorkspace(ownedParent);
  const env = isolatedEnvironment(workspace);
  const before = retained(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    for (const exposed of [ownedParent, parent]) {
      chmodSync(exposed, 0o777);
      const result = php(workspace, env, 'verify');
      assert.equal(result.status, 1); assert.equal(result.stdout, ''); assert.match(result.stderr, /not reset or removed/);
      assert.deepEqual(retained(workspace), before);
      chmodSync(exposed, 0o700);
    }
  } finally { chmodSync(parent, 0o700); chmodSync(ownedParent, 0o700); await lease.release(); }
});

native('direct PHP fails closed when effective UID inspection is disabled without changing private data', async parent => {
  const workspace = await readyWorkspace(parent);
  writeFileSync(join(workspace.directory, 'app/private/retained-test-content'), 'NONBINDING retained ownership-canary bytes', { mode: 0o600 });
  const before = retained(workspace);
  const env = isolatedEnvironment(workspace);
  const lease = await acquireLease(workspace, env);
  try {
    const result = spawnSync('php', ['-d', 'disable_functions=posix_geteuid', 'scripts/dev/persistent-content-bootstrap.php', 'verify'], {
      cwd: root, env, encoding: 'utf8', timeout: 10000,
    });
    assert.equal(result.status, 1);
    assert.equal(result.stdout, '');
    assert.equal(result.stderr, 'Persistent workspace isolation or operation failed. Retained files were not reset or removed.\n');
    assert.deepEqual(retained(workspace), before);
  } finally { await lease.release(); }
});
