import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { chmodSync, copyFileSync, existsSync, linkSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, renameSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { applyPinnedWebkitOfflineBackport, BACKPORT, REFUSAL } from './apply-playwright-webkit-offline-backport.mjs';

const root = resolve(import.meta.dirname, '../..');
const script = 'scripts/ci/apply-playwright-webkit-offline-backport.mjs';
const packages = ['@playwright/test', 'playwright', 'playwright-core'];
const old = 'if (contextOptions.offline)\n          promises2.push(session2.send("Network.setEmulateOfflineState", { offline: true }));';
const corrected = 'if (contextOptions.offline !== undefined)\n          promises2.push(session2.send("Network.setEmulateOfflineState", { offline: contextOptions.offline }));';
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const installed = readFileSync(join(root, BACKPORT.bundle));
const installedDigest = digest(installed);
assert.ok([BACKPORT.originalSha256, BACKPORT.patchedSha256].includes(installedDigest), 'Script fixtures require the actual known pinned SDK bundle.');
// The existing runner guard may already have applied the patch in this disposable checkout.
// Reconstruct stock bytes only in copied fixtures, only from the known exact patched digest.
const stock = installedDigest === BACKPORT.originalSha256 ? installed : Buffer.from(installed.toString().replace(corrected, old));
assert.equal(digest(stock), '549070af3acabb3efcc4f55bfe6210f9f7c2fcf633cf7eaa59bfe60719969171');

function fixture(t) {
  const base = mkdtempSync(join(tmpdir(), 'vasey-playwright-backport-'));
  const directory = join(base, 'checkout');
  t.after(() => rmSync(base, { recursive: true, force: true }));
  for (const relative of ['package.json', 'package-lock.json', script,
    ...packages.map(name => `node_modules/${name}/package.json`)]) {
    mkdirSync(dirname(join(directory, relative)), { recursive: true });
    copyFileSync(join(root, relative), join(directory, relative));
  }
  const bundle = join(directory, BACKPORT.bundle);
  mkdirSync(dirname(bundle), { recursive: true });
  writeFileSync(bundle, stock);
  return { base, directory, bundle };
}

function noWriteRefusal(f) {
  const before = readFileSync(f.bundle);
  const stat = statSync(f.bundle);
  assert.throws(() => applyPinnedWebkitOfflineBackport(f.directory), { message: REFUSAL });
  assert.deepEqual(readFileSync(f.bundle), before);
  assert.equal(statSync(f.bundle).mtimeMs, stat.mtimeMs);
  assert.equal(statSync(f.bundle).ino, stat.ino);
  assert.deepEqual(readdirSync(dirname(f.bundle)).filter(name => name.includes('.backport-')), []);
}

test('exact upstream two-line patch produces only the known patched full SDK bytes', t => {
  const f = fixture(t);
  chmodSync(f.bundle, 0o600);
  const originalInode = statSync(f.bundle).ino;
  assert.deepEqual(applyPinnedWebkitOfflineBackport(f.directory), {
    status: 'applied', version: '1.63.0',
    sha256: '4f5647c5a1fa104ff4536ec5923353d2a034d6d2f60050ed53b7633c3dadd67b',
    upstreamCommit: 'ea75b9f32ca4120efdfaf8f5b8cfd292f548f197',
  });
  const patched = readFileSync(f.bundle);
  assert.equal(patched.length, 3477215);
  assert.equal(digest(patched), BACKPORT.patchedSha256);
  assert.deepEqual(Buffer.from(patched.toString().replace(corrected, old)), stock);
  assert.equal(statSync(f.bundle).mode & 0o777, 0o600);
  assert.notEqual(statSync(f.bundle).ino, originalInode);
  const session = { send: (method, body) => ({ method, body }) };
  for (const offline of [false, true, undefined]) {
    const calls = [];
    new Function('contextOptions', 'session2', 'promises2', corrected)({ offline }, session, calls);
    assert.deepEqual(calls, offline === undefined ? [] : [{ method: 'Network.setEmulateOfflineState', body: { offline } }]);
  }
});

test('idempotence accepts only the exact known patched digest without another write', t => {
  const f = fixture(t);
  applyPinnedWebkitOfflineBackport(f.directory);
  const stat = statSync(f.bundle);
  const result = applyPinnedWebkitOfflineBackport(f.directory);
  assert.equal(result.status, 'already_applied');
  assert.equal(result.sha256, BACKPORT.patchedSha256);
  assert.equal(statSync(f.bundle).mtimeMs, stat.mtimeMs);
  assert.equal(statSync(f.bundle).ino, stat.ino);
  writeFileSync(f.bundle, Buffer.concat([readFileSync(f.bundle), Buffer.from('\n')]));
  noWriteRefusal(f);
});

test('every installed and lockfile SDK version is pinned before any mutation', async t => {
  for (const [kind, name] of [['project', ''], ['lock-root', ''],
    ...packages.flatMap(name => [['installed', name], ['lock-package', name]]),
    ['test-dependency', ''], ['core-dependency', '']]) {
    await t.test(`${kind} ${name}`, subtest => {
      const f = fixture(subtest);
      const relative = kind === 'project' ? 'package.json' : kind === 'installed' ? `node_modules/${name}/package.json` : 'package-lock.json';
      const path = join(f.directory, relative);
      const data = JSON.parse(readFileSync(path, 'utf8'));
      if (kind === 'project') data.devDependencies['@playwright/test'] = '1.64.0';
      else if (kind === 'installed') data.version = '1.64.0';
      else if (kind === 'lock-root') data.packages[''].devDependencies['@playwright/test'] = '1.64.0';
      else if (kind === 'lock-package') data.packages[`node_modules/${name}`].version = '1.64.0';
      else if (kind === 'test-dependency') data.packages['node_modules/@playwright/test'].dependencies.playwright = '1.64.0';
      else data.packages['node_modules/playwright'].dependencies['playwright-core'] = '1.64.0';
      writeFileSync(path, JSON.stringify(data));
      noWriteRefusal(f);
    });
  }
});

test('unknown bytes, duplicated old snippet and a partial patch are refused unchanged', async t => {
  for (const [name, bytes] of [
    ['unrelated appended bytes', Buffer.concat([stock, Buffer.from('\n')])],
    ['duplicate old snippet', Buffer.concat([stock, Buffer.from(old)])],
    ['condition-only partial patch', Buffer.from(stock.toString().replace('if (contextOptions.offline)\n          promises2', 'if (contextOptions.offline !== undefined)\n          promises2'))],
    ['truncated SDK', stock.subarray(0, -1)],
  ]) {
    await t.test(name, subtest => {
      const f = fixture(subtest);
      writeFileSync(f.bundle, bytes);
      noWriteRefusal(f);
    });
  }
});

test('shared node_modules, SDK directories, bundle and SDK CLI symlinks are refused', async t => {
  for (const relative of ['node_modules', 'node_modules/playwright-core', 'node_modules/playwright-core/lib',
    BACKPORT.bundle, 'node_modules/@playwright/test/cli.js']) {
    await t.test(relative, subtest => {
      const f = fixture(subtest);
      const path = join(f.directory, relative);
      const external = join(f.base, 'outside-checkout');
      const isBundleAncestor = relative !== 'node_modules/@playwright/test/cli.js';
      if (isBundleAncestor) renameSync(path, external);
      else writeFileSync(external, 'throw new Error("outside fixture CLI must never load");');
      symlinkSync(external, path);
      const outsideBundle = relative === 'node_modules' ? join(external, 'playwright-core/lib/coreBundle.js')
        : relative === 'node_modules/playwright-core' ? join(external, 'lib/coreBundle.js')
          : relative === 'node_modules/playwright-core/lib' ? join(external, 'coreBundle.js')
            : relative === BACKPORT.bundle ? external : f.bundle;
      assert.equal(digest(readFileSync(outsideBundle)), BACKPORT.originalSha256);
      noWriteRefusal(f);
      assert.equal(digest(readFileSync(outsideBundle)), BACKPORT.originalSha256);
    });
  }
});

test('atomic replacement leaves a hard-linked external stock bundle untouched', t => {
  const f = fixture(t);
  const external = join(f.base, 'outside-stock-bundle.js');
  linkSync(f.bundle, external);
  assert.equal(statSync(f.bundle).nlink, 2);
  applyPinnedWebkitOfflineBackport(f.directory);
  assert.equal(digest(readFileSync(f.bundle)), BACKPORT.patchedSha256);
  assert.equal(digest(readFileSync(external)), BACKPORT.originalSha256);
});

test('malformed or missing identity files fail closed', async t => {
  for (const [relative, operation] of [['package.json', 'malformed'], ['package-lock.json', 'missing'],
    ['node_modules/playwright-core/package.json', 'malformed'], ['node_modules/playwright/package.json', 'missing']]) {
    await t.test(`${relative} ${operation}`, subtest => {
      const f = fixture(subtest);
      const path = join(f.directory, relative);
      if (operation === 'missing') rmSync(path);
      else writeFileSync(path, '{');
      noWriteRefusal(f);
    });
  }
});

test('standalone CLI emits only a public pinned receipt and refuses all arguments', t => {
  const f = fixture(t);
  const result = spawnSync(process.execPath, [join(f.directory, script)], { cwd: f.directory, encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0);
  assert.equal(result.stderr, '');
  assert.deepEqual(JSON.parse(result.stdout), { status: 'applied', version: '1.63.0', sha256: BACKPORT.patchedSha256, upstreamCommit: BACKPORT.upstreamCommit });
  const stat = statSync(f.bundle);
  const refused = spawnSync(process.execPath, [join(f.directory, script), '--force'], { cwd: f.directory, encoding: 'utf8', timeout: 10_000 });
  assert.equal(refused.status, 1);
  assert.equal(refused.stdout, '');
  assert.equal(refused.stderr, `${REFUSAL}\n`);
  assert.equal(statSync(f.bundle).mtimeMs, stat.mtimeMs);
});

test('actual ordinary wrapper refuses unexpected SDK before PHP or private fixture setup', t => {
  const f = fixture(t);
  const wrapper = 'tests/browser/run.mjs';
  mkdirSync(dirname(join(f.directory, wrapper)), { recursive: true });
  copyFileSync(join(root, wrapper), join(f.directory, wrapper));
  for (const relative of ['vendor/autoload.php', 'public/build/manifest.json']) {
    mkdirSync(dirname(join(f.directory, relative)), { recursive: true });
    writeFileSync(join(f.directory, relative), '');
  }
  const bin = join(f.base, 'bin');
  const temporary = join(f.base, 'native-temporary');
  mkdirSync(bin); mkdirSync(temporary);
  const called = join(f.base, 'php-called');
  writeFileSync(join(bin, 'php'), `#!${process.execPath}\nrequire('node:fs').writeFileSync(${JSON.stringify(called)}, 'called');\n`, { mode: 0o700 });
  writeFileSync(f.bundle, Buffer.concat([stock, Buffer.from('\n')]));
  const result = spawnSync(process.execPath, [join(f.directory, wrapper), '--project=chromium-desktop'], {
    cwd: f.directory, env: { ...process.env, PATH: bin + ':' + process.env.PATH, TMPDIR: temporary }, encoding: 'utf8', timeout: 10_000,
  });
  assert.equal(result.status, 1);
  assert.equal(result.stdout, '');
  assert.ok(result.stderr.includes(REFUSAL));
  assert.equal(existsSync(called), false);
  assert.deepEqual(readdirSync(temporary), []);
  assert.deepEqual(readFileSync(f.bundle), Buffer.concat([stock, Buffer.from('\n')]));
});

test('copied fixture tests never mutate their installed SDK source', () => {
  assert.equal(digest(readFileSync(join(root, BACKPORT.bundle))), installedDigest);
});
