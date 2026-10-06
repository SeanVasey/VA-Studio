import { createHash, randomUUID } from 'node:crypto';
import { lstatSync, readFileSync, readdirSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Test-runner-only backport of Microsoft's exact two-line WKPage initialization fix.
// https://github.com/microsoft/playwright/pull/42894 (merged 2026-10-05)
// A dependency update must remove/reassess this guard rather than silently patch new SDK bytes.
export const BACKPORT = Object.freeze({
  version: '1.63.0',
  upstreamCommit: 'ea75b9f32ca4120efdfaf8f5b8cfd292f548f197',
  bundle: 'node_modules/playwright-core/lib/coreBundle.js',
  originalSha256: '549070af3acabb3efcc4f55bfe6210f9f7c2fcf633cf7eaa59bfe60719969171',
  patchedSha256: '4f5647c5a1fa104ff4536ec5923353d2a034d6d2f60050ed53b7633c3dadd67b',
  originalBytes: 3477183,
  patchedBytes: 3477215,
});

export const REFUSAL = 'Pinned Playwright WebKit backport refused; install the exact lockfile SDK inside this checkout.';
const before = Buffer.from('if (contextOptions.offline)\n          promises2.push(session2.send("Network.setEmulateOfflineState", { offline: true }));');
const after = Buffer.from('if (contextOptions.offline !== undefined)\n          promises2.push(session2.send("Network.setEmulateOfflineState", { offline: contextOptions.offline }));');
const packages = ['@playwright/test', 'playwright', 'playwright-core'];
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const reject = () => { throw new Error(REFUSAL); };

function owned(root, relative, directory = false) {
  const path = join(root, relative);
  if (realpathSync(path) !== path) reject();
  const stat = lstatSync(path);
  if (directory ? !stat.isDirectory() : !stat.isFile()) reject();
  return path;
}

function ownedTree(path) {
  // npm's pinned SDK has ordinary directories/files. Refuse every SDK symlink,
  // including a shared node_modules/package/CLI/bundle outside this checkout.
  for (const entry of readdirSync(path, { withFileTypes: true })) {
    if (entry.isDirectory()) ownedTree(join(path, entry.name));
    else if (!entry.isFile()) reject();
  }
}

/** Quiet when imported by the native wrappers; fixtures may supply a standalone copied checkout. */
export function applyPinnedWebkitOfflineBackport(checkout) {
  let temporary;
  try {
    const root = realpathSync(resolve(checkout));
    const project = JSON.parse(readFileSync(owned(root, 'package.json'), 'utf8'));
    const lock = JSON.parse(readFileSync(owned(root, 'package-lock.json'), 'utf8'));
    if (project.name !== 'vaseyaudio' || project.private !== true
        || project.devDependencies?.['@playwright/test'] !== BACKPORT.version
        || lock.lockfileVersion !== 3 || lock.name !== project.name
        || lock.packages?.['']?.devDependencies?.['@playwright/test'] !== BACKPORT.version
        || lock.packages?.['node_modules/@playwright/test']?.dependencies?.playwright !== BACKPORT.version
        || lock.packages?.['node_modules/playwright']?.dependencies?.['playwright-core'] !== BACKPORT.version) reject();
    for (const name of packages) {
      const directory = owned(root, `node_modules/${name}`, true);
      ownedTree(directory);
      const installed = JSON.parse(readFileSync(owned(root, `node_modules/${name}/package.json`), 'utf8'));
      if (installed.name !== name || installed.version !== BACKPORT.version
          || lock.packages?.[`node_modules/${name}`]?.version !== BACKPORT.version) reject();
    }
    const bundle = owned(root, BACKPORT.bundle);
    const original = readFileSync(bundle);
    const digest = hash(original);
    if (digest === BACKPORT.patchedSha256 && original.length === BACKPORT.patchedBytes) {
      return { status: 'already_applied', version: BACKPORT.version, sha256: digest, upstreamCommit: BACKPORT.upstreamCommit };
    }
    if (digest !== BACKPORT.originalSha256 || original.length !== BACKPORT.originalBytes) reject();
    const offset = original.indexOf(before);
    if (offset < 0 || original.indexOf(before, offset + 1) !== -1 || original.includes(after)) reject();
    const patched = Buffer.concat([original.subarray(0, offset), after, original.subarray(offset + before.length)]);
    if (patched.length !== BACKPORT.patchedBytes || hash(patched) !== BACKPORT.patchedSha256) reject();
    // Replace this checkout's file atomically; shared hard-linked originals are never modified in place.
    temporary = `${bundle}.backport-${randomUUID()}`;
    writeFileSync(temporary, patched, { flag: 'wx', mode: lstatSync(bundle).mode & 0o777 });
    if (owned(root, BACKPORT.bundle) !== bundle || hash(readFileSync(bundle)) !== BACKPORT.originalSha256) reject();
    renameSync(temporary, bundle);
    temporary = undefined;
    if (hash(readFileSync(bundle)) !== BACKPORT.patchedSha256) reject();
    return { status: 'applied', version: BACKPORT.version, sha256: BACKPORT.patchedSha256, upstreamCommit: BACKPORT.upstreamCommit };
  } catch {
    throw new Error(REFUSAL);
  } finally {
    if (temporary) rmSync(temporary, { force: true });
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv.length !== 2) reject();
    console.log(JSON.stringify(applyPinnedWebkitOfflineBackport(resolve(import.meta.dirname, '../..'))));
  } catch {
    console.error(REFUSAL);
    process.exitCode = 1;
  }
}
