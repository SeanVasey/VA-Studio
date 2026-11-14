import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const checkout = process.argv[2];
const { createWorkspace, readWorkspace, schemaHash } = await import(pathToFileURL(join(checkout, 'scripts/dev/persistent-content.mjs')));
const prior = execFileSync('git', ['show', '0b4a2e76fd2ffc414863a1ba97b9354c9ec4cde8:composer.lock'], { cwd: checkout });
const candidate = readFileSync(join(checkout, 'composer.lock'));
assert.notDeepEqual(prior, candidate);
const base = mkdtempSync(join(tmpdir(), 'dependency-lock-identity-'));
const release = join(base, 'release');
const hash = path => createHash('sha256').update(readFileSync(path)).digest('hex');
try {
  mkdirSync(release, { mode: 0o700 });
  for (const path of ['database', 'public/build', 'vendor']) mkdirSync(join(release, path), { recursive: true, mode: 0o700 });
  cpSync(join(checkout, 'database/migrations'), join(release, 'database/migrations'), { recursive: true });
  cpSync(join(checkout, 'public/build/manifest.json'), join(release, 'public/build/manifest.json'));
  cpSync(join(checkout, 'vendor/autoload.php'), join(release, 'vendor/autoload.php'));
  writeFileSync(join(release, 'composer.lock'), prior);
  const directory = join(base, 'initializing');
  createWorkspace(directory, release);
  readWorkspace(directory, release, { allowInitializing: true });
  const beforeSchema = schemaHash(release);
  const before = ['identity.json', 'database.sqlite'].map(name => hash(join(directory, name)));
  writeFileSync(join(release, 'composer.lock'), candidate);
  assert.notEqual(schemaHash(release), beforeSchema);
  assert.throws(() => readWorkspace(directory, release, { allowInitializing: true }), /Persistent workspace safety checks failed/);
  assert.deepEqual(['identity.json', 'database.sqlite'].map(name => hash(join(directory, name))), before);
  console.log('PASS: actual prior/candidate Composer lock bytes alone change schema identity; an existing initializing workspace is refused without changing identity/database bytes. No PHP execution, prior-runtime installation, completed ready-installation interoperability or host result is claimed by this probe.');
} finally {
  rmSync(base, { recursive: true, force: true });
}
