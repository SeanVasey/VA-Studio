import { spawnSync } from 'node:child_process';
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
  await page.screenshot({ path: testInfo.outputPath('public-preview-embed.png'), fullPage: true });
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
});
