import { execFileSync, spawn } from 'node:child_process';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { acquireLease, canonicalPath, isolatedEnvironment, readWorkspace, requireFreePort, root } from '../dev/persistent-content.mjs';
import { reviewedCheckout } from '../ops/persistent-content-upgrade.mjs';

const refusal = 'Private catalog operation refused or failed. Retained source, review and workspace files were not reset or removed.';
function requireSafe(condition) { if (!condition) throw new Error(refusal); }

export function releaseProof(checkout, expectedSha) {
  const release = reviewedCheckout(checkout, expectedSha);
  const tree = execFileSync('git', ['--no-replace-objects', 'rev-parse', 'HEAD^{tree}'], {
    cwd: checkout, encoding: 'utf8', maxBuffer: 4096, stdio: ['ignore', 'pipe', 'ignore'],
    env: { PATH: process.env.PATH, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: '/dev/null', GIT_NO_REPLACE_OBJECTS: '1' },
  }).trim();
  requireSafe(/^[a-f0-9]{40}$/.test(tree));
  return { commit: release.sha, tree, schema_hash: release.schemaHash };
}

export function parseArguments(args) {
  const command = args[0];
  requireSafe(['review', 'apply', 'verify-release'].includes(command) && (args.length - 1) % 2 === 0);
  const options = {};
  for (let index = 1; index < args.length; index += 2) {
    const name = args[index];
    requireSafe(name.startsWith('--') && !(name in options) && typeof args[index + 1] === 'string');
    options[name] = args[index + 1];
  }
  const required = command === 'verify-release' ? ['--expected-target-sha', '--expected-tree', '--expected-schema']
    : ['--directory', '--source-directory', '--report', '--actor-id', '--expected-target-sha'];
  const permitted = command === 'apply' ? [...required, '--expected-review-sha256', '--limit'] : required;
  requireSafe(Object.keys(options).sort().join(',') === [...permitted].sort().join(',')
    && /^[a-f0-9]{40}$/.test(options['--expected-target-sha']));
  if (command === 'verify-release') {
    requireSafe(/^[a-f0-9]{40}$/.test(options['--expected-tree']) && /^[a-f0-9]{64}$/.test(options['--expected-schema']));
  } else {
    requireSafe(/^[1-9][0-9]{0,14}$/.test(options['--actor-id']) && Number.isSafeInteger(Number(options['--actor-id'])));
    for (const name of ['--directory', '--source-directory']) canonicalPath(options[name]);
    canonicalPath(dirname(options['--report']));
    requireSafe(options['--report'].startsWith('/') && join(dirname(options['--report']), options['--report'].split('/').at(-1)) === options['--report']);
    if (command === 'apply') {
      requireSafe(/^[a-f0-9]{64}$/.test(options['--expected-review-sha256']) && /^(?:[1-9]|1[0-9]|2[0-5])$/.test(options['--limit']));
    }
  }
  return { command, options };
}

async function stopChild(child) {
  if (!child?.pid || child.exitCode !== null || child.signalCode !== null) return;
  await new Promise(resolveStop => {
    const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
    child.once('exit', () => { clearTimeout(timer); resolveStop(); });
    child.kill('SIGTERM');
  });
}

export async function catalogOperation({ command, directory, sourceDirectory, report, actorId, expectedTargetSha, expectedReviewSha256 = '-', limit = 1, checkout = root, signal } = {}) {
  requireSafe(['review', 'apply'].includes(command));
  const release = releaseProof(checkout, expectedTargetSha);
  const workspace = readWorkspace(directory, checkout);
  await requireFreePort();
  const env = isolatedEnvironment(workspace);
  Object.assign(env, { VASEY_CATALOG_TARGET_COMMIT: release.commit, VASEY_CATALOG_TARGET_TREE: release.tree,
    VASEY_CATALOG_TARGET_SCHEMA: release.schema_hash });
  const lease = await acquireLease(workspace, env);
  let child;
  try {
    requireSafe(!signal?.aborted);
    child = spawn('php', ['scripts/migration/persistent-catalog.php', command, sourceDirectory, report, String(actorId), expectedReviewSha256, String(limit)],
      { cwd: checkout, env, stdio: 'inherit' });
    const abort = () => child.kill('SIGTERM');
    signal?.addEventListener('abort', abort, { once: true });
    const timer = setTimeout(abort, 120000);
    try {
      const code = await new Promise((resolveExit, reject) => {
        child.once('error', () => reject(new Error(refusal)));
        child.once('exit', resolveExit);
        if (signal?.aborted) abort();
      });
      requireSafe(code === 0 && !signal?.aborted);
      requireSafe(JSON.stringify(releaseProof(checkout, expectedTargetSha)) === JSON.stringify(release));
    } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); }
  } finally {
    // Keep the installation's OS lease until the owned worker has actually stopped.
    await stopChild(child);
    await lease.release();
  }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const { command, options } = parseArguments(process.argv.slice(2));
    if (command === 'verify-release') {
      const release = releaseProof(root, options['--expected-target-sha']);
      requireSafe(release.tree === options['--expected-tree'] && release.schema_hash === options['--expected-schema']);
    } else {
      const controller = new AbortController();
      const stop = () => controller.abort();
      process.once('SIGINT', stop); process.once('SIGTERM', stop);
      try {
        await catalogOperation({ command, directory: options['--directory'], sourceDirectory: options['--source-directory'], report: options['--report'],
          actorId: options['--actor-id'], expectedTargetSha: options['--expected-target-sha'], expectedReviewSha256: options['--expected-review-sha256'] ?? '-',
          limit: Number(options['--limit'] ?? 1), signal: controller.signal });
      } finally { process.removeListener('SIGINT', stop); process.removeListener('SIGTERM', stop); }
    }
  } catch {
    process.stderr.write(`${refusal}\n`);
    process.exitCode = 1;
  }
}
