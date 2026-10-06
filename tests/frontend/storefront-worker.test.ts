import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { describe, expect, it, vi } from 'vitest';
import { isPublicStorefrontPath } from '../../resources/js/lib/storefront-offline';

const origin = 'https://storefront.example.test';
const cacheName = 'vasey-audio-public-offline-v1';
const assets = new Map([
  ['/offline.html', 'text/html'], ['/brand/theme.css', 'text/css'], ['/brand/vasey-audio-logo.png', 'image/png'],
  ['/brand/fonts/reddit-sans-latin-wght-normal.woff2', 'font/woff2'], ['/brand/fonts/bebas-neue-latin-400-normal.woff2', 'font/woff2'],
]);
const source = readFileSync('public/storefront-worker.js', 'utf8');
interface WorkerRequest { url: string; method: string; mode: string; destination: string; headers: Headers }

function harness() {
  const listeners: Record<string, (event: Record<string, unknown>) => void> = {};
  const stores = new Map<string, Map<string, Response>>();
  const writes: string[] = [];
  const fetch = vi.fn(async (request: { url: string }) => new Response(`network:${request.url}`, {
    headers: { 'Content-Type': assets.get(new URL(request.url).pathname) || 'text/html' },
  }));
  const put = vi.fn(async (key: string, response: Response) => {
    writes.push(key); stores.get(cacheName)!.set(key, response.clone());
  });
  const caches = {
    open: vi.fn(async (name: string) => {
      if (!stores.has(name)) stores.set(name, new Map());
      return { put, match: vi.fn(async (key: string) => stores.get(name)?.get(key)?.clone()) };
    }),
    keys: vi.fn(async () => Array.from(stores.keys())),
    delete: vi.fn(async (name: string) => stores.delete(name)),
  };
  const claim = vi.fn(async () => undefined);
  const skipWaiting = vi.fn();
  runInNewContext(source, {
    self: { location: { origin }, addEventListener: (name: string, listener: typeof listeners[string]) => { listeners[name] = listener; }, clients: { claim }, skipWaiting },
    caches, fetch, Request, Response, URL,
  });
  async function lifecycle(name: string) {
    let work: Promise<unknown> | undefined;
    listeners[name]({ waitUntil: (promise: Promise<unknown>) => { work = promise; } });
    expect(work).toBeDefined();
    await work;
  }
  function dispatch(path: string, options: Partial<WorkerRequest> = {}) {
    const request: WorkerRequest = { url: new URL(path, origin).href, method: 'GET', mode: 'navigate', destination: 'document', headers: new Headers(), ...options };
    let result: Promise<Response> | undefined;
    listeners.fetch({ request, respondWith: (promise: Promise<Response>) => { result = promise; } });
    return { request, result };
  }
  return { fetch, caches, stores, writes, put, claim, skipWaiting, lifecycle, dispatch };
}

describe('actual offline worker cache and network boundaries', () => {
  it('installs only the five fixed assets anonymously without redirects or HTTP-cache reuse', async () => {
    const h = harness();
    await h.lifecycle('install');
    expect(h.fetch).toHaveBeenCalledTimes(assets.size);
    for (const [request] of h.fetch.mock.calls) {
      expect(assets.has(new URL(request.url).pathname)).toBe(true);
      expect(request).toMatchObject({ credentials: 'omit', cache: 'no-store', redirect: 'error' });
    }
    expect(h.writes.sort()).toEqual(Array.from(assets.keys()).sort());
    expect(Array.from(h.stores.keys())).toEqual([cacheName]);
    expect(h.skipWaiting).not.toHaveBeenCalled();
  });

  it.each(['status', 'redirect', 'type', 'opaque', 'url'] as const)('refuses an invalid fixed asset (%s) before creating a cache', async invalid => {
    const h = harness();
    h.fetch.mockImplementation(async request => {
      const response = new Response('asset', { status: invalid === 'status' ? 503 : 200,
        headers: { 'Content-Type': invalid === 'type' ? 'application/json' : assets.get(new URL(request.url).pathname)! } });
      if (invalid === 'redirect') Object.defineProperty(response, 'redirected', { value: true });
      if (invalid === 'opaque') Object.defineProperty(response, 'type', { value: 'opaque' });
      if (invalid === 'url') Object.defineProperty(response, 'url', { value: `${origin}/account` });
      return response;
    });
    await expect(h.lifecycle('install')).rejects.toThrow('Offline asset unavailable');
    expect(h.caches.open).not.toHaveBeenCalled(); expect(h.writes).toEqual([]);
  });

  it('refuses failed asset transport before creating a cache', async () => {
    const h = harness(); h.fetch.mockRejectedValueOnce(new TypeError('Offline'));
    await expect(h.lifecycle('install')).rejects.toThrow('Offline');
    expect(h.caches.open).not.toHaveBeenCalled();
  });

  it('removes a partially written cache on storage failure', async () => {
    const h = harness();
    h.put.mockImplementationOnce(async (key, response) => { h.stores.get(cacheName)!.set(key, response.clone()); })
      .mockRejectedValueOnce(new Error('Quota'));
    await expect(h.lifecycle('install')).rejects.toThrow('Quota');
    expect(h.caches.delete).toHaveBeenCalledExactlyOnceWith(cacheName);
    expect(h.stores.has(cacheName)).toBe(false);
  });

  it('activation removes only old owned caches and claims clients without forced activation', async () => {
    const h = harness();
    for (const key of [cacheName, 'vasey-audio-public-offline-old', 'other-feature-v1']) h.stores.set(key, new Map());
    await h.lifecycle('activate');
    expect(h.caches.delete).toHaveBeenCalledExactlyOnceWith('vasey-audio-public-offline-old');
    expect(Array.from(h.stores.keys()).sort()).toEqual([cacheName, 'other-feature-v1'].sort());
    expect(h.claim).toHaveBeenCalledTimes(1); expect(h.skipWaiting).not.toHaveBeenCalled();
  });

  it.each([200, 302, 401, 404, 429, 503])('returns actual public HTTP status %i without caching it', async status => {
    const h = harness(); const response = new Response('live response', { status }); h.fetch.mockResolvedValueOnce(response);
    const event = h.dispatch('/tracks/a-track?from=store');
    expect(await event.result).toBe(response); expect(h.fetch).toHaveBeenCalledWith(event.request);
    expect(h.caches.open).not.toHaveBeenCalled(); expect(h.writes).toEqual([]);
  });

  it.each(['/', '/tracks/a-track', '/about', '/contact', '/blog/a-post', '/videos/a-video'])('serves only the static offline page on public navigation failure at %s', async path => {
    const h = harness(); await h.lifecycle('install');
    h.fetch.mockRejectedValueOnce(new TypeError('Network unavailable'));
    const event = h.dispatch(path);
    expect(isPublicStorefrontPath(path)).toBe(true);
    expect(await (await event.result)!.text()).toBe(`network:${origin}/offline.html`);
    expect(h.writes).toHaveLength(assets.size);
  });

  it.each([
    ['/api/catalog', { mode: 'cors' }], ['/account', {}], ['/admin', {}], ['/admin/site-releases/1/preview', {}],
    ['/orders/one/status', {}], ['/orders/one/delivery/download', {}], ['/quotes/one', {}], ['/inquiries/one', {}],
    ['/contact/inquiries', {}], ['/media/1', {}], ['/embed/one', {}], ['/tracks/one/offers/1/license', {}],
    ['/', { mode: 'cors' }], ['/', { destination: 'iframe' }], ['/', { destination: 'embed' }],
    ['/', { method: 'POST' }], ['/', { method: 'HEAD' }],
    ['https://external.example.test/', {}], ['/', { headers: new Headers({ Range: 'bytes=0-10' }) }],
    ['/brand/theme.css?version=old', { mode: 'cors' }], ['/brand/theme.css#fragment', { mode: 'cors' }],
    ['/brand/vasey-audio-logo.png', { headers: new Headers({ Range: 'bytes=0-10' }) }],
  ] as [string, Partial<WorkerRequest>][])('never intercepts or caches bypass request %s %j', (path, options) => {
    const h = harness(); const event = h.dispatch(path, options);
    expect(event.result).toBeUndefined(); expect(h.fetch).not.toHaveBeenCalled();
    expect(h.caches.open).not.toHaveBeenCalled(); expect(h.writes).toEqual([]);
  });

  it('uses exact cached offline assets without making a network call', async () => {
    const h = harness(); await h.lifecycle('install'); h.fetch.mockClear();
    for (const path of assets.keys()) expect(await (await h.dispatch(path, { mode: 'cors' }).result)!.text()).toBe(`network:${origin}${path}`);
    expect(h.fetch).not.toHaveBeenCalled(); expect(h.writes).toHaveLength(assets.size);
  });

  it('an evicted offline page produces a network error rather than a successful app response', async () => {
    const h = harness(); h.fetch.mockRejectedValueOnce(new TypeError('Offline'));
    const response = await h.dispatch('/').result;
    expect(response!.type).toBe('error'); expect(response!.status).toBe(0); expect(h.writes).toEqual([]);
  });

  it('an uncached fixed asset uses the normal network without runtime caching', async () => {
    const h = harness(); const event = h.dispatch('/brand/theme.css', { mode: 'cors' });
    expect(await (await event.result)!.text()).toBe(`network:${origin}/brand/theme.css`);
    expect(h.fetch).toHaveBeenCalledWith(event.request); expect(h.writes).toEqual([]);
  });

  it('storage denial after activation does not prevent normal online asset delivery', async () => {
    const h = harness(); h.caches.open.mockRejectedValueOnce(new Error('Storage denied'));
    const event = h.dispatch('/brand/theme.css', { mode: 'cors' });
    expect(await (await event.result)!.text()).toBe(`network:${origin}/brand/theme.css`);
    expect(h.fetch).toHaveBeenCalledWith(event.request); expect(h.writes).toEqual([]);
  });

  it('storage denial during a failed public navigation stays a network error', async () => {
    const h = harness(); h.fetch.mockRejectedValueOnce(new TypeError('Offline'));
    h.caches.open.mockRejectedValueOnce(new Error('Storage denied'));
    const response = await h.dispatch('/').result;
    expect(response!.type).toBe('error'); expect(response!.status).toBe(0); expect(h.writes).toEqual([]);
  });
});
