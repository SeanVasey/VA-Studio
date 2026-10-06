import { createHash, createHmac, randomBytes } from 'node:crypto';
import { constants, lstatSync, readFileSync, readdirSync, realpathSync, statSync } from 'node:fs';
import { mkdir, open, readdir, writeFile } from 'node:fs/promises';
import { dirname, join, resolve, sep } from 'node:path';
import { spawn, execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { acquireLease, canonicalPath, isolatedEnvironment, readWorkspace, requireFreePort, root, safeAncestors, schemaHash, validateCheckout } from '../dev/persistent-content.mjs';

export const defaultMaximumBytes = 10 * 1024 ** 3;
const failure = 'Private copy upgrade refused or failed. The original workspace was not changed; an incomplete destination, if created, was retained unavailable.';
const runtimeDirectories = ['app', 'app/private', 'framework', 'framework/views', 'framework/sessions', 'framework/cache', 'framework/cache/data', 'logs', 'public', 'tmp'];

function requireSafe(condition) { if (!condition) throw new Error(failure); }
function hash(value) { return createHash('sha256').update(value).digest('hex'); }
function owned(stat) { return typeof process.getuid === 'function' && stat.uid === process.getuid(); }

function git(checkout, args) {
  return execFileSync('git', ['--no-replace-objects', '-c', 'core.fsmonitor=false', '--no-pager', ...args], {
    cwd: checkout, encoding: 'utf8', maxBuffer: 16 * 1024 ** 2,
    env: { PATH: process.env.PATH, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: '/dev/null', GIT_NO_REPLACE_OBJECTS: '1', TZ: 'UTC' },
    stdio: ['ignore', 'pipe', 'ignore'],
  });
}

/** Exact reviewed Git source; generated dependencies/build are separately bound by the installation identity. */
export function reviewedCheckout(checkout, expectedSha) {
  canonicalPath(checkout);
  validateCheckout(checkout);
  requireSafe(/^[a-f0-9]{40}$/.test(expectedSha) && git(checkout, ['rev-parse', '--verify', 'HEAD']).trim() === expectedSha);
  const tracked = new Set();
  for (const entry of git(checkout, ['ls-tree', '-r', '-z', '--full-tree', 'HEAD']).split('\0').filter(Boolean)) {
    const match = entry.match(/^(100644|100755) blob ([a-f0-9]{40})\t(.+)$/s);
    requireSafe(match !== null);
    const path = join(checkout, match[3]);
    canonicalPath(path);
    const stat = lstatSync(path);
    requireSafe(stat.isFile() && stat.nlink === 1 && stat.size <= 32 * 1024 ** 2 && ((stat.mode & 0o111) !== 0) === (match[1] === '100755'));
    const data = readFileSync(path);
    requireSafe(createHash('sha1').update(`blob ${data.length}\0`).update(data).digest('hex') === match[2]);
    tracked.add(match[3]);
  }
  // Ignored application/migration PHP cannot enter a supposedly reviewed release.
  for (const prefix of ['app', 'config', 'database/migrations', 'routes', 'scripts']) {
    const pending = [prefix];
    let count = 0;
    while (pending.length > 0) {
      const parent = pending.pop();
      for (const name of readdirSync(join(checkout, parent))) {
        const relative = `${parent}/${name}`;
        const path = join(checkout, relative);
        canonicalPath(path);
        const stat = lstatSync(path);
        requireSafe(++count <= 100000);
        if (stat.isDirectory()) pending.push(relative);
        else requireSafe(stat.isFile() && tracked.has(relative));
      }
    }
  }
  return { checkout, sha: expectedSha, trackedFiles: tracked.size, schemaHash: schemaHash(checkout) };
}

function migrationManifest(checkout) {
  const paths = readdirSync(join(checkout, 'database/migrations'));
  return paths.filter(name => name.endsWith('.php')).sort().map(name => ({ name: name.slice(0, -4), sha256: hash(readFileSync(join(checkout, 'database/migrations', name))) }));
}

function forwardMigrations(sourceCheckout, checkout) {
  const previous = migrationManifest(sourceCheckout);
  const current = migrationManifest(checkout);
  requireSafe(current.length >= previous.length && previous.every((entry, index) => entry.name === current[index]?.name && entry.sha256 === current[index]?.sha256));
  return { previous, pending: current.slice(previous.length) };
}

async function inventory(directory, maximumBytes) {
  const files = [];
  const directories = [];
  const pending = [''];
  let totalBytes = 0;
  let count = 0;
  while (pending.length > 0) {
    const parent = pending.pop();
    for (const name of (await readdir(join(directory, parent))).sort()) {
      const relative = parent ? `${parent}/${name}` : name;
      const path = join(directory, relative);
      canonicalPath(path);
      const stat = lstatSync(path);
      requireSafe(++count <= 100000 && owned(stat) && !stat.isSymbolicLink());
      if (stat.isDirectory()) {
        requireSafe((stat.mode & 0o7777) === 0o700);
        directories.push(relative); pending.push(relative);
      } else {
        requireSafe(stat.isFile() && stat.nlink === 1 && (stat.mode & 0o7077) === 0 && (stat.mode & 0o400) !== 0);
        totalBytes += stat.size; requireSafe(Number.isSafeInteger(totalBytes) && totalBytes <= maximumBytes);
        files.push({ path: relative, bytes: stat.size, sha256: await fileHash(path), dev: String(stat.dev), ino: String(stat.ino) });
      }
    }
  }
  return { directories: directories.sort(), files: files.sort((a, b) => a.path.localeCompare(b.path, 'en')), totalBytes };
}

async function fileHash(path) {
  const handle = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    const before = await handle.stat();
    requireSafe(before.isFile() && owned(before) && before.nlink === 1);
    const digest = createHash('sha256'); const buffer = Buffer.alloc(1024 ** 2);
    let position = 0;
    for (;;) {
      const { bytesRead } = await handle.read(buffer, 0, buffer.length, position);
      if (bytesRead === 0) break;
      digest.update(buffer.subarray(0, bytesRead)); position += bytesRead;
    }
    const after = await handle.stat(); const named = lstatSync(path);
    for (const key of ['dev', 'ino', 'size', 'mtimeMs', 'ctimeMs', 'mode', 'nlink']) requireSafe(before[key] === after[key] && after[key] === named[key]);
    requireSafe(position === before.size);
    return digest.digest('hex');
  } finally { await handle.close(); }
}

async function copyFile(source, target, expected, signal) {
  const input = await open(source, constants.O_RDONLY | constants.O_NOFOLLOW);
  let output;
  try {
    const stat = await input.stat();
    requireSafe(String(stat.dev) === expected.dev && String(stat.ino) === expected.ino && stat.size === expected.bytes && stat.nlink === 1 && owned(stat));
    output = await open(target, constants.O_WRONLY | constants.O_CREAT | constants.O_EXCL | constants.O_NOFOLLOW, 0o600);
    const digest = createHash('sha256'); const buffer = Buffer.alloc(1024 ** 2);
    let position = 0;
    for (;;) {
      requireSafe(!signal?.aborted);
      const { bytesRead } = await input.read(buffer, 0, buffer.length, position);
      if (bytesRead === 0) break;
      digest.update(buffer.subarray(0, bytesRead));
      let written = 0;
      while (written < bytesRead) written += (await output.write(buffer, written, bytesRead - written, position + written)).bytesWritten;
      position += bytesRead;
    }
    requireSafe(position === expected.bytes && digest.digest('hex') === expected.sha256);
    await output.sync();
  } finally { await output?.close(); await input.close(); }
}

async function stopChild(child) {
  if (!child?.pid || child.exitCode !== null || child.signalCode !== null) return;
  await new Promise(resolveStop => {
    const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
    child.once('exit', () => { clearTimeout(timer); resolveStop(); }); child.kill('SIGTERM');
  });
}

async function sourceLease(workspace, checkout, identityHash) {
  const env = isolatedEnvironment(workspace);
  env.VASEY_UPGRADE_SOURCE_IDENTITY_SHA256 = identityHash;
  env.VASEY_UPGRADE_OPERATION = randomBytes(32).toString('hex');
  const child = spawn('php', ['scripts/ops/persistent-content-upgrade.php', 'source-lease'], { cwd: checkout, env, stdio: ['pipe', 'pipe', 'ignore'] });
  try {
    await new Promise((resolveLease, reject) => {
      let received = '';
      const timer = setTimeout(() => reject(new Error(failure)), 10000);
      const finish = error => { clearTimeout(timer); error ? reject(error) : resolveLease(); };
      child.once('error', () => finish(new Error(failure))); child.once('exit', () => finish(new Error(failure)));
      child.stdout.on('data', data => { received += data; if (received === `SOURCE-LEASED ${env.VASEY_UPGRADE_OPERATION}\n`) finish(); });
    });
    return { child, async release() { child.stdin.end(); await stopChild(child); } };
  } catch (error) { await stopChild(child); throw error; }
}

async function operation(workspace, env, command, signal) {
  requireSafe(!signal?.aborted);
  const child = spawn('php', ['scripts/ops/persistent-content-upgrade.php', command], { cwd: workspace.checkout, env, stdio: 'ignore' });
  const abort = () => { stopChild(child).catch(() => {}); };
  signal?.addEventListener('abort', abort, { once: true });
  const timer = setTimeout(abort, 60000);
  try {
    const code = await new Promise((resolveExit, reject) => { child.once('error', () => reject(new Error(failure))); child.once('exit', resolveExit); });
    requireSafe(code === 0 && !signal?.aborted);
  } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); await stopChild(child); }
}

function keepRuntime(path) {
  return path === 'database.sqlite' || path === 'database.sqlite-wal' || path === 'database.sqlite-shm'
    || path === 'app' || path.startsWith('app/') || path === 'framework/sessions' || path.startsWith('framework/sessions/') || path === 'logs' || path.startsWith('logs/');
}

export async function upgradeWorkspace({ sourceCheckout, sourceDirectory, directory, expectedSourceSha, expectedTargetSha, maximumBytes = defaultMaximumBytes, checkout = root, output = process.stdout, signal } = {}) {
  requireSafe(Number.isSafeInteger(maximumBytes) && maximumBytes > 0 && maximumBytes <= 1024 ** 4);
  const sourceRelease = reviewedCheckout(sourceCheckout, expectedSourceSha);
  const targetRelease = reviewedCheckout(checkout, expectedTargetSha);
  const migrations = forwardMigrations(sourceCheckout, checkout);
  const source = readWorkspace(sourceDirectory, sourceCheckout);
  canonicalPath(directory, { missingLeaf: true }); safeAncestors(dirname(directory));
  requireSafe(!(() => { try { lstatSync(directory); return true; } catch { return false; } })());
  for (const path of [sourceDirectory, sourceCheckout, checkout]) requireSafe(directory !== path && !directory.startsWith(`${path}${sep}`) && !path.startsWith(`${directory}${sep}`));
  requireSafe(!sourceDirectory.startsWith(`${checkout}${sep}`) && !checkout.startsWith(`${sourceDirectory}${sep}`));
  await requireFreePort();
  const sourceIdentityHash = hash(readFileSync(join(sourceDirectory, 'identity.json')));
  const heldSource = await sourceLease(source, checkout, sourceIdentityHash);
  let heldTarget;
  try {
    requireSafe(heldSource.child.exitCode === null && heldSource.child.signalCode === null);
    const baseline = await inventory(sourceDirectory, maximumBytes);
    await mkdir(directory, { mode: 0o700 });
    for (const path of runtimeDirectories) await mkdir(join(directory, path), { recursive: true, mode: 0o700 });
    const stat = statSync(directory);
    const identity = { ...source.identity, directory, directory_identity: { dev: String(stat.dev), ino: String(stat.ino) }, checkout: realpathSync(checkout), schema_hash: targetRelease.schemaHash, state: 'initializing' };
    await writeFile(join(directory, 'identity.json'), JSON.stringify(identity), { mode: 0o600, flag: 'wx' });
    await writeFile(join(directory, 'lease'), '', { mode: 0o600, flag: 'wx' });
    for (const path of baseline.directories.filter(keepRuntime)) await mkdir(join(directory, path), { recursive: true, mode: 0o700 });
    for (const file of baseline.files.filter(file => keepRuntime(file.path))) await copyFile(join(sourceDirectory, file.path), join(directory, file.path), file, signal);
    const copiedSource = await inventory(sourceDirectory, maximumBytes);
    requireSafe(JSON.stringify(copiedSource) === JSON.stringify(baseline), 'Source capture must not change even WAL/SHM/lease bytes.');
    const target = readWorkspace(directory, checkout, { allowInitializing: true });
    const env = isolatedEnvironment(target);
    heldTarget = await acquireLease(target, env);
    const plan = {
      schema_version: 1, operation_id: randomBytes(32).toString('hex'), source_sha: sourceRelease.sha, target_sha: targetRelease.sha,
      source_identity_sha256: sourceIdentityHash, target_initial_identity_sha256: hash(readFileSync(join(directory, 'identity.json'))),
      source_schema_hash: sourceRelease.schemaHash, target_schema_hash: targetRelease.schemaHash,
      applied_migrations: migrations.previous, pending_migrations: migrations.pending,
      retained_files: baseline.files.filter(file => file.path === 'app' || file.path.startsWith('app/') || file.path.startsWith('framework/sessions/') || file.path.startsWith('logs/')).map(({ path, bytes, sha256 }) => ({ path, bytes, sha256 })),
      raw_database_capture: baseline.files.filter(file => /^database\.sqlite(?:-wal|-shm)?$/.test(file.path)).map(({ path, bytes, sha256 }) => ({ path, bytes, sha256 })),
    };
    await writeFile(join(directory, 'upgrade-plan.json'), JSON.stringify(plan), { mode: 0o600, flag: 'wx' });
    env.VASEY_UPGRADE_PLAN_SHA256 = hash(JSON.stringify(plan));
    env.VASEY_UPGRADE_OPERATION = plan.operation_id;
    await operation(target, env, 'snapshot', signal);
    output.write('Stopped private database and files captured. Applying reviewed forward migrations to the new copy.\n');
    await operation(target, env, 'apply', signal);
    requireSafe(heldSource.child.exitCode === null && heldSource.child.signalCode === null && heldTarget.child.exitCode === null && heldTarget.child.signalCode === null);
    const finalSource = await inventory(sourceDirectory, maximumBytes);
    requireSafe(JSON.stringify(finalSource) === JSON.stringify(baseline));
    for (const file of plan.retained_files) requireSafe(await fileHash(join(directory, file.path)) === file.sha256);
    requireSafe(hash(readFileSync(join(sourceDirectory, 'identity.json'))) === sourceIdentityHash);
    const readyIdentity = { ...identity, state: 'ready' };
    const proof = {
      schema_version: 1, scope: 'stopped_private_sqlite_copy_upgrade', source_sha: sourceRelease.sha, target_sha: targetRelease.sha,
      source_identity_sha256: sourceIdentityHash, target_identity_sha256: hash(JSON.stringify(readyIdentity)),
      source_database_capture: plan.raw_database_capture, retained_file_count: plan.retained_files.length,
      pending_migrations: plan.pending_migrations.map(entry => entry.name), original_workspace_unchanged: true,
      old_cell_and_audit_values_preserved: true, business_sequences_preserved: true,
      baseline_sha256: await fileHash(join(directory, 'upgrade-baseline.json')), result_sha256: await fileHash(join(directory, 'upgrade-result.json')),
      production_backup_or_restore: false,
    };
    const signed = { proof, hmac_sha256: createHmac('sha256', Buffer.from(identity.app_key.slice(7), 'base64')).update(JSON.stringify(proof)).digest('hex') };
    await writeFile(join(directory, 'upgrade-provenance.json'), JSON.stringify(signed), { mode: 0o600, flag: 'wx' });
    requireSafe(!signal?.aborted);
    await writeFile(join(directory, 'identity.json'), JSON.stringify(readyIdentity), { mode: 0o600 });
    readWorkspace(directory, checkout);
    output.write('Private copy upgrade verified. The new workspace is ready; the original workspace and key remain unchanged. No production backup or restore was performed.\n');
    return proof;
  } catch { throw new Error(failure); }
  finally { await heldTarget?.release(); await heldSource.release(); }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const args = process.argv.slice(2); const values = {};
  const allowed = ['--source-checkout', '--source-directory', '--directory', '--expected-source-sha', '--expected-target-sha', '--max-bytes'];
  try {
    requireSafe(args.length % 2 === 0);
    for (let index = 0; index < args.length; index += 2) { requireSafe(allowed.includes(args[index]) && !(args[index] in values)); values[args[index]] = args[index + 1]; }
    requireSafe(allowed.slice(0, 5).every(key => typeof values[key] === 'string'));
    const controller = new AbortController(); const stop = () => controller.abort();
    process.once('SIGINT', stop); process.once('SIGTERM', stop);
    try { await upgradeWorkspace({ sourceCheckout: values['--source-checkout'], sourceDirectory: values['--source-directory'], directory: values['--directory'], expectedSourceSha: values['--expected-source-sha'], expectedTargetSha: values['--expected-target-sha'], maximumBytes: values['--max-bytes'] === undefined ? defaultMaximumBytes : Number(values['--max-bytes']), signal: controller.signal }); }
    finally { process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop); }
  } catch { console.error(failure); process.exitCode = 1; }
}
