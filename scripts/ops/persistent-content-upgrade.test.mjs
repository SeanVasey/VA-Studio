import test, { after, before } from 'node:test';
import assert from 'node:assert/strict';
import { createHash, createHmac } from 'node:crypto';
import { chmodSync, cpSync, existsSync, linkSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { acquireLease, initialize, isolatedEnvironment, launch, origin, readWorkspace, requireFreePort, root } from '../dev/persistent-content.mjs';
import { reviewedCheckout, upgradeWorkspace } from './persistent-content-upgrade.mjs';

const sha = value => createHash('sha256').update(value).digest('hex');
let base;
let oldRelease;
let additiveRelease;
let destructiveRelease;
let sequenceRelease;
let failedRelease;
let waitingRelease;
let removedCheckRelease;
let changedStorageRelease;
const available = spawnSync('php', ['-r', 'exit(PHP_MAJOR_VERSION===8 && PHP_MINOR_VERSION>=4 && extension_loaded("pdo_sqlite") && function_exists("posix_geteuid") ? 0 : 1);']).status === 0 && existsSync(join(root, 'vendor/autoload.php')) && existsSync(join(root, 'public/build/manifest.json'));

function git(checkout, args) {
  const result = spawnSync('git', args, { cwd: checkout, encoding: 'utf8' }); assert.equal(result.status, 0, result.stderr); return result.stdout.trim();
}

function release(parent, name, migration) {
  const checkout = join(parent, name); mkdirSync(checkout, { mode: 0o700 });
  for (const path of git(root, ['ls-files']).split('\n')) {
    mkdirSync(dirname(join(checkout, path)), { recursive: true }); cpSync(join(root, path), join(checkout, path));
  }
  cpSync(join(root, 'public/build'), join(checkout, 'public/build'), { recursive: true });
  mkdirSync(join(checkout, 'vendor'), { mode: 0o700 });
  for (const name of readdirSync(join(root, 'vendor'))) {
    const from = join(root, 'vendor', name); const to = join(checkout, 'vendor', name);
    if (name === 'composer') cpSync(from, to, { recursive: true });
    else if (name === 'autoload.php') cpSync(from, to);
    else symlinkSync(realpathSync(from), to, 'dir');
  }
  if (migration) writeFileSync(join(checkout, 'database/migrations/2099_01_01_000001_private_upgrade_fixture.php'), `<?php\nuse Illuminate\\Database\\Migrations\\Migration;use Illuminate\\Database\\Schema\\Blueprint;use Illuminate\\Support\\Facades\\Schema;use Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration { public function up():void { ${migration} } public function down():void { throw new RuntimeException('No fixture downgrade.'); }};\n`);
  git(checkout, ['init', '-q']); git(checkout, ['add', '.']);
  git(checkout, ['-c', 'user.name=Private Upgrade Fixture', '-c', 'user.email=private-upgrade-fixture@example.test', 'commit', '-q', '-m', 'Nonbinding isolated upgrade source']);
  return { checkout, commit: git(checkout, ['rev-parse', 'HEAD']) };
}

before(() => {
  if (!available) return;
  base = mkdtempSync(join(realpathSync(tmpdir()), 'private-copy-upgrade-fixtures-')); chmodSync(base, 0o700);
  oldRelease = release(base, 'old');
  additiveRelease = release(base, 'additive', "Schema::table('tracks',fn(Blueprint $table)=>$table->string('private_upgrade_note')->nullable());Schema::create('private_upgrade_new_table',function(Blueprint $table){$table->id();$table->string('value');});");
  destructiveRelease = release(base, 'destructive', "DB::table('users')->where('id',1)->update(['name'=>'MUTATED-PRIVATE-ROW','updated_at'=>'2099-01-01 00:00:00']);");
  sequenceRelease = release(base, 'sequence', "DB::statement(\"UPDATE sqlite_sequence SET seq=seq+500 WHERE name='tracks'\");");
  failedRelease = release(base, 'failed', "throw new RuntimeException('NONBINDING secret-like fixture failure must not escape.');");
  waitingRelease = release(base, 'waiting', "file_put_contents(storage_path('fixture-migration-waiting'),'waiting');$deadline=microtime(true)+10;while(!file_exists(storage_path('fixture-migration-release'))){if(microtime(true)>$deadline)throw new RuntimeException('Fixture expired.');usleep(50000);}Schema::create('private_upgrade_waited',fn(Blueprint $table)=>$table->id());");
  removedCheckRelease = release(base, 'removed-check', "DB::statement('ALTER TABLE private_upgrade_check_fixture RENAME TO private_upgrade_old_check');DB::statement('CREATE TABLE private_upgrade_check_fixture (id INTEGER PRIMARY KEY AUTOINCREMENT, value INTEGER NOT NULL)');DB::statement('INSERT INTO private_upgrade_check_fixture SELECT * FROM private_upgrade_old_check');DB::statement('DROP TABLE private_upgrade_old_check');");
  changedStorageRelease = release(base, 'changed-storage', "DB::statement('UPDATE private_upgrade_binary_fixture SET payload=CAST(payload AS TEXT)');");
});
after(() => { if (base) rmSync(base, { recursive: true, force: true }); });

function native(name, fn) {
  test(name, async t => {
    if (!available && process.env.PERSISTENT_UPGRADE_REQUIRE_PHP !== '1') { t.skip('Real PHP8.4/SQLite/POSIX, locked dependencies and built assets unavailable.'); return; }
    assert.equal(available, true, 'PERSISTENT_UPGRADE_REQUIRE_PHP=1 requires a genuine runtime.');
    const parent = mkdtempSync(join(base, 'case-')); chmodSync(parent, 0o700);
    try { await fn(parent); } finally { rmSync(parent, { recursive: true, force: true }); }
  });
}

function bytes(directory) {
  const result = {};
  function walk(parent = '') {
    for (const name of readdirSync(join(directory, parent)).sort()) {
      const path = parent ? `${parent}/${name}` : name; const file = join(directory, path);
      if (lstatSync(file).isDirectory()) walk(path); else result[path] = sha(readFileSync(file));
    }
  }
  walk(); return result;
}

function query(workspace, sql) {
  const result = spawnSync('php', ['-r', '$pdo=new PDO("sqlite:".getenv("DB_DATABASE"));echo json_encode($pdo->query($argv[1])->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);', sql], { cwd: workspace.checkout, env: isolatedEnvironment(workspace), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr); return JSON.parse(result.stdout);
}

async function sourceWorkspace(parent) {
  const directory = join(parent, 'source');
  await initialize(directory, { checkout: oldRelease.checkout, output: { write() {} } });
  const workspace = readWorkspace(directory, oldRelease.checkout);
  const env = isolatedEnvironment(workspace); const lease = await acquireLease(workspace, env);
  try {
    const account = spawnSync('php', ['scripts/dev/persistent-content-bootstrap.php', 'operator'], { cwd: workspace.checkout, env, input: 'NONBINDING private upgrade operator\nprivate-upgrade-operator@example.test\nNonbindingPrivateUpgrade987654321\n', encoding: 'utf8' });
    assert.equal(account.status, 0, account.stderr); assert.doesNotMatch(account.stdout, /NonbindingPrivateUpgrade987654321/);
    const program = 'umask(0077);require "vendor/autoload.php";$app=require "bootstrap/app.php";$dir=getenv("VASEY_CONTENT_DIRECTORY");$app->useEnvironmentPath($dir);$app->useStoragePath($dir);$app->usePublicPath($dir."/public");$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$actor=App\\Models\\User::sole();app(App\\Domain\\Catalog\\SaveTrackMetadata::class)->handle(null,["title"=>"NONBINDING private retained track","slug"=>"private-retained-track","artist"=>"Nonbinding fixture operator"],$actor);$pdo=new PDO("sqlite:".getenv("DB_DATABASE"));$pdo->exec("CREATE TABLE private_upgrade_binary_fixture (id INTEGER PRIMARY KEY AUTOINCREMENT,payload BLOB,note TEXT,measure REAL)");$statement=$pdo->prepare("INSERT INTO private_upgrade_binary_fixture (payload,note,measure) VALUES (?,?,?)");$statement->bindValue(1,chr(0).chr(255)."private",PDO::PARAM_LOB);$statement->bindValue(2,null,PDO::PARAM_NULL);$statement->bindValue(3,1.0000000000000002);$statement->execute();$pdo->exec("CREATE TABLE private_upgrade_check_fixture (id INTEGER PRIMARY KEY AUTOINCREMENT, value INTEGER NOT NULL CHECK(value >= 0))");$pdo->exec("INSERT INTO private_upgrade_check_fixture (value) VALUES (7)");';
    const created = spawnSync('php', ['-r', program], { cwd: workspace.checkout, env, encoding: 'utf8' }); assert.equal(created.status, 0, created.stderr);
  } finally { await lease.release(); }
  writeFileSync(join(directory, 'app/private/retained-fixture-source.bin'), Buffer.from([0, 255, 16, 32, 42]), { mode: 0o600 });
  writeFileSync(join(directory, 'framework/sessions/retained-fixture-session'), 'NONBINDING opaque retained session', { mode: 0o600 });
  return readWorkspace(directory, oldRelease.checkout);
}

function args(workspace, parent, target = additiveRelease) {
  return { sourceCheckout: oldRelease.checkout, sourceDirectory: workspace.directory, directory: join(parent, 'destination'), expectedSourceSha: oldRelease.commit, expectedTargetSha: target.commit, checkout: target.checkout, output: { write() {} } };
}

async function withServer(workspace, fn) {
  const controller = new AbortController();
  let announce;
  let fail;
  const ready = new Promise((resolveReady, reject) => { announce = resolveReady; fail = reject; });
  let output = '';
  const running = launch(workspace.directory, { checkout: workspace.checkout, signal: controller.signal, output: { write(value) { output += value; announce(); } } });
  running.catch(fail);
  const deadline = setTimeout(() => fail(new Error('Real upgraded HTTP startup did not complete.')), 20000);
  try {
    await ready;
    assert.match(output, /Payments, customer enrollment, mail, media processing, workers and scheduler are disabled/);
    assert.doesNotMatch(output, /base64:|password:|[a-f0-9]{64}/i);
    await fn();
  } finally { clearTimeout(deadline); controller.abort(); await running; }
  await requireFreePort();
}

function attribute(value) {
  const named = { quot: '"', apos: "'", amp: '&', lt: '<', gt: '>' };
  return value.replace(/&(?:#(\d+)|#x([\da-f]+)|(quot|apos|amp|lt|gt));/gi,
    (_match, decimal, hex, name) => name ? named[name.toLowerCase()] : String.fromCodePoint(Number.parseInt(decimal ?? hex, decimal ? 10 : 16)));
}

function client() {
  const cookies = new Map();
  async function visit(path, options = {}) {
    const response = await fetch(new URL(path, origin), { ...options, redirect: 'manual', signal: AbortSignal.timeout(10000), headers: {
      Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; '), ...options.headers,
    } });
    for (const raw of response.headers.getSetCookie()) {
      const first = raw.split(';')[0]; const at = first.indexOf('='); cookies.set(first.slice(0, at), first.slice(at + 1));
      if (first.startsWith('vasey_content_')) assert.match(raw, /HttpOnly/i);
      assert.match(raw, /SameSite=strict/i);
    }
    return response;
  }
  return {
    visit,
    async signIn() {
      const response = await visit('/admin/login'); assert.equal(response.status, 200); const html = await response.text();
      const snapshot = [...html.matchAll(/wire:snapshot="([^\"]+)"/g)].map(match => attribute(match[1])).find(raw => JSON.parse(raw).memo.name.includes('Login'));
      const token = html.match(/<meta name="csrf-token" content="([^\"]+)"/); const endpoint = html.match(/data-update-uri="([^\"]+)"/);
      assert.ok(snapshot); assert.ok(token); assert.ok(endpoint);
      const authenticated = await visit(attribute(endpoint[1]), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Livewire': '' }, body: JSON.stringify({ _token: attribute(token[1]), components: [{ snapshot, updates: {
        'data.email': 'private-upgrade-operator@example.test', 'data.password': 'NonbindingPrivateUpgrade987654321',
      }, calls: [{ path: '', method: 'authenticate', params: [] }] }] }) });
      assert.equal(authenticated.status, 200); const result = await authenticated.json();
      assert.deepEqual(JSON.parse(result.components[0].snapshot).memo.errors, []);
      assert.equal(result.components[0].effects.redirect, `${origin}/admin`);
    },
  };
}

native('a reviewed additive copy upgrade preserves original bytes, every old cell, key, session and business sequence', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory);
  const rows = query(source, 'SELECT * FROM tracks'); const audits = query(source, 'SELECT * FROM audit_events ORDER BY id'); const sequences = query(source, 'SELECT name,seq FROM sqlite_sequence WHERE name <> \'migrations\' ORDER BY name');
  const input = args(source, parent); const proof = await upgradeWorkspace(input);
  assert.deepEqual(bytes(source.directory), before);
  const target = readWorkspace(input.directory, input.checkout);
  assert.equal(target.identity.app_key, source.identity.app_key); assert.equal(target.identity.session_cookie, source.identity.session_cookie); assert.equal(target.identity.installation_id, source.identity.installation_id);
  assert.deepEqual(query(target, 'SELECT id,title,slug,artist,metadata_version,created_at,updated_at FROM tracks'), rows.map(({ id, title, slug, artist, metadata_version, created_at, updated_at }) => ({ id, title, slug, artist, metadata_version, created_at, updated_at })));
  assert.deepEqual(query(target, 'SELECT * FROM audit_events ORDER BY id'), audits);
  assert.deepEqual(query(target, 'SELECT name,seq FROM sqlite_sequence WHERE name <> \'migrations\' ORDER BY name'), sequences);
  assert.deepEqual(readFileSync(join(target.directory, 'app/private/retained-fixture-source.bin')), readFileSync(join(source.directory, 'app/private/retained-fixture-source.bin')));
  assert.equal(readFileSync(join(target.directory, 'framework/sessions/retained-fixture-session'), 'utf8'), 'NONBINDING opaque retained session');
  assert.equal(query(target, 'SELECT private_upgrade_note FROM tracks')[0].private_upgrade_note, null);
  assert.equal(proof.original_workspace_unchanged, true); assert.equal(proof.production_backup_or_restore, false);
  const signed = JSON.parse(readFileSync(join(target.directory, 'upgrade-provenance.json')));
  assert.equal(signed.proof.target_identity_sha256, sha(readFileSync(join(target.directory, 'identity.json'))));
  assert.equal(signed.hmac_sha256, createHmac('sha256', Buffer.from(target.identity.app_key.slice(7), 'base64')).update(JSON.stringify(signed.proof)).digest('hex'));
  const result = JSON.parse(readFileSync(join(target.directory, 'upgrade-result.json'))); assert.ok(result.old_rows_verified >= 2); assert.equal(result.migration_sequence_advance, 1);
});

native('a genuine crash-retained WAL and SHM are captured without opening or checkpointing the original database', async parent => {
  const source = await sourceWorkspace(parent); const env = isolatedEnvironment(source);
  const code = 'umask(0077);$pdo=new PDO("sqlite:".getenv("DB_DATABASE"));$pdo->exec("PRAGMA journal_mode=WAL");$pdo->exec("PRAGMA wal_autocheckpoint=0");$pdo->exec("INSERT INTO private_upgrade_binary_fixture (note) VALUES (\'WAL-ONLY-RETAINED\')");echo "WAL-READY\\n";fflush(STDOUT);fgets(STDIN);';
  const writer = spawn('php', ['-r', code], { cwd: source.checkout, env, stdio: ['pipe', 'pipe', 'ignore'] });
  try {
    await new Promise((resolveReady, reject) => {
      const deadline = setTimeout(() => reject(new Error('Native WAL fixture did not become ready.')), 10000);
      const finish = error => { clearTimeout(deadline); error ? reject(error) : resolveReady(); };
      writer.once('error', finish); writer.once('exit', () => finish(new Error('Native WAL fixture stopped early.')));
      writer.stdout.on('data', data => { if (String(data).includes('WAL-READY')) finish(); });
    });
    writer.kill('SIGKILL'); await new Promise(resolveExit => writer.once('exit', resolveExit));
    assert.ok(lstatSync(join(source.directory, 'database.sqlite-wal')).size > 0); assert.ok(existsSync(join(source.directory, 'database.sqlite-shm')));
    const before = bytes(source.directory); const input = args(source, parent);
    const proof = await upgradeWorkspace(input); assert.deepEqual(bytes(source.directory), before);
    const target = readWorkspace(input.directory, input.checkout);
    assert.deepEqual(query(target, "SELECT note FROM private_upgrade_binary_fixture WHERE note='WAL-ONLY-RETAINED'"), [{ note: 'WAL-ONLY-RETAINED' }]);
    assert.equal(proof.source_database_capture.find(file => file.path === 'database.sqlite-wal').sha256, before['database.sqlite-wal']);
    assert.equal(proof.source_database_capture.find(file => file.path === 'database.sqlite-shm').sha256, before['database.sqlite-shm']);
  } finally { if (writer.exitCode === null && writer.signalCode === null) writer.kill('SIGKILL'); }
});

native('real authenticated HTTP sessions survive copy upgrade and restart while private paths and checkout remain blocked', async parent => {
  const source = await sourceWorkspace(parent); const browser = client();
  await withServer(source, async () => {
    await browser.signIn(); const tracks = await browser.visit('/admin/tracks'); assert.equal(tracks.status, 200);
    assert.match(await tracks.text(), /NONBINDING private retained track/);
  });
  const before = bytes(source.directory); const input = args(source, parent); await upgradeWorkspace(input);
  assert.deepEqual(bytes(source.directory), before);
  const target = readWorkspace(input.directory, input.checkout); const identity = sha(readFileSync(join(target.directory, 'identity.json')));
  for (let restart = 0; restart < 2; restart++) {
    await withServer(target, async () => {
      const tracks = await browser.visit('/admin/tracks'); assert.equal(tracks.status, 200);
      const html = await tracks.text(); assert.match(html, /NONBINDING private retained track/);
      for (const path of ['/identity.json', '/database.sqlite', '/upgrade-plan.json', '/upgrade-baseline.json', '/upgrade-provenance.json', '/app/private/retained-fixture-source.bin', '/.env', '/vendor/autoload.php']) {
        const blocked = await browser.visit(path); assert.equal(blocked.status, 404, path); await blocked.body?.cancel();
      }
      const token = html.match(/<meta name="csrf-token" content="([^\"]+)"/); assert.ok(token);
      const checkout = await browser.visit('/checkout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ _token: attribute(token[1]) }) });
      assert.equal(checkout.status, 503); assert.equal((await checkout.json()).code, 'COMMERCE_NOT_ENABLED');
    });
    assert.equal(sha(readFileSync(join(target.directory, 'identity.json'))), identity);
  }
  assert.deepEqual(bytes(source.directory), before);
});

native('unchanged row counts do not hide a rewritten old value or timestamp; only the unavailable copy is affected', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent, destructiveRelease);
  await assert.rejects(upgradeWorkspace(input), /original workspace was not changed/i);
  assert.deepEqual(bytes(source.directory), before);
  const identity = JSON.parse(readFileSync(join(input.directory, 'identity.json'))); assert.equal(identity.state, 'initializing');
  const target = { directory: input.directory, checkout: input.checkout, identity };
  assert.deepEqual(query(target, 'SELECT name,updated_at FROM users'), [{ name: 'MUTATED-PRIVATE-ROW', updated_at: '2099-01-01 00:00:00' }]);
  await assert.rejects(launch(input.directory, { checkout: input.checkout }), /safety checks/);
  const destination = bytes(input.directory); await assert.rejects(upgradeWorkspace(input)); assert.deepEqual(bytes(input.directory), destination);
});

native('old SQLite business sequence changes refuse even when all table rows remain identical', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent, sequenceRelease);
  await assert.rejects(upgradeWorkspace(input)); assert.deepEqual(bytes(source.directory), before);
  assert.equal(JSON.parse(readFileSync(join(input.directory, 'identity.json'))).state, 'initializing');
  assert.equal(existsSync(join(input.directory, 'upgrade-result.json')), false);
});

native('removing an old CHECK refuses even when every column, row and business sequence remains identical', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent, removedCheckRelease);
  const rows = query(source, 'SELECT * FROM private_upgrade_check_fixture');
  const sequence = query(source, "SELECT * FROM sqlite_sequence WHERE name='private_upgrade_check_fixture'");
  await assert.rejects(upgradeWorkspace(input)); assert.deepEqual(bytes(source.directory), before);
  const identity = JSON.parse(readFileSync(join(input.directory, 'identity.json'))); assert.equal(identity.state, 'initializing');
  const target = { directory: input.directory, checkout: input.checkout, identity };
  assert.deepEqual(query(target, 'SELECT * FROM private_upgrade_check_fixture'), rows);
  assert.deepEqual(query(target, "SELECT * FROM sqlite_sequence WHERE name='private_upgrade_check_fixture'"), sequence);
  assert.doesNotMatch(query(target, "SELECT sql FROM sqlite_schema WHERE name='private_upgrade_check_fixture'")[0].sql, /CHECK/i);
  assert.equal(existsSync(join(input.directory, 'upgrade-result.json')), false);
  assert.equal(existsSync(join(input.directory, 'upgrade-provenance.json')), false);
});

native('BLOB to TEXT storage-class rewriting refuses despite identical retained binary bytes and row counts', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent, changedStorageRelease);
  const original = query(source, 'SELECT typeof(payload) AS storage_type,hex(payload) AS payload_hex FROM private_upgrade_binary_fixture');
  assert.equal(original[0].storage_type, 'blob');
  await assert.rejects(upgradeWorkspace(input)); assert.deepEqual(bytes(source.directory), before);
  const identity = JSON.parse(readFileSync(join(input.directory, 'identity.json'))); assert.equal(identity.state, 'initializing');
  const target = { directory: input.directory, checkout: input.checkout, identity };
  assert.deepEqual(query(target, 'SELECT typeof(payload) AS storage_type,hex(payload) AS payload_hex FROM private_upgrade_binary_fixture'), [{ storage_type: 'text', payload_hex: original[0].payload_hex }]);
  assert.equal(existsSync(join(input.directory, 'upgrade-result.json')), false);
  assert.equal(existsSync(join(input.directory, 'upgrade-provenance.json')), false);
});

native('migration failure retains an unavailable destination and hides its raw exception without touching the original', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent, failedRelease);
  await assert.rejects(upgradeWorkspace(input), error => { assert.doesNotMatch(error.message, /secret-like|RuntimeException|2099_/); return true; });
  assert.deepEqual(bytes(source.directory), before); assert.equal(JSON.parse(readFileSync(join(input.directory, 'identity.json'))).state, 'initializing');
  assert.equal(existsSync(join(input.directory, 'upgrade-provenance.json')), false);
});

native('locked source, overlapping destination and explicit copy budget refuse before destination creation', async parent => {
  const source = await sourceWorkspace(parent); const input = args(source, parent); const env = isolatedEnvironment(source); const lease = await acquireLease(source, env);
  const held = bytes(source.directory);
  try { await assert.rejects(upgradeWorkspace(input)); assert.equal(existsSync(input.directory), false); assert.deepEqual(bytes(source.directory), held); }
  finally { await lease.release(); }
  const before = bytes(source.directory);
  await assert.rejects(upgradeWorkspace({ ...input, maximumBytes: 1 })); assert.equal(existsSync(input.directory), false); assert.deepEqual(bytes(source.directory), before);
  await assert.rejects(upgradeWorkspace({ ...input, directory: join(source.directory, 'nested') })); assert.equal(existsSync(join(source.directory, 'nested')), false);
});

native('in-flight destination never starts and another copy operation cannot reuse the same source', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent, waitingRelease);
  const running = upgradeWorkspace(input); let finish;
  try {
    const deadline = Date.now() + 15000;
    while (!existsSync(join(input.directory, 'fixture-migration-waiting')) && Date.now() < deadline) await new Promise(resolveDelay => setTimeout(resolveDelay, 50));
    assert.equal(existsSync(join(input.directory, 'fixture-migration-waiting')), true);
    await assert.rejects(launch(input.directory, { checkout: input.checkout }), /safety checks/);
    await assert.rejects(upgradeWorkspace({ ...input, directory: join(parent, 'second-destination') })); assert.equal(existsSync(join(parent, 'second-destination')), false);
  } finally {
    if (existsSync(input.directory)) writeFileSync(join(input.directory, 'fixture-migration-release'), 'release', { mode: 0o600 });
    finish = await running;
  }
  assert.equal(finish.original_workspace_unchanged, true); assert.deepEqual(bytes(source.directory), before);
});

native('a moved, tampered or unreviewed source refuses without exposing or overwriting existing content', async parent => {
  const source = await sourceWorkspace(parent); const before = bytes(source.directory); const input = args(source, parent);
  await assert.rejects(upgradeWorkspace({ ...input, expectedSourceSha: '0'.repeat(40) })); assert.equal(existsSync(input.directory), false);
  await assert.rejects(upgradeWorkspace({ ...input, expectedTargetSha: '0'.repeat(40) })); assert.equal(existsSync(input.directory), false);
  assert.deepEqual(bytes(source.directory), before);
  const alias = join(parent, 'source-alias'); symlinkSync(source.directory, alias, 'dir');
  await assert.rejects(upgradeWorkspace({ ...input, sourceDirectory: alias }));
  mkdirSync(input.directory, { mode: 0o700 }); writeFileSync(join(input.directory, 'sentinel'), 'KEEP', { mode: 0o600 });
  await assert.rejects(upgradeWorkspace(input)); assert.equal(readFileSync(join(input.directory, 'sentinel'), 'utf8'), 'KEEP');
});

native('tracked-source mutation, ignored executable migration and retrospective migration rewriting refuse', async parent => {
  const modified = release(parent, 'modified'); const manifest = join(modified.checkout, 'database/migrations');
  const first = readdirSync(manifest).filter(name => name.endsWith('.php')).sort()[0];
  writeFileSync(join(manifest, first), readFileSync(join(manifest, first), 'utf8') + '\n// changed after reviewed SHA\n');
  assert.throws(() => reviewedCheckout(modified.checkout, modified.commit));
  git(modified.checkout, ['add', '.']); git(modified.checkout, ['-c', 'user.name=Private Upgrade Fixture', '-c', 'user.email=private-upgrade-fixture@example.test', 'commit', '-q', '-m', 'Nonbinding retrospective migration mutation']);
  const source = await sourceWorkspace(parent); const before = bytes(source.directory);
  await assert.rejects(upgradeWorkspace(args(source, parent, { checkout: modified.checkout, commit: git(modified.checkout, ['rev-parse', 'HEAD']) })));
  assert.deepEqual(bytes(source.directory), before);
  const ignored = release(parent, 'ignored'); writeFileSync(join(ignored.checkout, '.git/info/exclude'), '\ndatabase/migrations/2099_ignored.php\n');
  writeFileSync(join(ignored.checkout, 'database/migrations/2099_ignored.php'), '<?php throw new RuntimeException("Must never execute");');
  assert.throws(() => reviewedCheckout(ignored.checkout, ignored.commit));
});

native('Git replacement refs and executable-mode drift cannot redefine an exact reviewed checkout', async parent => {
  const modified = release(parent, 'replaced');
  const original = modified.commit;
  writeFileSync(join(modified.checkout, 'README.md'), readFileSync(join(modified.checkout, 'README.md'), 'utf8') + '\nNONBINDING replacement tree mutation\n');
  git(modified.checkout, ['add', 'README.md']); git(modified.checkout, ['-c', 'user.name=Private Upgrade Fixture', '-c', 'user.email=private-upgrade-fixture@example.test', 'commit', '-q', '-m', 'Nonbinding replacement tree']);
  const replacement = git(modified.checkout, ['rev-parse', 'HEAD']);
  git(modified.checkout, ['replace', original, replacement]);
  git(modified.checkout, ['update-ref', 'HEAD', original]);
  assert.equal(git(modified.checkout, ['rev-parse', 'HEAD']), original);
  assert.throws(() => reviewedCheckout(modified.checkout, original));
  const mode = release(parent, 'mode'); chmodSync(join(mode.checkout, 'app/Models/User.php'), 0o755);
  assert.throws(() => reviewedCheckout(mode.checkout, mode.commit));
});

native('source descendant links and an unsafe target ancestor refuse without copying or mutating private bytes', async parent => {
  const source = await sourceWorkspace(parent); const input = args(source, parent); const before = bytes(source.directory);
  const retained = join(source.directory, 'app/private/retained-fixture-source.bin');
  const link = join(source.directory, 'app/private/linked-fixture-source.bin');
  linkSync(retained, link);
  await assert.rejects(upgradeWorkspace(input)); assert.equal(existsSync(input.directory), false);
  rmSync(link); assert.deepEqual(bytes(source.directory), before);
  symlinkSync(retained, link);
  await assert.rejects(upgradeWorkspace(input)); assert.equal(existsSync(input.directory), false);
  rmSync(link); assert.deepEqual(bytes(source.directory), before);
  const unsafe = join(parent, 'unsafe'); mkdirSync(unsafe, { mode: 0o777 }); chmodSync(unsafe, 0o777);
  const owned = join(unsafe, 'owned'); mkdirSync(owned, { mode: 0o700 });
  await assert.rejects(upgradeWorkspace({ ...input, directory: join(owned, 'destination') }));
  assert.equal(existsSync(join(owned, 'destination')), false); assert.deepEqual(bytes(source.directory), before);
});
