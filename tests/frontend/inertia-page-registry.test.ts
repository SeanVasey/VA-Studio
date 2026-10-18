import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

/**
 * Every page name the server renders must resolve in the browser. A page that the server renders but
 * resources/js/app.tsx does not resolve throws "Unknown page" as soon as its route is mounted.
 */
const root = resolve(import.meta.dirname, '../..');
const read = (path: string) => readFileSync(resolve(root, path), 'utf8');
const php = (directory: string) => (readdirSync(resolve(root, directory), { recursive: true }) as string[])
  .filter(name => name.endsWith('.php')).map(name => join(directory, name)).sort();

function serverPages() {
  const names = new Set<string>(), dynamic: string[] = [];
  for (const file of [...php('app'), ...php('routes')]) {
    const source = read(file);
    const calls = source.match(/\bInertia::render\s*\(|\bRoute::inertia\s*\(|(?<![\w:>$])inertia\s*\(/g) ?? [];
    const literal = [...source.matchAll(/\bInertia::render\s*\(\s*(['"])([A-Za-z0-9_]+)\1|\bRoute::inertia\s*\(\s*(['"])[^'"]*\3\s*,\s*(['"])([A-Za-z0-9_]+)\4|(?<![\w:>$])inertia\s*\(\s*(['"])([A-Za-z0-9_]+)\6/g)];
    literal.forEach(match => names.add(match[2] ?? match[5] ?? match[7]));
    // A computed page name cannot be checked statically, so it is refused rather than silently skipped.
    if (calls.length !== literal.length) dynamic.push(file);
  }
  return { names: [...names].sort(), dynamic };
}

function resolvedPages() {
  const app = read('resources/js/app.tsx');
  const imports = new Map([...app.matchAll(/^import (\w+) from '\.\/Pages\/(\w+)';$/gm)].map(match => [match[1], match[2]]));
  const branches = [...app.matchAll(/if \(name === '(\w+)'\) return ([^;]+);/g)];
  return branches.map(([, name, target]) => {
    const lazy = target.match(/^import\('\.\/Pages\/(\w+)'\)\.then\(page => page\.default\)$/);
    return { name, file: lazy ? lazy[1] : imports.get(target.trim()) ?? null };
  });
}

describe('Inertia page registry', () => {
  test('every server-rendered page has a literal name', () => {
    const { names, dynamic } = serverPages();
    expect(dynamic).toEqual([]);
    expect(names.length).toBeGreaterThan(0);
  });

  test('every server-rendered page resolves in app.tsx to its own Pages file', () => {
    const resolved = resolvedPages();
    const missing = serverPages().names.filter(name => !resolved.some(branch => branch.name === name));
    expect(missing, 'pages rendered by the server but not resolved in resources/js/app.tsx').toEqual([]);
    for (const name of serverPages().names) {
      expect(existsSync(resolve(root, `resources/js/Pages/${name}.tsx`)), `resources/js/Pages/${name}.tsx`).toBe(true);
    }
  });

  test('each resolve branch loads the Pages file of the same name, once', () => {
    const resolved = resolvedPages();
    expect(resolved.length).toBeGreaterThan(0);
    expect(new Set(resolved.map(branch => branch.name)).size).toBe(resolved.length);
    for (const branch of resolved) {
      expect(branch.file, `resolve('${branch.name}')`).toBe(branch.name);
      expect(existsSync(resolve(root, `resources/js/Pages/${branch.name}.tsx`)), `resources/js/Pages/${branch.name}.tsx`).toBe(true);
    }
  });
});
