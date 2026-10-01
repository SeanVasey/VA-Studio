import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';

// Real Blade/CSS and native audio/keyboard in a foreign-origin iframe; synthetic audio transport.
// Backend feature tests separately establish publication, file integrity and withdrawal behavior.
test('public preview iframe has native controls, no autoplay and a keyboard-accessible store exit', async ({ page, context, browserName }, testInfo) => {
  const rendered = spawnSync('php', ['tests/browser/render-public-embed.php'], { encoding: 'utf8', timeout: 30_000 });
  expect(rendered.status, rendered.stderr).toBe(0);
  const unavailable = await page.request.get('/embed/tracks/synthetic-browser-track');
  expect(unavailable.status()).toBe(404);
  const headers = unavailable.headers();
  const embed = 'http://127.0.0.1:8173/embed/tracks/synthetic-browser-track';
  const fonts: { url: string; status: number }[] = [];
  page.on('response', response => {
    if (response.request().resourceType() === 'font') fonts.push({ url: response.url(), status: response.status() });
  });
  await context.addInitScript(() => {
    const target = window as unknown as Window & { __embedCspViolations: string[] };
    target.__embedCspViolations = [];
    document.addEventListener('securitypolicyviolation', event => {
      target.__embedCspViolations.push(event.effectiveDirective + ' ' + event.blockedURI);
    });
  });
  await context.route(embed, route => route.fulfill({ status: 200, body: rendered.stdout, headers: {
    'Content-Type': 'text/html; charset=UTF-8', 'Content-Security-Policy': headers['content-security-policy'],
    'Permissions-Policy': headers['permissions-policy'], 'Referrer-Policy': 'no-referrer', 'Cache-Control': 'no-store',
  } }));
  const wav = Buffer.alloc(44 + 12 * 8000 * 2);
  wav.write('RIFF', 0); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
  wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
  wav.writeUInt32LE(8000, 24); wav.writeUInt32LE(16000, 28); wav.writeUInt16LE(2, 32);
  wav.writeUInt16LE(16, 34); wav.write('data', 36); wav.writeUInt32LE(wav.length - 44, 40);
  for (let sample = 0; sample < (wav.length - 44) / 2; sample++) wav.writeInt16LE(Math.round(1000 * Math.sin(sample * 2 * Math.PI * 220 / 8000)), 44 + sample * 2);
  const requests: string[] = [];
  await context.route(embed + '/preview/7001', route => {
    requests.push(route.request().url());
    const range = route.request().headers().range?.match(/^bytes=(\d+)-(\d*)$/);
    const start = range ? Number(range[1]) : 0;
    const end = range?.[2] ? Math.min(Number(range[2]), wav.length - 1) : wav.length - 1;
    return route.fulfill({ status: range ? 206 : 200, body: wav.subarray(start, end + 1), headers: {
      'Content-Type': 'audio/wav', 'Accept-Ranges': 'bytes', 'Cache-Control': 'no-store',
      ...(range ? { 'Content-Range': `bytes ${start}-${end}/${wav.length}` } : {}),
    } });
  });
  await context.route('http://localhost:8173/synthetic-embed-host', route => route.fulfill({ contentType: 'text/html', body:
    `<!doctype html><title>Synthetic embed host</title><meta name="viewport" content="width=device-width,initial-scale=1"><iframe title="VASEY.AUDIO preview" src="${embed}" style="width:100%;height:320px;border:0"></iframe>` }));
  await context.route('http://127.0.0.1:8173/tracks/synthetic-browser-track', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Synthetic store destination</title>' }));
  await page.goto('http://localhost:8173/synthetic-embed-host');
  const frame = page.frameLocator('iframe');
  await expect(frame.getByRole('heading', { name: 'Synthetic browser preview' })).toBeVisible();
  const loadedFonts = await frame.locator('body').evaluate(async element => {
    const fontSet = element.ownerDocument.fonts;
    const requested = [
      { family: 'Reddit Sans', font: '400 16px "Reddit Sans"' },
      { family: 'Noto Sans Display', font: '900 16px "Noto Sans Display"' },
      { family: 'JetBrains Mono', font: '400 16px "JetBrains Mono"' },
      { family: 'Bebas Neue', font: '400 16px "Bebas Neue"' },
    ];
    return Promise.all(requested.map(async ({ family, font }) => {
      const faces = await fontSet.load(font, 'VASEY AUDIO 0123456789');
      return { family, checked: fontSet.check(font, 'VASEY AUDIO 0123456789'),
        faces: faces.map(face => ({ family: face.family.replace(/^(['"])(.*)\1$/, '$2'), status: face.status })) };
    }));
  });
  for (const loaded of loadedFonts) {
    expect(loaded.checked).toBe(true);
    expect(loaded.faces.length).toBeGreaterThan(0);
    expect(loaded.faces.every(face => face.family === loaded.family && face.status === 'loaded')).toBe(true);
  }
  expect(fonts).toHaveLength(4);
  for (const file of ['reddit-sans-latin-wght-normal.woff2', 'noto-sans-display-latin-standard-normal.woff2',
    'jetbrains-mono-latin-wght-normal.woff2', 'bebas-neue-latin-400-normal.woff2']) {
    expect(fonts.filter(font => new URL(font.url).pathname === '/brand/fonts/' + file)).toHaveLength(1);
  }
  for (const font of fonts) {
    expect(new URL(font.url).origin).toBe(new URL(embed).origin);
    expect(new URL(font.url).pathname).toMatch(/\.woff2$/);
    expect(font.status).toBe(200);
  }
  const audio = frame.locator('audio');
  await expect(audio).toHaveAttribute('preload', 'none');
  await expect(audio).not.toHaveAttribute('autoplay');
  expect(await audio.evaluate(element => (element as HTMLAudioElement).paused)).toBe(true);
  // preload="none" is a browser hint; a metadata request does not mean playback began.
  expect(await audio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBe(0);
  await audio.focus();
  await expect(audio).toBeFocused();
  const toggleNativePlayback = async () => {
    if (browserName === 'webkit') {
      // This pinned mobile project's UA controls expose no DOM Play button. Its outer
      // audio focus ignores Space; tap the visible native left play/pause control.
      expect(testInfo.project.use.hasTouch).toBe(true);
      const bounds = await audio.boundingBox();
      expect(bounds).not.toBeNull();
      expect(bounds!.width).toBeGreaterThan(48);
      expect(bounds!.height).toBeGreaterThan(0);
      await audio.tap({ position: { x: 24, y: bounds!.height / 2 } });
    } else {
      await audio.press('Space');
    }
  };
  await toggleNativePlayback();
  await expect.poll(() => requests.length).toBeGreaterThan(0);
  await expect.poll(() => audio.evaluate(element => (element as HTMLAudioElement).paused)).toBe(false);
  await expect.poll(() => audio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBeGreaterThan(0);
  expect(await audio.evaluate(element => element.ownerDocument.documentElement.scrollWidth <= element.ownerDocument.documentElement.clientWidth)).toBe(true);
  await toggleNativePlayback();
  await expect.poll(() => audio.evaluate(element => (element as HTMLAudioElement).paused)).toBe(true);
  const store = frame.getByRole('link', { name: 'Open track on VASEY.AUDIO (new tab)' });
  await store.focus();
  await expect(store).toBeFocused();
  const opened = page.waitForEvent('popup');
  await store.press('Enter');
  const popup = await opened;
  await expect(popup).toHaveURL('http://127.0.0.1:8173/tracks/synthetic-browser-track');
  expect(await popup.evaluate(() => window.opener)).toBeNull();
  const cspViolations = await frame.locator('body').evaluate(element =>
    (element.ownerDocument.defaultView as unknown as Window & { __embedCspViolations: string[] }).__embedCspViolations);
  expect(cspViolations).toEqual([]);
  await testInfo.attach('embed-font-evidence', { contentType: 'application/json', body: Buffer.from(JSON.stringify({ loadedFonts, fonts, cspViolations })) });
  // Capture after all journey assertions so Playwright's injected screenshot style is not attributed to the app.
  await page.screenshot({ path: testInfo.outputPath('public-preview-embed.png'), fullPage: true });
});

test('public preview fonts remain same-origin with development hot and foreign asset configurations', async ({ browser, page }, testInfo) => {
  const embed = 'http://127.0.0.1:8173/embed/tracks/synthetic-browser-track';
  const unavailable = await page.request.get(embed);
  expect(unavailable.status()).toBe(404);
  const headers = unavailable.headers();
  expect(headers['content-security-policy']).toBe("default-src 'none'; style-src 'self'; font-src 'self'; media-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors http: https:");
  const approved = JSON.parse(readFileSync(new URL('../../docs/brand/font-manifest.json', import.meta.url), 'utf8')) as {
    family: string; file: string; sha256: string;
  }[];
  const configurations = [];
  for (const mode of ['hot-server', 'foreign-assets', 'hot-server-and-foreign-assets']) {
    const rendered = spawnSync('php', ['tests/browser/render-public-embed.php', mode], { encoding: 'utf8', timeout: 30_000 });
    expect(rendered.status, rendered.stderr).toBe(0);
    const environment = JSON.parse(rendered.stderr.trim()) as { mode: string; hot: boolean; assetProbe: string };
    expect(environment).toEqual({ mode, hot: mode !== 'foreign-assets',
      assetProbe: (mode === 'hot-server' ? 'http://127.0.0.1:8173' : 'https://assets.example.test') + '/css/track-embed-fonts.css' });
    expect(rendered.stdout).not.toMatch(/localhost:5173|assets\.example\.test|\/build\/|<script|<base/);
    const context = await browser.newContext({ viewport: testInfo.project.use.viewport,
      hasTouch: testInfo.project.use.hasTouch, isMobile: testInfo.project.use.isMobile,
      userAgent: testInfo.project.use.userAgent, deviceScaleFactor: testInfo.project.use.deviceScaleFactor });
    try {
      await context.addInitScript(() => {
        const target = window as unknown as Window & { __embedCspViolations: string[] };
        target.__embedCspViolations = [];
        document.addEventListener('securitypolicyviolation', event => {
          target.__embedCspViolations.push(event.effectiveDirective + ' ' + event.blockedURI);
        });
      });
      await context.route(embed, route => route.fulfill({ status: 200, body: rendered.stdout, headers: {
        'Content-Type': 'text/html; charset=UTF-8', 'Content-Security-Policy': headers['content-security-policy'],
        'Permissions-Policy': headers['permissions-policy'], 'Referrer-Policy': 'no-referrer', 'Cache-Control': 'no-store',
      } }));
      const preview = await context.newPage();
      const pendingFonts: Promise<{ url: string; status: number; sha256: string }>[] = [];
      const styles: { url: string; status: number }[] = [];
      const foreignRequests: string[] = [];
      preview.on('request', request => {
        if (new URL(request.url()).origin !== new URL(embed).origin) foreignRequests.push(request.url());
      });
      preview.on('response', response => {
        if (response.request().resourceType() === 'font') pendingFonts.push(response.body().then(bytes => ({
          url: response.url(), status: response.status(), sha256: createHash('sha256').update(bytes).digest('hex'),
        })));
        if (response.request().resourceType() === 'stylesheet') styles.push({ url: response.url(), status: response.status() });
      });
      await preview.goto(embed);
      await expect(preview.getByRole('heading', { name: 'Synthetic browser preview' })).toBeVisible();
      const loadedFonts = await preview.locator('body').evaluate(async (element, families) => {
        const fontSet = element.ownerDocument.fonts;
        return Promise.all(families.map(async family => {
          const font = (family === 'Noto Sans Display' ? '900' : '400') + ' 16px "' + family + '"';
          const faces = await fontSet.load(font, 'VASEY AUDIO 0123456789');
          return { family, checked: fontSet.check(font, 'VASEY AUDIO 0123456789'),
            faces: faces.map(face => ({ family: face.family.replace(/^(['"])(.*)\1$/, '$2'), status: face.status })) };
        }));
      }, approved.map(font => font.family));
      for (const loaded of loadedFonts) {
        expect(loaded.checked).toBe(true);
        expect(loaded.faces.length).toBeGreaterThan(0);
        expect(loaded.faces.every(face => face.family === loaded.family && face.status === 'loaded')).toBe(true);
      }
      const fonts = await Promise.all(pendingFonts);
      expect(fonts).toHaveLength(4);
      for (const font of approved) {
        expect(fonts.filter(response => response.url === 'http://127.0.0.1:8173/brand/fonts/' + font.file)).toEqual([
          { url: 'http://127.0.0.1:8173/brand/fonts/' + font.file, status: 200, sha256: font.sha256 },
        ]);
      }
      expect(styles.sort((a, b) => a.url.localeCompare(b.url))).toEqual(['/brand/theme.css', '/css/track-embed-fonts.css', '/css/track-embed.css']
        .map(path => ({ url: 'http://127.0.0.1:8173' + path, status: 200 })).sort((a, b) => a.url.localeCompare(b.url)));
      expect(foreignRequests).toEqual([]);
      const cspViolations = await preview.evaluate(() =>
        (window as unknown as Window & { __embedCspViolations: string[] }).__embedCspViolations);
      expect(cspViolations).toEqual([]);
      configurations.push({ environment, loadedFonts, fonts, styles, foreignRequests, cspViolations });
    } finally { await context.close(); }
  }
  await testInfo.attach('embed-font-origin-evidence', { contentType: 'application/json', body: Buffer.from(JSON.stringify({ configurations })) });
});
