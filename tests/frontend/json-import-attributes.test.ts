import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

// Playwright loads the TypeScript helpers that browser specs import through Node's native ESM loader, which refuses a bare
// JSON import ("needs an import attribute of type: json") and then reports "no tests". Vite and tsc accept the attribute.
function sources(directory: string): string[] {
  return readdirSync(directory).flatMap(name => {
    const path = join(directory, name);
    return statSync(path).isDirectory() ? sources(path) : /\.(ts|tsx)$/.test(name) ? [path] : [];
  });
}

describe('JSON imports under resources/js', () => {
  it('declare the json import attribute so browser specs can load shared modules', () => {
    const bare = sources('resources/js').flatMap(path => readFileSync(path, 'utf8').split('\n')
      .filter(line => /^\s*import\s[^;]*from\s+['"][^'"]+\.json['"]/.test(line) && !/with\s*\{\s*type:\s*'json'\s*\}/.test(line))
      .map(line => `${path}: ${line.trim()}`));
    expect(bare).toEqual([]);
  });
});
