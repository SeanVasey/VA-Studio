import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { lstatSync, readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type APIRequestContext, type Browser, type Locator, type Page, type Request, type Response, type TestInfo } from '@playwright/test';
import { resetBrowserLoginRateLimit } from '../browser/auth-fixture';
import { releaseMenuAction, releaseRow } from '../browser/site-release-row';
import { actionResponse, closeDialog, expectModalFits, login, openPublication, publicationReview, row, searchTracks } from '../browser/publication-fixture';
import { syncSuccessNotification } from '../browser/notification-sync';

type Track = { id: number; title: string; artist: string; slug: string; href: string };
type MediaOutput = { id: number; role: string; sha256: string; sizeBytes: number };
type Manifest = {
  schemaVersion: 2; marker: string; origin: string; database: string; projects: Record<string, { tracks: [Track, Track]; publicationTrack: Track }>; evidenceHash: string;
  evidence: { tracks: Array<{ trackId: number; sources: Array<{ outputs: MediaOutput[] }> }> };
};
function loadManifest(): Manifest {
  const directory = process.env.VASEY_BROWSER_DIRECTORY!;
  const manifestPath = join(directory, 'related-track-fixtures.json');
  const stat = lstatSync(manifestPath);
  if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 262144 || (stat.mode & 0o777) !== 0o600 || realpathSync(manifestPath) !== manifestPath) {
    throw new Error('The related-track manifest is not the bounded private isolated fixture.');
  }
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8')) as Manifest;
  if (manifest.schemaVersion !== 2 || manifest.marker !== process.env.VASEY_BROWSER_RELATED_MARKER
    || manifest.origin !== 'http://127.0.0.1:8173' || manifest.database !== join(directory, 'database.sqlite')
    || Object.keys(manifest.projects).join(',') !== 'chromium-desktop,webkit-mobile'
    || manifest.evidenceHash?.length !== 64 || !/^[a-f0-9]{64}$/.test(manifest.evidenceHash)) {
    throw new Error('The related-track manifest identity does not match this selected run.');
  }
  return manifest;
}

test.beforeEach(() => resetBrowserLoginRateLimit());

async function persisted(testInfo: TestInfo, manifest: Manifest, state: 'published' | 'withdrawn', label: string) {
  const output = execFileSync('php', ['tests/browser/prepare-related-tracks.php', 'verify', testInfo.project.name, state], {
    cwd: process.cwd(), env: process.env, timeout: 30000, maxBuffer: 65536, encoding: 'utf8', stdio: 'pipe',
  });
  const result = JSON.parse(output) as { verified: boolean; project: string; state: string; evidenceHash: string };
  expect(result.verified).toBe(true);
  expect(result.project).toBe(testInfo.project.name);
  expect(result.state).toBe(state);
  expect(result.evidenceHash).toBe(manifest.evidenceHash);
  await testInfo.attach(label, { body: Buffer.from(output), contentType: 'application/json' });
}

async function props(request: APIRequestContext, path: string) {
  // Bootstrap this route's exact server version; private panel previews legitimately use an empty version.
  const shell = await request.get(path, { headers: { Accept: 'text/html, application/xhtml+xml' } });
  expect(shell.status()).toBe(200);
  const embedded = (await shell.text()).match(/<script\b[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/);
  expect(embedded).not.toBeNull();
  const bootstrap = JSON.parse(embedded![1]) as { component: string; version: string };
  expect(bootstrap.component).toBe('Editorial');
  expect(typeof bootstrap.version).toBe('string');
  const version = bootstrap.version;
  const inertiaHeaders = { 'X-Inertia': 'true', 'X-Inertia-Version': version, Accept: 'text/html, application/xhtml+xml' };
  const response = await request.get(path, { headers: inertiaHeaders });
  expect(response.status()).toBe(200);
  expect(response.headers()['x-inertia']).toBe('true');
  const payload = await response.json();
  expect(payload.component).toBe('Editorial');
  expect(payload.version).toBe(version);
  return payload.props as { editorial: { relatedTracks?: Array<{ title: string; artist: string; href?: string }> }; sitePreview?: boolean; commerceEnabled?: boolean };
}

async function activate(page: Page, label: string, action: 'Publish release' | 'Restore previous release') {
  const trigger = action === 'Publish release'
    ? releaseRow(page, label).getByRole('button', { name: action, exact: true })
    : await releaseMenuAction(page, label, action);
  await trigger.click();
  const dialog = page.getByRole('alertdialog', { name: action, exact: true });
  await dialog.getByRole('button', { name: 'Confirm', exact: true }).click();
  await expect(releaseRow(page, label).getByText('Active', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('heading')).not.toBeVisible();
}

async function addTrack(group: Locator, track: Track) {
  const before = await group.getByRole('combobox').count();
  await group.getByRole('button', { name: 'Add to related tracks', exact: true }).click();
  await expect(group.getByRole('combobox')).toHaveCount(before + 1);
  const select = group.getByRole('combobox').last();
  await select.focus();
  await select.press('Enter');
  await group.getByRole('textbox', { name: 'Search', exact: true }).fill(track.title.slice(0, 80));
  await group.getByRole('option', { name: `${track.title} · ${track.artist}`, exact: true }).click();
  await expect(select).toContainText(track.title);
}

async function clearTracks(group: Locator) {
  let remaining = await group.getByRole('combobox').count();
  while (remaining > 0) {
    await group.getByRole('button', { name: 'Delete', exact: true }).last().click();
    await expect(group.getByRole('combobox')).toHaveCount(--remaining);
  }
  await expect(group.getByRole('combobox')).toHaveCount(0);
}

async function reviewVerifiedPrivateTrack(page: Page, visitor: APIRequestContext, testInfo: TestInfo, manifest: Manifest, track: Track, browserName: string) {
  // These IDs/hashes come from genuinely scanned ordinary pipeline output, already checked by persisted(), not injected ready records.
  const record = manifest.evidence.tracks.find(record => record.trackId === track.id);
  expect(record).toBeDefined();
  const outputs = record!.sources.flatMap(source => source.outputs);
  const artwork = outputs.filter(output => output.role === 'artwork');
  const previews = outputs.filter(output => output.role === 'preview_tagged');
  expect(artwork).toHaveLength(1);
  expect(previews).toHaveLength(1);
  const media = [artwork[0], previews[0]];
  const expectedUrls = media.map(output => `/admin/media/${output.id}/preview`);
  let playbackRequested = false;
  const previewObservations: Array<{ response: Response; postGesture: boolean }> = [];
  const previewFailures: Array<{ errorText: string | null; postGesture: boolean }> = [];
  const observePreview = (response: Response) => {
    if (response.url() === new URL(expectedUrls[1], manifest.origin).href) {
      previewObservations.push({ response, postGesture: playbackRequested });
    }
  };
  const observePreviewFailure = (request: Request) => {
    if (request.url() === new URL(expectedUrls[1], manifest.origin).href) {
      previewFailures.push({ errorText: request.failure()?.errorText ?? null, postGesture: playbackRequested });
    }
  };
  // preload=none is a browser hint. Observe from insertion so an early metadata fetch also retains its real response evidence.
  page.on('response', observePreview);
  page.on('requestfailed', observePreviewFailure);
  await page.bringToFront();
  const launch = page.getByRole('row').filter({ has: page.getByText(track.title, { exact: true }) })
    .getByRole('button', { name: 'Review track', exact: true });
  await launch.focus();
  await launch.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: 'Private track review', exact: true })).toBeVisible();
  await expect(dialog.getByText('Private staff review. This track keeps its current publication state.', { exact: true })).toBeVisible();
  await expect(dialog.getByText('Status: draft', { exact: true })).toBeVisible();
  const metadata = dialog.getByRole('group', { name: 'Metadata', exact: true });
  for (const [label, value] of [['Track ID', String(track.id)], ['Title', track.title], ['URL', track.slug], ['Artist', track.artist], ['BPM', '90'],
    ['Musical key', 'C minor'], ['Genre', 'Synthetic fixture'], ['Mood', 'Not set']]) {
    const term = metadata.locator('dt').filter({ hasText: new RegExp(`^${label}$`) });
    await expect(term).toHaveCount(1);
    await expect(term.locator('xpath=following-sibling::dd[1]')).toHaveText(value);
  }
  await expect(metadata.getByText('No tags', { exact: true })).toBeVisible();
  const readiness = dialog.getByRole('group', { name: 'Publication readiness', exact: true });
  await expect(readiness.getByText('Ready to publish', { exact: true })).toBeVisible();
  await expect(readiness.getByRole('listitem')).toHaveCount(0);
  await expect(dialog.locator('input:not([type="hidden"]), select, textarea')).toHaveCount(0);
  await expect(dialog.getByRole('button', { name: /Save|Publish/i })).toHaveCount(0);
  await expect(dialog.getByRole('link')).toHaveCount(0);
  const image = dialog.getByRole('group', { name: 'Artwork', exact: true }).getByRole('img', { name: `Artwork for ${track.title}`, exact: true });
  await expect(image).toHaveAttribute('src', expectedUrls[0]);
  await expect.poll(() => image.evaluate(element => (element as HTMLImageElement).complete && (element as HTMLImageElement).naturalWidth > 0)).toBe(true);
  const audio = dialog.getByRole('group', { name: 'Tagged preview', exact: true }).locator('audio');
  await expect(audio).toHaveAttribute('aria-label', `Tagged preview for ${track.title}`);
  await expect(audio).toHaveAttribute('src', expectedUrls[1]);
  await expect(audio).toHaveAttribute('preload', 'none');
  await expect(audio).toHaveAttribute('controls', /.*/);
  await expect(audio).not.toHaveAttribute('autoplay');
  expect(await audio.evaluate(element => (element as HTMLAudioElement).paused)).toBe(true);
  expect(await audio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBe(0);

  const receipts = [];
  let verifiedPreviewBytes: Buffer | undefined;
  for (const [index, output] of media.entries()) {
    const response = await page.request.get(expectedUrls[index]);
    expect(response.status()).toBe(200);
    expect(response.headers()['cache-control']).toContain('private');
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(response.headers()['x-content-type-options']).toBe('nosniff');
    expect(response.headers()['x-robots-tag']).toContain('noindex');
    expect(response.headers()['referrer-policy']).toBe('no-referrer');
    const bytes = await response.body();
    expect(bytes.length).toBe(output.sizeBytes);
    const sha256 = createHash('sha256').update(bytes).digest('hex');
    expect(sha256).toBe(output.sha256);
    if (index === 1) verifiedPreviewBytes = bytes;
    const denied = await visitor.get(expectedUrls[index], { maxRedirects: 0, headers: { Accept: 'text/html' } });
    expect(denied.status()).toBe(302);
    expect(new URL(denied.headers().location, manifest.origin).pathname).toBe('/admin/login');
    expect(denied.headers()['content-type']).toContain('text/html');
    expect(denied.headers()['cache-control']).toContain('private');
    expect(denied.headers()['cache-control']).toContain('no-store');
    expect(denied.headers()['x-robots-tag']).toContain('noindex');
    expect(denied.headers()['x-content-type-options']).toBe('nosniff');
    expect(denied.headers()['referrer-policy']).toBe('no-referrer');
    expect(createHash('sha256').update(await denied.body()).digest('hex')).not.toBe(output.sha256);
    expect((await visitor.get(`/media/${output.id}`)).status()).toBe(404);
    receipts.push({ role: output.role, id: output.id, sizeBytes: bytes.length, sha256, cacheControl: response.headers()['cache-control'], guestStatus: denied.status() });
  }

  // Exercise browser-owned controls against the real protected MP3; the generated preview is short, so it may finish before polling.
  await audio.focus();
  await expect(audio).toBeFocused();
  if (browserName === 'webkit') {
    expect(testInfo.project.use.hasTouch).toBe(true);
    const bounds = await audio.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds!.width).toBeGreaterThan(48);
    playbackRequested = true;
    await audio.tap({ position: { x: 24, y: bounds!.height / 2 } });
  } else {
    playbackRequested = true;
    await audio.press('Space');
  }
  await expect.poll(() => audio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBeGreaterThan(0);
  await expect.poll(() => previewObservations.length).toBeGreaterThan(0);
  await expect.poll(() => previewObservations.some(observation => observation.postGesture && [200, 206].includes(observation.response.status()))).toBe(true);
  const previewResponses = [];
  for (let index = 0; index < previewObservations.length; index++) {
    const { response, postGesture } = previewObservations[index];
    const finished = await response.finished();
    const request = response.request();
    const requestHeaders = await request.allHeaders();
    const headers = await response.allHeaders();
    let body: Buffer | null = null;
    let bodyError: string | null = null;
    try {
      body = await response.body();
    } catch (error) {
      bodyError = error instanceof Error ? error.message : String(error);
    }
    previewResponses.push({
      status: response.status(), statusText: response.statusText(), postGesture,
      method: request.method(), resourceType: request.resourceType(),
      requestHeaderNames: Object.keys(requestHeaders), responseHeaderNames: Object.keys(headers),
      cacheControl: headers['cache-control'] ?? '', contentType: headers['content-type'] ?? '', contentRange: headers['content-range'] ?? '',
      finishedError: finished?.message ?? null, requestFailure: request.failure()?.errorText ?? null,
      body, bodyError, bodyBytes: body?.length ?? null, sha256: body === null ? null : createHash('sha256').update(body).digest('hex'),
    });
  }
  // Seal the observation window synchronously after draining every captured response, before attaching evidence.
  // Reopening below independently checks a fresh stopped player; it is outside this native-playback interval.
  page.off('response', observePreview);
  page.off('requestfailed', observePreviewFailure);
  // Attach all transport outcomes before assertions. Headerless driver observations are never media success.
  const nativeReceipts = previewResponses.map(({ body: _body, ...receipt }) => receipt);
  await testInfo.attach('private-track-review-native-preview-lifecycle', {
    body: Buffer.from(JSON.stringify({ trackId: track.id, evidenceHash: manifest.evidenceHash, browserName, nativeReceipts, previewFailures })),
    contentType: 'application/json',
  });
  expect(previewFailures).toEqual([]);
  expect(verifiedPreviewBytes).toBeDefined();
  for (const response of previewResponses) {
    expect(response.finishedError).toBeNull();
    expect(response.requestFailure).toBeNull();
    expect(response.method).toBe('GET');
    if (response.status === 0) {
      // The original locked WebKit trace contains headerless pre-gesture observations with no retained body.
      // Require its exact lifecycle and Network.getResponseBody protocol classification; do not call it cancelled.
      expect(browserName).toBe('webkit');
      expect(response.postGesture).toBe(false);
      expect(response.resourceType).toBe('other');
      expect(response.statusText).toBe('');
      expect(response.requestHeaderNames).toEqual([]);
      expect(response.responseHeaderNames).toEqual([]);
      expect(response.body).toBeNull();
      expect(response.bodyError).toMatch(/Protocol error \(Network\.getResponseBody\)/);
      continue;
    }
    expect([200, 206]).toContain(response.status);
    expect(response.cacheControl).toContain('private');
    expect(response.cacheControl).toContain('no-store');
    expect(response.contentType).toMatch(/^audio\/mpeg(?:;|$)/);
    expect(response.bodyError).toBeNull();
    expect(response.body).not.toBeNull();
    if (response.status === 206) {
      const range = /^bytes (\d+)-(\d+)\/(\d+)$/.exec(response.contentRange);
      expect(range).not.toBeNull();
      const [, startText, endText, totalText] = range!;
      const start = Number(startText), end = Number(endText), total = Number(totalText);
      expect(Number.isSafeInteger(start) && start >= 0 && Number.isSafeInteger(end) && end >= start && end < total).toBe(true);
      expect(total).toBe(verifiedPreviewBytes!.length);
      expect(response.bodyBytes).toBe(end - start + 1);
      expect(response.body!.equals(verifiedPreviewBytes!.subarray(start, end + 1))).toBe(true);
    } else {
      expect(response.bodyBytes).toBe(verifiedPreviewBytes!.length);
      expect(response.body!.equals(verifiedPreviewBytes!)).toBe(true);
    }
  }
  expect(previewResponses.some(response => response.postGesture && [200, 206].includes(response.status))).toBe(true);
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  expect(await dialog.locator('.fi-modal-window').evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('private-track-review-verified-media.png'), fullPage: false });
  await testInfo.attach('private-track-review-verified-bytes', { body: Buffer.from(JSON.stringify({ trackId: track.id, evidenceHash: manifest.evidenceHash, receipts, previewResponses: nativeReceipts })), contentType: 'application/json' });
  const close = async () => {
    const closeButton = dialog.locator('.fi-modal-footer').getByRole('button', { name: 'Close', exact: true });
    await closeButton.focus();
    await expect(closeButton).toBeFocused();
    const [unmounted] = await Promise.all([
      page.waitForResponse(response => {
        if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
        const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
        return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
      }),
      closeButton.press('Enter'),
    ]);
    expect(unmounted.status()).toBe(200);
    expect(await unmounted.finished()).toBeNull();
    await expect(dialog.getByRole('heading')).toBeHidden();
    await expect(launch).toBeFocused();
  };
  await close();
  // Reopening performs another authorized read and starts with a fresh, stopped native player.
  await launch.press('Enter');
  await expect(dialog.getByRole('heading', { name: 'Private track review', exact: true })).toBeVisible();
  await expect(image).toHaveAttribute('src', expectedUrls[0]);
  await expect(audio).toHaveAttribute('src', expectedUrls[1]);
  expect(await audio.evaluate(element => (element as HTMLAudioElement).paused)).toBe(true);
  expect(await audio.evaluate(element => (element as HTMLAudioElement).currentTime)).toBe(0);
  await close();
  expect((await visitor.get(track.href)).status()).toBe(404);
}

/** Runs after every retained editorial/media assertion, on a separate genuinely scanned track. */
async function reviewedPublication(page: Page, browser: Browser, visitor: APIRequestContext, testInfo: TestInfo, manifest: Manifest, track: Track) {
  const proof = JSON.parse(execFileSync('php', ['tests/browser/prepare-related-tracks.php', 'verify-publication', testInfo.project.name], {
    cwd: process.cwd(), env: process.env, timeout: 30000, maxBuffer: 65536, encoding: 'utf8', stdio: 'pipe',
  })) as { verified: boolean; project: string; state: string; evidenceHash: string };
  expect(proof).toMatchObject({ verified: true, project: testInfo.project.name, state: 'publication-ready', evidenceHash: manifest.evidenceHash });
  await page.goto('/admin/tracks');
  await searchTracks(page, track.title);
  await row(page, track.title).getByRole('button', { name: 'Unpublish', exact: true }).click();
  const withdrawal = page.getByRole('alertdialog', { name: 'Unpublish', exact: true });
  const [withdrawn] = await Promise.all([actionResponse(page, 'callMountedAction'), withdrawal.getByRole('button', { name: 'Confirm', exact: true }).click()]);
  expect(withdrawn.status()).toBe(200);
  expect(await withdrawn.finished()).toBeNull();
  await expect(withdrawal.getByRole('heading')).toBeHidden();
  await expect(row(page, track.title).getByRole('button', { name: 'Publish', exact: true })).toBeVisible();
  let publication = await openPublication(page, track.title);
  expect(publication.review).toMatchObject({ track_id: track.id, metadata_version: 1, publication_version: 2 });
  const initialHash = publication.review.manifest_hash;
  expect(await publicationReview(await closeDialog(page, publication.dialog, 'Cancel'))).toBeNull();
  await expect(publication.launch).toBeFocused();
  expect((await visitor.get(track.href)).status()).toBe(404);
  publication = await openPublication(page, track.title);
  expect(publication.review.manifest_hash).toBe(initialHash);

  const otherContext = await browser.newContext({ baseURL: manifest.origin, viewport: page.viewportSize() });
  const failures: string[] = [];
  try {
    const other = await otherContext.newPage();
    other.on('pageerror', error => failures.push(error.message));
    await login(other);
    await other.goto('/admin/tracks');
    await searchTracks(other, track.title);
    await row(other, track.title).getByRole('button', { name: 'Edit', exact: true }).click();
    const editor = other.getByRole('dialog');
    const mood = `Synthetic reviewed winner ${testInfo.project.name}`;
    const description = `Synthetic private concurrent description ${testInfo.project.name}.`;
    await editor.getByLabel('Mood', { exact: false }).fill(mood);
    await editor.getByLabel('Description', { exact: false }).fill(description);
    await syncSuccessNotification(other, 'Saved', () => editor.getByRole('button', { name: 'Save changes', exact: true }).click(),
      async () => { await expect(editor.getByRole('heading')).toBeHidden(); });

    const rejectHeldReview = async (message: string, label: string) => {
      await page.bringToFront();
      await publication.confirm.focus();
      await expectModalFits(page, publication.dialog);
      await syncSuccessNotification(page, 'Publication blocked', async () => {
        const [submitted] = await Promise.all([actionResponse(page, 'callMountedAction'), publication.confirm.press('Enter')]);
        expect(submitted.status()).toBe(200);
        expect(await submitted.finished()).toBeNull();
        expect(await publicationReview(submitted)).toBeNull();
        await expect(publication.dialog.getByRole('heading')).toBeHidden();
      }, async () => {
        const notification = page.locator('.fi-no-notification').filter({ has: page.getByRole('heading', { name: 'Publication blocked', exact: true }) });
        await expect(notification.getByText(message, { exact: true })).toBeVisible();
        for (const privateValue of [description, mood, publication.review.manifest_hash as string]) await expect(notification).not.toContainText(privateValue);
        await page.screenshot({ path: testInfo.outputPath(`${label}.png`), fullPage: false });
        await testInfo.attach(`${label}-notification-dom`, { body: await notification.evaluate(element => element.outerHTML), contentType: 'text/html' });
      });
      expect((await visitor.get(track.href)).status()).toBe(404);
      await expect(row(page, track.title).getByText('draft', { exact: true })).toBeVisible();
    };
    await rejectHeldReview('This track changed after publication review. Close and reopen the confirmation before trying again.', 'publication-stale-metadata');
    publication = await openPublication(page, track.title);
    expect(publication.review).toMatchObject({ track_id: track.id, metadata_version: 2, publication_version: 2 });
    expect(publication.review.manifest_hash).not.toBe(initialHash);
    const metadataHash = publication.review.manifest_hash;

    // Publish a real successor offer through the ordinary second operator session; draft edits alone do not change the promise.
    await other.bringToFront();
    await other.goto('/admin/offers');
    await searchTracks(other, track.title);
    const offer = row(other, track.title);
    await offer.getByRole('button', { name: 'Edit draft', exact: true }).click();
    const offerEditor = other.getByRole('dialog');
    await expect(offerEditor.getByLabel('Draft price in cents', { exact: false })).toHaveValue('1');
    await offerEditor.getByLabel('Draft price in cents', { exact: false }).fill('2');
    await syncSuccessNotification(other, 'Saved', () => offerEditor.getByRole('button', { name: 'Save changes', exact: true }).click(),
      async () => { await expect(offerEditor.getByRole('heading')).toBeHidden(); });
    await offer.getByRole('button', { name: 'Publish revision', exact: true }).click();
    const offerConfirmation = other.getByRole('alertdialog', { name: 'Publish revision', exact: true });
    const [revised] = await Promise.all([actionResponse(other, 'callMountedAction'), offerConfirmation.getByRole('button', { name: 'Confirm', exact: true }).click()]);
    expect(revised.status()).toBe(200);
    expect(await revised.finished()).toBeNull();
    await expect(offerConfirmation.getByRole('heading')).toBeHidden();
    await expect(offer.getByRole('cell', { name: '$0.02', exact: true })).toHaveCount(2);
    await rejectHeldReview('Publication evidence changed after review. Close and reopen the confirmation before trying again.', 'publication-stale-offer');

    publication = await openPublication(page, track.title);
    expect(publication.review).toMatchObject({ track_id: track.id, metadata_version: 2, publication_version: 2 });
    expect(publication.review.manifest_hash).not.toBe(metadataHash);
    await publication.confirm.focus();
    await expectModalFits(page, publication.dialog);
    const [published] = await Promise.all([actionResponse(page, 'callMountedAction'), publication.confirm.press('Enter')]);
    expect(published.status()).toBe(200);
    expect(await published.finished()).toBeNull();
    expect(await publicationReview(published)).toBeNull();
    await expect(publication.dialog.getByRole('heading')).toBeHidden();
    await expect(row(page, track.title).getByText('published', { exact: true })).toBeVisible();
    await page.reload();
    await expect(row(page, track.title).getByRole('button', { name: 'Unpublish', exact: true })).toBeVisible();
    expect((await visitor.get(track.href)).status()).toBe(200);
    await row(page, track.title).getByRole('button', { name: 'Edit', exact: true }).click();
    const retained = page.getByRole('dialog');
    await expect(retained.getByLabel('Mood', { exact: false })).toHaveValue(mood);
    await expect(retained.getByLabel('Description', { exact: false })).toHaveValue(description);
    await closeDialog(page, retained, 'Cancel');
    await testInfo.attach('reviewed-publication-receipt', { body: Buffer.from(JSON.stringify({
      fixtureEvidenceHash: manifest.evidenceHash, trackId: track.id, review: publication.review,
      earlierReviewsConsumed: true, publicStatus: 200,
    })), contentType: 'application/json' });
    expect(failures).toEqual([]);
  } finally {
    await otherContext.close();
  }
}

test('ordinary editorial associations and reviewed publication preserve current evidence and first-party destinations', async ({ page, context, playwright, browserName, browser }, testInfo) => {
  test.setTimeout(150_000);
  const manifest = loadManifest();
  const fixture = manifest.projects[testInfo.project.name];
  expect(fixture.tracks).toHaveLength(2);
  const [first, second] = fixture.tracks;
  for (const track of fixture.tracks) {
    expect(Number.isSafeInteger(track.id) && track.id > 0).toBe(true);
    expect(track.href).toBe(`/tracks/${track.slug}`);
  }
  expect(first.title).toHaveLength(255);
  expect(first.id).not.toBe(second.id);
  await persisted(testInfo, manifest, 'published', 'genuine-ready-track-evidence');
  const failures: string[] = [];
  const externalRequests: string[] = [];
  const editorialMediaRequests: string[] = [];
  let watchMedia = true;
  const watch = (target: Page, editorial = false) => {
    target.on('pageerror', error => failures.push(error.message));
    // The no-provider requirement covers every request from editorial previews/public pages; admin keeps its own shell.
    if (!editorial) return;
    target.on('request', request => {
      const url = new URL(request.url());
      if (url.origin !== manifest.origin) externalRequests.push(url.origin);
      if (watchMedia && /^\/media\//.test(url.pathname)) editorialMediaRequests.push(url.pathname);
    });
  };
  watch(page);
  const visitor = await playwright.request.newContext({ baseURL: manifest.origin });
  const suffix = testInfo.project.name;
  const firstLabel = `Synthetic related original ${suffix}`;
  const copiedLabel = `Synthetic related copy ${suffix}`;
  const slug = `synthetic-related-${suffix}`;
  const articleTitle = `Synthetic related article ${suffix}`;
  const firstParagraph = `Synthetic retained related paragraph ${suffix}.`;
  const copiedParagraph = `Synthetic copied related paragraph ${suffix}.`;
  const publicLinks = [second, first].map(track => ({ title: track.title, artist: track.artist, href: track.href }));
  try {
    await page.goto('/admin/login');
    await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
    await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
    await page.goto('/admin/site-releases');
    await page.getByRole('button', { name: 'New content draft', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('Release label', { exact: false }).fill(firstLabel);
    for (const section of ['Blog', 'Videos']) {
      await dialog.getByLabel(`Include ${section} page`, { exact: true }).check();
      await dialog.getByLabel(`${section} page title`, { exact: false }).fill(`Synthetic related ${section} ${suffix}`);
      await dialog.getByLabel(`${section} page description`, { exact: false }).fill('Synthetic related detail associations.');
    }
    await dialog.getByLabel('Article URL slug', { exact: false }).fill(slug);
    await dialog.getByLabel('Article title', { exact: false }).fill(articleTitle);
    await dialog.getByLabel('Article description', { exact: false }).fill('Synthetic related article description.');
    await dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea')).fill(firstParagraph);
    await dialog.getByLabel('Video URL slug', { exact: false }).fill(slug);
    await dialog.getByLabel('Video title', { exact: false }).fill(`Synthetic related video ${suffix}`);
    await dialog.getByLabel('Video description', { exact: false }).fill('Synthetic related video description.');
    await dialog.getByLabel('Video provider', { exact: false }).selectOption('youtube');
    await dialog.getByLabel('Video ID', { exact: false }).fill('abcdefghijk');
    const groups = dialog.getByRole('group', { name: 'Related tracks', exact: true });
    await expect(groups).toHaveCount(2);
    // New drafts copy the active release. Remove any inherited choices through its normal repeater controls.
    await clearTracks(groups.nth(0));
    await clearTracks(groups.nth(1));
    await addTrack(groups.nth(0), first);
    await addTrack(groups.nth(0), second);
    const move = groups.nth(0).getByRole('button', { name: 'Move up', exact: true }).nth(1);
    await move.focus();
    await move.press('Enter');
    await expect(groups.nth(0).getByRole('combobox').first()).toContainText(second.title);
    await addTrack(groups.nth(1), first);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(releaseRow(page, firstLabel).getByText('Private draft', { exact: true })).toBeVisible();
    expect((await visitor.get(`/blog/${slug}`)).status()).toBe(404);
    const previewUrl = await releaseRow(page, firstLabel).getByRole('link', { name: 'Preview', exact: true }).getAttribute('href');
    expect(previewUrl).toBeTruthy();
    const privatePath = `${new URL(previewUrl!, manifest.origin).pathname}/blog/${slug}`;
    const denied = await visitor.get(privatePath, { maxRedirects: 0 });
    expect([302, 403]).toContain(denied.status());
    expect(await denied.text()).not.toContain(firstParagraph);
    const preview = await context.newPage(); watch(preview, true);
    const privateResponse = await preview.goto(privatePath);
    expect(privateResponse?.headers()['cache-control']).toContain('no-store');
    expect(privateResponse?.headers()['x-robots-tag']).toContain('noindex');
    const privateRelated = preview.getByRole('region', { name: 'Related tracks', exact: true });
    await expect(privateRelated.getByRole('heading', { level: 3 })).toHaveText([second.title, first.title]);
    await expect(privateRelated.getByRole('link')).toHaveCount(0);
    const privateProps = await props(preview.request, privatePath);
    expect(privateProps.sitePreview).toBe(true);
    expect(privateProps.commerceEnabled).toBe(false);
    expect(privateProps.editorial.relatedTracks).toEqual(publicLinks.map(({ title, artist }) => ({ title, artist })));
    await preview.screenshot({ path: testInfo.outputPath('related-private-preview.png'), fullPage: true });
    await preview.close();

    await activate(page, firstLabel, 'Publish release');
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual(publicLinks);
    expect((await props(visitor, `/videos/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[1]]);
    for (const path of ['/blog', '/videos']) expect((await props(visitor, path)).editorial.relatedTracks).toBeUndefined();
    const publicPage = await context.newPage(); watch(publicPage, true);
    await publicPage.goto(`/blog/${slug}`);
    const related = publicPage.getByRole('region', { name: 'Related tracks', exact: true });
    await expect(related.getByRole('link')).toHaveText([second.title, first.title]);
    expect(await publicPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await publicPage.screenshot({ path: testInfo.outputPath('related-public-wrapping.png'), fullPage: true });

    await page.goto('/admin/tracks');
    const trackRow = page.getByRole('row').filter({ has: page.getByText(first.title, { exact: true }) });
    await trackRow.getByRole('button', { name: 'Unpublish', exact: true }).click();
    const withdrawal = page.getByRole('alertdialog', { name: 'Unpublish', exact: true });
    await withdrawal.getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(trackRow.getByRole('button', { name: 'Publish', exact: true })).toBeVisible();
    await persisted(testInfo, manifest, 'withdrawn', 'ordinary-withdrawal-evidence');
    await reviewVerifiedPrivateTrack(page, visitor, testInfo, manifest, first, browserName);
    await persisted(testInfo, manifest, 'withdrawn', 'private-track-review-unchanged-evidence');
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[0]]);
    expect((await props(visitor, `/videos/${slug}`)).editorial.relatedTracks).toEqual([]);
    expect((await visitor.get(first.href)).status()).toBe(404);
    await publicPage.reload();
    await expect(related.getByRole('link')).toHaveText([second.title]);
    await expect(publicPage.getByText(firstParagraph, { exact: true })).toBeVisible();

    await page.goto('/admin/site-releases');
    await (await releaseMenuAction(page, firstLabel, 'Edit as new draft')).click();
    dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('group', { name: 'Related tracks', exact: true }).first().getByRole('combobox').first()).toContainText(second.title);
    await expect(dialog.getByRole('group', { name: 'Related tracks', exact: true }).first().getByRole('combobox').nth(1)).toContainText(`${first.title} · ${first.artist} (not currently available)`);
    await dialog.getByLabel('Release label', { exact: false }).fill(copiedLabel);
    await dialog.getByLabel('Article paragraph', { exact: false }).and(dialog.locator('textarea')).fill(copiedParagraph);
    await dialog.getByRole('button', { name: 'Save private draft', exact: true }).click();
    await expect(releaseRow(page, copiedLabel).getByText('Private draft', { exact: true })).toBeVisible();
    await activate(page, copiedLabel, 'Publish release');
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[0]]);
    expect(await (await visitor.get(`/blog/${slug}`)).text()).toContain(copiedParagraph);
    await activate(page, firstLabel, 'Restore previous release');
    expect(await (await visitor.get(`/blog/${slug}`)).text()).toContain(firstParagraph);
    expect((await props(visitor, `/blog/${slug}`)).editorial.relatedTracks).toEqual([publicLinks[0]]);
    await persisted(testInfo, manifest, 'withdrawn', 'unchanged-media-license-commerce-evidence');
    expect(editorialMediaRequests).toEqual([]);
    expect(externalRequests).toEqual([]);

    // Only after the editorial no-media checks, follow the genuine surviving track destination.
    watchMedia = false;
    await publicPage.reload();
    const link = related.getByRole('link', { name: second.title, exact: true });
    await link.focus();
    await publicPage.keyboard.press('Shift+Tab');
    await publicPage.keyboard.press('Tab');
    await expect(link).toBeFocused();
    expect(await link.evaluate(element => parseFloat(getComputedStyle(element).outlineWidth))).toBeGreaterThanOrEqual(2);
    await link.press('Enter');
    await expect(publicPage).toHaveURL(second.href);
    await expect(publicPage.locator('#detail-title')).toBeFocused();
    await publicPage.close();
    await activate(page, 'Original site content', 'Restore previous release');
    expect((await visitor.get(`/blog/${slug}`)).status()).toBe(404);
    expect((await visitor.get(`/videos/${slug}`)).status()).toBe(404);
    await reviewedPublication(page, browser, visitor, testInfo, manifest, fixture.publicationTrack);
    await persisted(testInfo, manifest, 'withdrawn', 'editorial-graphs-unchanged-after-reviewed-publication');
    expect(failures).toEqual([]);
    expect(externalRequests).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});
