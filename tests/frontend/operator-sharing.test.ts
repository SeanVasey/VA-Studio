import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, it } from 'vitest';

it('passes the exact standalone Alpine sharing-state behavioral suite', () => {
  const result = execFileSync(process.execPath, [
    '--test',
    '--test-reporter=tap',
    resolve(process.cwd(), 'tests/frontend/operator-sharing.test.mjs'),
  ], { encoding: 'utf8', timeout: 10_000 });
  expect(result).toContain('# fail 0');
});
