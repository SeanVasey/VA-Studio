import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const root = resolve(import.meta.dirname, '../..');
const read = (path: string) => readFileSync(resolve(root, path));
const sha256 = (bytes: Buffer) => createHash('sha256').update(bytes).digest('hex');
const approved = JSON.parse(read('docs/brand/font-manifest.json').toString()) as {
  family: string; file: string; package: string; packageVersion: string; sha256: string;
}[];
const sources = JSON.parse(read('public/brand/fonts/source-manifest.json').toString()) as {
  files: { package: string; version: string; font: string; font_size_bytes: number; font_sha256: string;
    license: string; license_sha256: string; package_url: string; package_integrity: string }[];
};
const lock = JSON.parse(read('package-lock.json').toString()) as {
  packages: Record<string, { version: string; resolved: string; integrity: string }>;
};

describe('standalone preview font assets', () => {
  test.each(approved)('$family retains exact approved and locked package bytes with its license', font => {
    const retained = sources.files.find(source => source.package === font.package)!;
    expect(retained).toBeDefined();
    expect(retained.font).toBe(`public/brand/fonts/${font.file}`);
    expect(retained.font_sha256).toBe(font.sha256);
    const bytes = read(retained.font);
    expect(sha256(bytes)).toBe(font.sha256);
    expect(bytes.length).toBe(retained.font_size_bytes);
    expect(bytes).toEqual(read(`node_modules/${font.package}/files/${font.file}`));
    const dependency = lock.packages[`node_modules/${font.package}`]!;
    expect(retained.version).toBe(font.packageVersion);
    expect(retained.version).toBe(dependency.version);
    expect(retained.package_url).toBe(dependency.resolved);
    expect(retained.package_integrity).toBe(dependency.integrity);
    const license = read(retained.license);
    expect(sha256(license)).toBe(retained.license_sha256);
    expect(license).toEqual(read(`node_modules/${font.package}/LICENSE`));
    expect(license.toString()).toContain('SIL OPEN FONT LICENSE Version 1.1');
  });

  test('all standalone font URLs are root-relative and independent of Vite or configured asset hosts', () => {
    const css = read('public/css/track-embed-fonts.css').toString();
    expect(sources.files.map(source => source.package).sort()).toEqual(approved.map(font => font.package).sort());
    const urls = Array.from(css.matchAll(/url\('([^']+)'\)/g), match => match[1]);
    expect(urls.sort()).toEqual(approved.map(font => `/brand/fonts/${font.file}`).sort());
    expect(css.match(/@font-face\b/g)).toHaveLength(4);
    expect(css.match(/font-display: swap/g)).toHaveLength(4);
    expect(css).toContain('font-weight: 200 900');
    expect(css).toContain('font-weight: 100 900; font-stretch: 62.5% 100%');
    expect(css).toContain('font-weight: 100 800');
    expect(css).not.toMatch(/@import|https?:|data:|\/build\//);
    const html = read('resources/views/public-track-embed.blade.php').toString();
    expect(html).toContain('href="/css/track-embed-fonts.css"');
    expect(html).not.toMatch(/@vite|asset\(|<script|<base/);
  });
});
