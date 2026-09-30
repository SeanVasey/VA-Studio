import { act, render, waitFor } from '@testing-library/react';
import { createInertiaApp, router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { afterEach, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import type { PageMetadata } from '../../resources/js/lib/catalog';

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

async function mount(metadata: PageMetadata) {
  const host = document.createElement('div'); host.id = 'metadata-app'; document.body.appendChild(host);
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  const page: Page = {
    component: 'Storefront', props: { errors: {}, tracks: [], licenseTiers: [], metadata },
    url: '/', version: null, rescuedProps: [], flash: {}, rememberedState: {},
  };
  await act(async () => {
    await createInertiaApp({
      id: host.id, page, progress: false, title: title => title || home.title, resolve: () => Storefront,
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
