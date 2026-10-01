import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createInertiaApp, router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { afterEach, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import CheckoutReturn from '../../resources/js/Pages/CheckoutReturn';
import type { PageMetadata, Track } from '../../resources/js/lib/catalog';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { fixtureTracks } from '../../resources/js/test/fixtures';
import { player } from '../../resources/js/lib/audio';

const home: PageMetadata = {
  title: 'VASEY.AUDIO — Sound with intent', description: 'Original music by Sean Vasey.',
  canonicalUrl: 'http://localhost:3000/', imageUrl: 'http://localhost:3000/images/storefront-hero.jpg',
  imageAlt: 'Studio artwork', type: 'website', robots: 'noindex, nofollow',
};
const track: PageMetadata = {
  ...home, title: 'Night Signal by Synthetic Artist — VASEY.AUDIO', description: 'Listen to Night Signal. 90 BPM.',
  canonicalUrl: 'http://localhost:3000/tracks/night-signal', imageUrl: 'http://localhost:3000/media/10',
  imageAlt: 'Night Signal cover artwork', type: 'music.song',
};

const returnOrder = '74000000-0000-4000-8000-000000000001';
const returnUrl = `/orders/${returnOrder}/checkout/return?success=true&payment_status=paid`;
const privateTitle = 'Checkout status — VASEY.AUDIO';
const privateDescription = 'View the saved test order status for this session. A browser return does not verify payment.';

function savedStatus() {
  return new Response(JSON.stringify({ checkout: { checkoutSchema: 1, orderId: returnOrder, id: null,
    currency: 'USD', totalMinor: 4280, status: 'not_started', testOnly: true, paymentStatus: 'not_verified',
    finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started',
    url: null, expiresAt: null, observedAt: null } }));
}

async function mount(metadata: PageMetadata, options: { tracks?: Track[]; privateReturn?: boolean } = {}) {
  const host = document.createElement('div'); host.id = 'metadata-app'; document.body.appendChild(host);
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  const page: Page = {
    component: options.privateReturn ? 'CheckoutReturn' : 'Storefront',
    props: options.privateReturn ? { errors: {}, orderId: returnOrder, siteContent: defaultSiteContent }
      : { errors: {}, tracks: options.tracks ?? [], licenseTiers: [], metadata },
    url: options.privateReturn ? returnUrl : '/', version: null, rescuedProps: [], flash: {}, rememberedState: {},
  };
  await act(async () => {
    await createInertiaApp({
      id: host.id, page, progress: false, title: title => title || home.title,
      resolve: name => name === 'CheckoutReturn' ? CheckoutReturn : Storefront,
      setup({ App, props }) { render(<App {...props} />, { container: host }); },
    });
  });
}

async function expectMetadata(metadata: PageMetadata) {
  await waitFor(() => {
    expect(document.title).toBe(metadata.title);
    expect(document.head.querySelectorAll('title')).toHaveLength(1);
    expect(document.head.querySelectorAll('link[rel="canonical"]')).toHaveLength(1);
    expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', metadata.canonicalUrl);
    for (const [key, value] of Object.entries({
      description: metadata.description, robots: metadata.robots, 'og:site_name': 'VASEY.AUDIO', 'og:type': metadata.type,
      'og:title': metadata.title, 'og:description': metadata.description, 'og:url': metadata.canonicalUrl,
      'og:image': metadata.imageUrl, 'og:image:alt': metadata.imageAlt, 'twitter:card': 'summary_large_image',
      'twitter:title': metadata.title, 'twitter:description': metadata.description,
      'twitter:image': metadata.imageUrl, 'twitter:image:alt': metadata.imageAlt,
    })) {
      const nodes = document.head.querySelectorAll(`meta[name="${key}"], meta[property="${key}"]`);
      expect(nodes).toHaveLength(1);
      expect(nodes[0]).toHaveAttribute('content', value);
    }
  });
}

afterEach(() => {
  document.head.querySelectorAll('[data-inertia], [data-test-head]').forEach(node => node.remove());
  window.history.replaceState({}, '', '/');
});

async function expectPrivateMetadata() {
  await waitFor(() => {
    expect(document.title).toBe(privateTitle);
    expect(document.head.querySelectorAll('title')).toHaveLength(1);
    for (const [name, content] of Object.entries({ description: privateDescription, robots: 'noindex, nofollow', referrer: 'no-referrer' })) {
      const tags = document.head.querySelectorAll(`meta[name="${name}"]`);
      expect(tags).toHaveLength(1); expect(tags[0]).toHaveAttribute('content', content);
    }
    expect(document.head.querySelectorAll('link[rel="canonical"], meta[property^="og:"], meta[name^="twitter:"]')).toHaveLength(0);
    expect(document.head.innerHTML).not.toContain(returnOrder);
    expect(document.head.innerHTML).not.toContain('payment_status');
    expect(document.head.querySelector('meta[name="csrf-token"]')).toHaveAttribute('content', 'keep-me');
    expect(document.head.querySelector('meta[name="theme-color"]')).toHaveAttribute('content', '#101214');
  });
}

it('removes public sharing metadata on a real private return visit and restores it while retaining one audio owner', async () => {
  document.head.insertAdjacentHTML('beforeend', '<meta data-test-head name="csrf-token" content="keep-me"><meta data-test-head name="theme-color" content="#101214">');
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async () => savedStatus());
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
  vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
  vi.spyOn(HTMLMediaElement.prototype, 'duration', 'get').mockReturnValue(120);
  const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) {
    this.dispatchEvent(new Event('playing')); return Promise.resolve();
  });
  const shared = { ...home, imageWidth: 1200, imageHeight: 630, imageType: 'image/jpeg' };
  const playable = { ...fixtureTracks[0], previewUrl: '/media/tagged-preview' };
  try {
    await mount(shared, { tracks: [playable] });
    await expectMetadata(shared);
    await waitFor(() => {
      for (const [key, value] of Object.entries({ 'og:image:width': '1200', 'og:image:height': '630', 'og:image:type': 'image/jpeg' })) {
        expect(document.head.querySelector(`meta[property="${key}"]`)).toHaveAttribute('content', value);
      }
    });
    await act(() => player.play(playable));
    const source = play.mock.contexts[0] as HTMLMediaElement;
    act(() => { source.dispatchEvent(new Event('durationchange')); source.currentTime = 42; source.dispatchEvent(new Event('timeupdate')); });
    await act(async () => { router.push({ component: 'CheckoutReturn', url: returnUrl,
      props: { errors: {}, orderId: returnOrder, siteContent: defaultSiteContent }, preserveScroll: true }); });
    await expectPrivateMetadata();
    await within(screen.getByRole('region', { name: 'Stripe test checkout' })).findByRole('status');
    expect(fetcher.mock.calls).toEqual([[`/orders/${returnOrder}/checkout`, expect.objectContaining({ method: 'GET', cache: 'no-store' })]]);
    expect(screen.queryByText(/test payment verified/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pause preview' })).toBeInTheDocument();
    expect(screen.getByRole('slider', { name: 'Seek preview' })).toHaveValue('42');
    fireEvent.click(screen.getByRole('button', { name: 'Queue & loop' }));
    expect(within(screen.getByRole('region', { name: 'Preview queue and loop controls' })).getByRole('list').children).toHaveLength(1);
    expect(source.currentTime).toBe(42); expect(source.src).toBe(window.location.origin + playable.previewUrl);
    expect(play).toHaveBeenCalledTimes(1);
    await act(async () => { router.push({ component: 'Storefront', url: '/', props: { errors: {}, tracks: [playable], licenseTiers: [], metadata: shared }, preserveScroll: true }); });
    await expectMetadata(shared);
    expect(document.head.querySelector('meta[name="referrer"]')).toBeNull();
    expect(screen.getByRole('slider', { name: 'Seek preview' })).toHaveValue('42');
    fireEvent.click(screen.getByRole('button', { name: 'Queue & loop' }));
    expect(within(screen.getByRole('region', { name: 'Preview queue and loop controls' })).getByRole('list').children).toHaveLength(1);
    expect(source.currentTime).toBe(42); expect(source.src).toBe(window.location.origin + playable.previewUrl);
    expect(play).toHaveBeenCalledTimes(1);
    expect(document.head.querySelector('meta[name="csrf-token"]')).toHaveAttribute('content', 'keep-me');
    expect(document.head.querySelector('meta[name="theme-color"]')).toHaveAttribute('content', '#101214');
  } finally { act(() => player.close()); }
});

it('adopts the private server fallback keys and removes private referrer metadata when visiting public content', async () => {
  document.head.insertAdjacentHTML('beforeend', `<title data-inertia="title">${privateTitle}</title><meta data-inertia="description" name="description" content="${privateDescription}"><meta data-inertia="robots" name="robots" content="noindex, nofollow"><meta data-inertia="referrer" name="referrer" content="no-referrer"><meta data-test-head name="csrf-token" content="keep-me"><meta data-test-head name="theme-color" content="#101214">`);
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async () => savedStatus());
  await mount(home, { privateReturn: true });
  await expectPrivateMetadata();
  await within(screen.getByRole('region', { name: 'Stripe test checkout' })).findByRole('status');
  expect(fetcher.mock.calls.every(([, init]) => init?.method === 'GET')).toBe(true);
  await act(async () => { router.push({ component: 'Storefront', url: '/', props: { errors: {}, tracks: [], licenseTiers: [], metadata: home }, preserveScroll: true }); });
  await expectMetadata(home);
  expect(document.head.querySelector('meta[name="referrer"]')).toBeNull();
  expect(document.head.querySelector('meta[name="csrf-token"]')).toHaveAttribute('content', 'keep-me');
});

it('adopts server fallback tags and replaces track metadata on real Inertia navigation and return home', async () => {
  document.head.insertAdjacentHTML('beforeend', '<title data-inertia="title">Server fallback</title><meta data-inertia="description" name="description" content="Server description"><link data-inertia="canonical" rel="canonical" href="/old"><meta data-test-head name="csrf-token" content="keep-me">');
  await mount(home);
  await expectMetadata(home);
  await act(async () => { router.push({ component: 'Storefront', url: '/tracks/night-signal', props: { errors: {}, tracks: [], licenseTiers: [], metadata: track }, preserveScroll: true }); });
  await expectMetadata(track);
  const second = { ...track, title: 'Second track — VASEY.AUDIO', canonicalUrl: 'http://localhost:3000/tracks/second', imageUrl: 'http://localhost:3000/media/20' };
  await act(async () => { router.push({ component: 'Storefront', url: '/tracks/second', props: { errors: {}, tracks: [], licenseTiers: [], metadata: second }, preserveScroll: true }); });
  await expectMetadata(second);
  await act(async () => { router.push({ component: 'Storefront', url: '/', props: { errors: {}, tracks: [], licenseTiers: [], metadata: home }, preserveScroll: true }); });
  await expectMetadata(home);
  expect(document.head.querySelector('meta[name="csrf-token"]')).toHaveAttribute('content', 'keep-me');
});

it('keeps markup-like public text inert in client titles and attributes', async () => {
  const text = 'Écho & "Keys" </title><script id="injected">window.pwned=1</script>';
  const metadata = { ...home, title: text, description: text, imageAlt: text };
  await mount(metadata);
  await expectMetadata(metadata);
  expect(document.head.querySelector('#injected')).toBeNull();
  expect(document.head.querySelector('[onerror], [onload]')).toBeNull();
});

it('emits the share image size and type only when the metadata gives them', async () => {
  const sizeTags = () => Object.fromEntries(['og:image:width', 'og:image:height', 'og:image:type']
    .map(key => [key, [...document.head.querySelectorAll(`meta[property="${key}"]`)].map(node => node.getAttribute('content'))]));
  const shared = { ...home, imageWidth: 1200, imageHeight: 630, imageType: 'image/jpeg' };
  await mount(shared);
  await expectMetadata(shared);
  await waitFor(() => expect(sizeTags()).toEqual({ 'og:image:width': ['1200'], 'og:image:height': ['630'], 'og:image:type': ['image/jpeg'] }));
  // Track pages share artwork of unknown size, sent as nulls: the previous page's tags must go, not linger.
  const artwork = { ...track, imageWidth: null, imageHeight: null, imageType: null };
  await act(async () => { router.push({ component: 'Storefront', url: '/tracks/night-signal', props: { errors: {}, tracks: [], licenseTiers: [], metadata: artwork }, preserveScroll: true }); });
  await expectMetadata(artwork);
  await waitFor(() => expect(sizeTags()).toEqual({ 'og:image:width': [], 'og:image:height': [], 'og:image:type': [] }));
  await act(async () => { router.push({ component: 'Storefront', url: '/', props: { errors: {}, tracks: [], licenseTiers: [], metadata: shared }, preserveScroll: true }); });
  await waitFor(() => expect(sizeTags()).toEqual({ 'og:image:width': ['1200'], 'og:image:height': ['630'], 'og:image:type': ['image/jpeg'] }));
});
