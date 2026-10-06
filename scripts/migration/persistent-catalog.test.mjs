import test, { after, before } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { chmodSync, cpSync, existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, realpathSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { acquireLease, initialize, isolatedEnvironment, readWorkspace, root } from '../dev/persistent-content.mjs';
import { parseArguments, releaseProof } from './persistent-catalog.mjs';

const password = 'NonbindingCatalogOperator987654321';
const available = spawnSync('php', ['-r', 'exit(PHP_MAJOR_VERSION===8 && PHP_MINOR_VERSION>=4 && extension_loaded("pdo_sqlite") && function_exists("posix_geteuid") ? 0 : 1);']).status === 0
  && spawnSync('script', ['--version']).status === 0 && existsSync(join(root, 'vendor/autoload.php')) && existsSync(join(root, 'public/build/manifest.json'));
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
let base;
let release;

function git(checkout, args) {
  const result = spawnSync('git', args, { cwd: checkout, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

before(() => {
  if (!available) return;
  base = mkdtempSync(join(realpathSync(tmpdir()), 'private-catalog-native-fixtures-')); chmodSync(base, 0o700);
  const checkout = join(base, 'release'); mkdirSync(checkout, { mode: 0o700 });
  for (const path of git(root, ['ls-files']).split('\n')) {
    mkdirSync(dirname(join(checkout, path)), { recursive: true }); cpSync(join(root, path), join(checkout, path));
  }
  cpSync(join(root, 'public/build'), join(checkout, 'public/build'), { recursive: true });
  mkdirSync(join(checkout, 'vendor'), { mode: 0o700 });
  for (const name of readdirSync(join(root, 'vendor'))) {
    const from = join(root, 'vendor', name); const to = join(checkout, 'vendor', name);
    if (name === 'composer' || name === 'autoload.php') cpSync(from, to, { recursive: true });
    else symlinkSync(realpathSync(from), to, 'dir');
  }
  git(checkout, ['init', '-q']); git(checkout, ['add', '.']);
  git(checkout, ['-c', 'user.name=Private Catalog Fixture', '-c', 'user.email=private-catalog@example.test', 'commit', '-q', '-m', 'SYNTHETIC isolated catalog adapter source']);
  release = { checkout, commit: git(checkout, ['rev-parse', 'HEAD']) };
});
after(() => { if (base) rmSync(base, { recursive: true, force: true }); });

function native(name, operation) {
  test(name, async t => {
    if (!available && process.env.PERSISTENT_CATALOG_REQUIRE_PHP !== '1') {
      t.skip('Real PHP8.4/SQLite/POSIX, util-linux script, locked dependencies and built assets unavailable.'); return;
    }
    assert.equal(available, true, 'PERSISTENT_CATALOG_REQUIRE_PHP=1 requires the actual native runtime.');
    const parent = mkdtempSync(join(base, 'case-')); chmodSync(parent, 0o700);
    try { await operation(parent); } finally { rmSync(parent, { recursive: true, force: true }); }
  });
}

function query(workspace, sql) {
  const result = spawnSync('php', ['-r', '$pdo=new PDO("sqlite:".getenv("DB_DATABASE"));echo json_encode($pdo->query($argv[1])->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);', sql],
    { cwd: workspace.checkout, env: isolatedEnvironment(workspace), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr); return JSON.parse(result.stdout);
}

function rows(workspace) {
  return Object.fromEntries(['users', 'tracks', 'audit_events', 'catalog_import_batches', 'catalog_import_mappings', 'orders', 'pending_entitlements']
    .map(table => [table, query(workspace, `SELECT * FROM ${table} ORDER BY id`)]));
}

async function assertLeaseAvailable(workspace) {
  // A leftover token is not ownership: the shared broker verifies a live OS lock.
  const lease = await acquireLease(workspace, isolatedEnvironment(workspace));
  await lease.release();
  assert.ok(lease.child.exitCode !== null || lease.child.signalCode !== null);
}

async function prepared(parent, count = 2) {
  const directory = join(parent, 'installation');
  await initialize(directory, { checkout: release.checkout, output: { write() {} } });
  const workspace = readWorkspace(directory, release.checkout);
  const env = isolatedEnvironment(workspace); const lease = await acquireLease(workspace, env);
  try {
    const result = spawnSync('php', ['scripts/dev/persistent-content-bootstrap.php', 'operator'], { cwd: release.checkout, env,
      encoding: 'utf8', input: `SYNTHETIC catalog operator\nprivate-catalog-operator@example.test\n${password}\n`, timeout: 15000 });
    assert.equal(result.status, 0, result.stderr); assert.ok(!result.stdout.includes(password));
  } finally { await lease.release(); }
  const sourceDirectory = join(parent, 'source'); mkdirSync(sourceDirectory, { mode: 0o700 }); mkdirSync(join(sourceDirectory, 'raw'), { mode: 0o700 });
  const fixture = spawnSync('php', ['-r', 'require "vendor/autoload.php";echo App\\Support\\CanonicalJson::encode(Tests\\Support\\NormalizedCatalogFixtures::snapshot((int)$argv[1]))."\\n";', String(count)],
    { cwd: release.checkout, env, encoding: 'utf8' });
  assert.equal(fixture.status, 0, fixture.stderr);
  const snapshot = JSON.parse(fixture.stdout);
  writeFileSync(join(sourceDirectory, 'catalog.json'), fixture.stdout, { mode: 0o600 });
  writeFileSync(join(sourceDirectory, 'raw/source.csv'), 'SYNTHETIC NORMALIZED CATALOG EVIDENCE ONLY\nsource-id,title,bpm\n001,SYNTHETIC Alpha,0095\n', { mode: 0o600 });
  assert.equal(hash(readFileSync(join(sourceDirectory, 'raw/source.csv'))), snapshot.artifacts[0].sha256);
  const reports = join(parent, 'reports'); mkdirSync(reports, { mode: 0o700 });
  return { workspace, sourceDirectory, report: join(reports, 'review.json') };
}

function argumentsFor(input, command = 'review', digest, limit = 1) {
  const args = ['scripts/migration/persistent-catalog.mjs', command, '--directory', input.workspace.directory,
    '--source-directory', input.sourceDirectory, '--report', input.report, '--actor-id', '1', '--expected-target-sha', release.commit];
  if (command === 'apply') args.push('--expected-review-sha256', digest, '--limit', String(limit));
  return args;
}

async function consoleOperation(input, command = 'review', digest, { credential = password, limit = 1, interrupt = false } = {}) {
  const quote = value => `'${value.replaceAll("'", "'\\''")}'`;
  const program = [process.execPath, ...argumentsFor(input, command, digest, limit)].map(quote).join(' ');
  const child = spawn('script', ['-q', '-e', '-c', program, '/dev/null'], { cwd: release.checkout, env: process.env, stdio: ['pipe', 'pipe', 'pipe'] });
  let output = ''; let answered = false; let answerTimer;
  const capture = data => {
    output += data;
    if (!answered && output.includes('Staff password (hidden): ')) {
      answered = true;
      answerTimer = setTimeout(() => child.stdin.write(interrupt ? '\x03' : `${credential}\n`), 75);
    }
  };
  child.stdout.on('data', capture); child.stderr.on('data', capture);
  const deadline = setTimeout(() => child.kill('SIGKILL'), 20000);
  try {
    const status = await new Promise((resolveExit, reject) => { child.once('error', reject); child.once('exit', resolveExit); });
    assert.ok(!output.includes(credential), 'Hidden credential leaked to console.');
    const result = output.split(/[\r\n]+/).filter(line => line.startsWith('{')).map(line => JSON.parse(line)).at(-1);
    return { status, output, result, answered };
  } finally { clearTimeout(deadline); clearTimeout(answerTimer); child.stdin.end(); }
}

native('real hidden-password review is read-only and bounded committed draft segments survive process restart and lost success replay', async parent => {
  const input = await prepared(parent, 3); const before = rows(input.workspace);
  const manifest = readFileSync(join(input.sourceDirectory, 'catalog.json')); const raw = readFileSync(join(input.sourceDirectory, 'raw/source.csv'));
  const reviewed = await consoleOperation(input); assert.equal(reviewed.status, 0, reviewed.output); assert.equal(reviewed.answered, true);
  assert.deepEqual(rows(input.workspace), before); assert.deepEqual(reviewed.result.counts, { conflict: 0, create_draft: 3, skip: 0 });
  assert.doesNotMatch(reviewed.output, /SYNTHETIC Alpha|private note|base64:/); assert.equal(lstatSync(input.report).mode & 0o7777, 0o600);
  const digest = reviewed.result.review_sha256;
  const first = await consoleOperation(input, 'apply', digest); assert.equal(first.status, 0, first.output); assert.equal(first.result.processed, 1); assert.equal(first.result.complete, false);
  readWorkspace(input.workspace.directory, release.checkout);
  const second = await consoleOperation(input, 'apply', digest, { limit: 2 }); assert.equal(second.status, 0, second.output); assert.equal(second.result.processed, 3); assert.equal(second.result.complete, true);
  const complete = rows(input.workspace); assert.equal(complete.tracks.length, 3); assert.equal(complete.catalog_import_mappings.length, 3);
  assert.equal(complete.orders.length, 0); assert.equal(complete.pending_entitlements.length, 0);
  for (const track of complete.tracks) { assert.equal(track.status, 'draft'); assert.equal(track.published_at, null); assert.equal(track.published_slug, null); }
  const replay = await consoleOperation(input, 'apply', digest, { limit: 25 }); assert.equal(replay.status, 0, replay.output); assert.equal(replay.result.created, 0);
  assert.deepEqual(rows(input.workspace), complete); assert.deepEqual(readFileSync(join(input.sourceDirectory, 'catalog.json')), manifest); assert.deepEqual(readFileSync(join(input.sourceDirectory, 'raw/source.csv')), raw);
  await assertLeaseAvailable(input.workspace);
});

native('wrong password and nonterminal input refuse without a report or database mutation', async parent => {
  const input = await prepared(parent, 1); const before = rows(input.workspace);
  const wrong = await consoleOperation(input, 'review', undefined, { credential: 'SYNTHETIC-wrong-password' }); assert.notEqual(wrong.status, 0);
  assert.equal(existsSync(input.report), false); assert.deepEqual(rows(input.workspace), before);
  const piped = spawnSync(process.execPath, argumentsFor(input), { cwd: release.checkout, env: process.env, input: `${password}\n`, encoding: 'utf8', timeout: 15000 });
  assert.notEqual(piped.status, 0); assert.equal(existsSync(input.report), false); assert.deepEqual(rows(input.workspace), before);
  assert.ok(!(piped.stdout + piped.stderr).includes(password));
});

native('an active installation OS lease refuses catalog operation without stopping the existing owner', async parent => {
  const input = await prepared(parent, 1); const before = rows(input.workspace);
  const env = isolatedEnvironment(input.workspace); const lease = await acquireLease(input.workspace, env);
  try {
    const result = await consoleOperation(input); assert.notEqual(result.status, 0); assert.equal(result.answered, false);
    assert.equal(lease.child.exitCode, null); assert.equal(existsSync(input.report), false); assert.deepEqual(rows(input.workspace), before);
  } finally { await lease.release(); }
});

for (const mutation of ['source', 'report', 'digest', 'target', 'schema']) {
  native(`a ${mutation} change after a native private review is refused without importing a segment`, async parent => {
    const input = await prepared(parent, 1); const reviewed = await consoleOperation(input); assert.equal(reviewed.status, 0, reviewed.output);
    let digest = reviewed.result.review_sha256;
    if (mutation === 'source') writeFileSync(join(input.sourceDirectory, 'raw/source.csv'), 'SYNTHETIC changed source', { mode: 0o600 });
    if (mutation === 'report') { const report = JSON.parse(readFileSync(input.report)); report.review.entries[0].proposed_metadata.title = 'SYNTHETIC forged'; writeFileSync(input.report, JSON.stringify(report)); }
    if (mutation === 'digest') digest = '0'.repeat(64);
    if (mutation === 'target') {
      const result = spawnSync('php', ['-r', '$pdo=new PDO("sqlite:".getenv("DB_DATABASE"));$pdo->exec("INSERT INTO audit_events (actor_id,action,subject_type,subject_id,context,created_at) VALUES (1,\'SYNTHETIC target change\',\'SYNTHETIC\',1,\'{}\',\'2026-10-06 12:00:00\')");'],
        { cwd: release.checkout, env: isolatedEnvironment(input.workspace), encoding: 'utf8' }); assert.equal(result.status, 0, result.stderr);
    }
    if (mutation === 'schema') {
      const result = spawnSync('php', ['-r', '$pdo=new PDO("sqlite:".getenv("DB_DATABASE"));$pdo->exec("DROP TRIGGER catalog_import_mappings_immutable_delete");'],
        { cwd: release.checkout, env: isolatedEnvironment(input.workspace), encoding: 'utf8' }); assert.equal(result.status, 0, result.stderr);
    }
    const before = rows(input.workspace); const applied = await consoleOperation(input, 'apply', digest); assert.notEqual(applied.status, 0);
    assert.deepEqual(rows(input.workspace), before); await assertLeaseAvailable(input.workspace);
  });
}

native('interrupting a hidden credential prompt stops its worker and releases the installation lease before another operation', async parent => {
  const input = await prepared(parent, 1); const before = rows(input.workspace);
  const cancelled = await consoleOperation(input, 'review', undefined, { interrupt: true }); assert.notEqual(cancelled.status, 0); assert.equal(cancelled.answered, true);
  assert.equal(existsSync(input.report), false); assert.deepEqual(rows(input.workspace), before); await assertLeaseAvailable(input.workspace);
  const next = await consoleOperation(input); assert.equal(next.status, 0, next.output);
});

native('changed reviewed checkout and unknown CLI switches are refused before password prompts or report writes', async parent => {
  const input = await prepared(parent, 1); const before = rows(input.workspace);
  assert.throws(() => parseArguments([...argumentsFor(input).slice(1), '--password', password]));
  const path = join(release.checkout, 'app/Domain/Migration/CatalogOnboarding/NormalizedSourceSnapshot.php'); const original = readFileSync(path);
  try {
    writeFileSync(path, Buffer.concat([original, Buffer.from('\n// SYNTHETIC unreviewed source\n')]));
    assert.throws(() => releaseProof(release.checkout, release.commit));
    const result = await consoleOperation(input); assert.notEqual(result.status, 0); assert.equal(result.answered, false); assert.equal(existsSync(input.report), false);
    assert.deepEqual(rows(input.workspace), before);
  } finally { writeFileSync(path, original); }
});
