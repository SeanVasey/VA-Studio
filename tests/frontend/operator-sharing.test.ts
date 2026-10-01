import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, it } from 'vitest';

it('passes the exact standalone Alpine sharing-state behavioral suite', () => {
  const result = execFileSync(process.execPath, [
    '--test',
    '--test-reporter=tap',
    fileURLToPath(new URL('./operator-sharing.test.mjs', import.meta.url)),
  ], { encoding: 'utf8', timeout: 10_000 });
  expect(result).toContain('# fail 0');
});
