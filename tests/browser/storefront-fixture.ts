import { expect, type Page, type Route } from '@playwright/test';
import type { StorefrontProps, Track } from '../../resources/js/lib/catalog';

/** Synthetic UI transport only. Backend feature tests own publication/security evidence.
 * Real built React/CSS, Inertia navigation and native audio run against this transport.
 * No fixture records, scanner bypasses or mock audio enter the application runtime.
 */
const duration = 60;
const sampleRate = 22050;
const sampleCount = duration * sampleRate;
const wav = Buffer.alloc(44 + sampleCount * 2);
wav.write('RIFF', 0); wav.writeUInt32LE(wav.length - 8, 4); wav.write('WAVEfmt ', 8);
wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
wav.writeUInt32LE(sampleRate, 24); wav.writeUInt32LE(sampleRate * 2, 28);
wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34); wav.write('data', 36); wav.writeUInt32LE(sampleCount * 2, 40);
const waveform = Array<number>(80).fill(0);
for (let index = 0; index < sampleCount; index++) {
  const sample = Math.round(1500 * Math.sin(2 * Math.PI * 220 * index / sampleRate));
  wav.writeInt16LE(sample, 44 + index * 2);
  const bucket = Math.min(79, Math.floor(index / sampleCount * 80));
  waveform[bucket] = Math.max(waveform[bucket], Math.abs(sample) / 32768);
}

export const fixtureTrack: Track = {
  id: '7001', slug: 'synthetic-browser-track', title: 'Synthetic browser track', artist: 'Nonbinding test fixture',
  bpm: 90, musicalKey: 'C minor', genre: 'Test', mood: 'Synthetic', durationSeconds: duration, tags: ['Synthetic recording'], waveform,
  artworkUrl: '/images/storefront-hero.jpg', previewUrl: '/media/synthetic-browser-preview', shareUrl: '/tracks/synthetic-browser-track',
  offers: ['Standard test license', 'Extended test license'].map((licenseName, index) => ({
    id: String(7101 + index), offerRevisionId: String(7201 + index), licenseVersionId: String(7301 + index), licenseName,
    priceMinor: 1000 + index * 1000, currency: 'USD', deliverableRoles: ['master_wav'], licenseUrl: `/tracks/synthetic-browser-track/offers/${7201 + index}/license`,
  })),
};
const tiers: StorefrontProps['licenseTiers'] = fixtureTrack.offers.map(offer => ({
  id: offer.licenseVersionId, name: offer.licenseName, version: 1, type: 'non-exclusive', requiredAssetRoles: offer.deliverableRoles,
  features: ['NONBINDING browser fixture. No real rights or payment obligation.'],
}));
export const query = '?q=Synthetic&genre=Test&sort=title';
export const policyText = (index: number) => `NONBINDING SYNTHETIC ${index === 0 ? 'STANDARD' : 'EXTENDED'} POLICY.\n<script id="policy-injection">window.injected=true</script>\n${'Complete retained synthetic clause. '.repeat(100)}\nFINAL RETAINED CLAUSE.`;

async function serveAudio(route: Route) {
  const headers: Record<string, string> = { 'Content-Type': 'audio/wav', 'Accept-Ranges': 'bytes', 'Cache-Control': 'no-store' };
  const range = route.request().headers().range?.match(/^bytes=(\d+)-(\d*)$/);
  if (!range) return route.fulfill({ status: 200, headers, body: wav });
  const start = Number(range[1]);
  const end = Math.min(range[2] ? Number(range[2]) : wav.length - 1, wav.length - 1);
  if (start > end) return route.fulfill({ status: 416, headers: { ...headers, 'Content-Range': `bytes */${wav.length}` } });
  return route.fulfill({ status: 206, headers: { ...headers, 'Content-Range': `bytes ${start}-${end}/${wav.length}` }, body: wav.subarray(start, end + 1) });
}

declare global { interface Window { __nativePreviews: HTMLAudioElement[] } }

export async function storefrontFixture(page: Page, options: { failFirstTerms?: boolean; testOrderPreparationEnabled?: boolean } = {}) {
  const shellResponse = await page.request.get('/');
  expect(shellResponse.ok()).toBe(true);
  const shell = await shellResponse.text();
  // Bootstrap the exact page/version from the real HTML. A bare X-Inertia GET
  // without that asset version correctly triggers Inertia's 409 reload protocol.
  const pageScript = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
  const embedded = shell.match(pageScript);
  expect(embedded).not.toBeNull();
  const base = JSON.parse(embedded![2]);

  // Observe allocation/state without replacing playback, events, seeking or promises.
  await page.addInitScript(() => {
    window.__nativePreviews = [];
    window.Audio = new Proxy(window.Audio, { construct(target, args: [string?]) {
      const element = new target(...args); window.__nativePreviews.push(element); return element;
    } });
  });
  let termsRequests = 0;
  const navigation: string[] = [];
  await page.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.pathname === '/media/synthetic-browser-preview') return serveAudio(route);
    if (url.pathname === '/catalog/selections') return route.fulfill({ json: { tracks: [fixtureTrack], licenseTiers: tiers } });
    const offerIndex = fixtureTrack.offers.findIndex(offer => offer.licenseUrl === url.pathname);
    if (offerIndex !== -1) {
      termsRequests++;
      if (options.failFirstTerms && termsRequests === 1) return route.fulfill({ status: 503, json: { message: 'Synthetic connection failure' } });
      const offer = fixtureTrack.offers[offerIndex];
      return route.fulfill({ headers: { 'Cache-Control': 'private, no-store' }, json: {
        offerId: offer.id, offerRevisionId: offer.offerRevisionId, licenseVersionId: offer.licenseVersionId,
        name: offer.licenseName, version: 1, type: 'non-exclusive', features: tiers[offerIndex].features,
        deliverableRoles: offer.deliverableRoles, termsText: policyText(offerIndex),
      } });
    }
    if (request.method() !== 'GET' || !['/', fixtureTrack.shareUrl].includes(url.pathname)) return route.continue();
    const detail = url.pathname === fixtureTrack.shareUrl;
    const currentUrl = '/' + url.search;
    const metadata = { ...base.props.metadata, title: detail ? `${fixtureTrack.title} — VASEY.AUDIO` : base.props.metadata.title, canonicalUrl: url.origin + url.pathname, type: detail ? 'music.song' : 'website' };
    const payload = { ...base, url: url.pathname + url.search, props: { ...base.props, tracks: [fixtureTrack], licenseTiers: tiers,
      selectedTrack: detail ? fixtureTrack : null, selectedTrackSlug: detail ? fixtureTrack.slug : null, metadata,
      testOrderPreparationEnabled: options.testOrderPreparationEnabled ?? base.props.testOrderPreparationEnabled,
      catalogPage: { filters: { q: url.searchParams.get('q') ?? '', genre: url.searchParams.get('genre') ?? '', sort: url.searchParams.get('sort') ?? 'featured' }, previousUrl: null, nextUrl: null, restartUrl: currentUrl, currentUrl, hasCursor: false },
    } };
    if (request.headers()['x-inertia']) {
      navigation.push(payload.url);
      return route.fulfill({ headers: { 'X-Inertia': 'true', Vary: 'X-Inertia' }, json: payload });
    }
    const json = JSON.stringify(payload).replaceAll('<', '\\u003c');
    return route.fulfill({ contentType: 'text/html', body: shell.replace(pageScript, (_match, opening, _original, closing) => opening + json + closing) });
  });
  return { navigation, termsRequests: () => termsRequests };
}
