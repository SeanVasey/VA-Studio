// Builds the VASEY.AUDIO app icon suite from the owner-supplied official icon SVG.
//
// The mark's path data is read verbatim from assets/brand/vasey-audio/vasey-audio-icon-white.svg and is never
// edited or redrawn. A tile may vary only the background rect and a translate/uniform-scale wrapper (the same
// variation the kit allows). Chromium rasterizes each size directly from the vector, so no size is a resample.
//
// Usage (needs the `playwright` package and a matching Chromium): node scripts/brand/build-app-icons.mjs
// Verify the result with: python3 scripts/ci/test-brand-icons.py
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const brand = join(root, 'public', 'brand');
const source = readFileSync(join(root, 'assets/brand/vasey-audio/vasey-audio-icon-white.svg'), 'utf8');
const path = source.match(/<path fill="#FFFFFF" fill-rule="evenodd" d="([^"]+)"\/>/);
if (!path) throw new Error('Official icon path not found; the kit file changed shape.');

const TILE = '#052E3A'; // docs/brand/README.md deep turquoise canvas; manifest background_color
// Official live area is 768/1024 (75%), bbox-centred on (512, 512). Maskable icons must keep the mark inside the
// central circle of radius 40% of the tile; the official mark's farthest vertex sits at 47.2%, so the maskable tile
// scales the mark uniformly by 0.82 (farthest vertex 38.7%).
const ANY = 1;
const MASKABLE = 0.82;

const tile = (scale, size = 1024) => {
  const wrap = scale === 1 ? '' : ` transform="translate(512 512) scale(${scale}) translate(-512 -512)"`;
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024" width="${size}" height="${size}">`
    + `<rect width="1024" height="1024" fill="${TILE}"/>`
    + `<g${wrap}><path fill="#FFFFFF" fill-rule="evenodd" d="${path[1]}"/></g></svg>`;
};

const jobs = [];
for (const size of [1024, 512, 384, 192, 144, 96]) {
  jobs.push([`app-icon-${size}.png`, size, ANY], [`app-icon-maskable-${size}.png`, size, MASKABLE]);
}
jobs.push(['apple-touch-icon.png', 180, ANY]);
for (const size of [167, 152, 120]) jobs.push([`apple-touch-icon-${size}.png`, size, ANY]);
for (const size of [32, 16]) jobs.push([`favicon-${size}.png`, size, ANY]);

mkdirSync(brand, { recursive: true });
const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 1 });
const rendered = new Map();
for (const [name, size, scale] of jobs) {
  await page.setViewportSize({ width: size, height: size });
  await page.setContent(`<!doctype html><html><body style="margin:0;background:${TILE}">${tile(scale, size)}</body></html>`);
  const png = await page.screenshot({ type: 'png', clip: { x: 0, y: 0, width: size, height: size } });
  writeFileSync(join(brand, name), png);
  rendered.set(name, png);
}
await browser.close();

writeFileSync(join(brand, 'favicon.svg'), tile(ANY, 1024).replace(' width="1024" height="1024"', '') + '\n');

// favicon.ico: PNG-compressed 16 and 32 entries, each the exact render above.
const entries = [16, 32].map(size => ({ size, data: rendered.get(`favicon-${size}.png`) }));
const header = Buffer.alloc(6 + 16 * entries.length);
header.writeUInt16LE(1, 2); header.writeUInt16LE(entries.length, 4);
let offset = header.length;
entries.forEach(({ size, data }, i) => {
  const at = 6 + 16 * i;
  header[at] = size; header[at + 1] = size; header.writeUInt16LE(1, at + 4); header.writeUInt16LE(32, at + 6);
  header.writeUInt32LE(data.length, at + 8); header.writeUInt32LE(offset, at + 12);
  offset += data.length;
});
writeFileSync(join(root, 'public', 'favicon.ico'), Buffer.concat([header, ...entries.map(entry => entry.data)]));
console.log(`wrote ${jobs.length} PNGs, favicon.svg and favicon.ico`);
