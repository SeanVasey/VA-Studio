import { act, render, waitFor } from '@testing-library/react';
import { createInertiaApp, router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { afterEach, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import type { PageMetadata } from '../../resources/js/lib/catalog';

// Shapes match PublicStructuredData: ASCII JSON with <, > and & as \u escapes.
const homeData = '{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"VASEY.AUDIO","url":"http://localhost:3000/"}]}';
const trackData = '{"@context":"https://schema.org","@graph":[{"@type":"MusicRecording","name":"Night \\u003C/script\\u003E\\u003Cscript\\u003Ewindow.pwned=1\\u003C/script\\u003E \\u0026 Day"}]}';

const home: PageMetadata = {
  title: 'VASEY.AUDIO — Sound with intent', description: 'Original music by Sean Vasey.',
  canonicalUrl: 'http://localhost:3000/', imageUrl: 'http://localhost:3000/images/storefront-hero.jpg',
  imageAlt: 'Studio artwork', type: 'website', robots: 'index, follow', structuredData: homeData,
};
const track: PageMetadata = {
  ...home, title: 'Night Signal — VASEY.AUDIO', canonicalUrl: 'http://localhost:3000/tracks/night-signal',
  type: 'music.song', structuredData: trackData,
};

function blocks() {
  return Array.from(document.head.querySelectorAll('script[type="application/ld+json"]'));
}

async function mount(metadata: PageMetadata) {
  const host = document.createElement('div'); host.id = 'structured-data-app'; document.body.appendChild(host);
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

async function visit(metadata: PageMetadata, url: string) {
  await act(async () => { router.push({ component: 'Storefront', url, props: { errors: {}, tracks: [], licenseTiers: [], metadata }, preserveScroll: true }); });
}

afterEach(() => {
  document.head.querySelectorAll('[data-inertia]').forEach(node => node.remove());
  delete (window as unknown as { pwned?: number }).pwned;
  window.history.replaceState({}, '', '/');
});

it('adopts the server JSON-LD block, replaces it on navigation and removes it from pages without one', async () => {
  document.head.insertAdjacentHTML('beforeend', `<script data-inertia="structured-data" type="application/ld+json">${homeData}</script>`);
  const server = blocks()[0];
  await mount(home);
  await waitFor(() => expect(document.title).toBe(home.title));
  await waitFor(() => { expect(blocks()).toHaveLength(1); expect(blocks()[0].textContent).toBe(homeData); });
  // Identical content is adopted in place, not duplicated or rewritten.
  expect(blocks()[0]).toBe(server);

  await visit(track, '/tracks/night-signal');
  await waitFor(() => { expect(blocks()).toHaveLength(1); expect(blocks()[0].textContent).toBe(trackData); });
  expect(blocks()[0]).toHaveAttribute('data-inertia', 'structured-data');
  expect(JSON.parse(blocks()[0].textContent ?? '')['@graph'][0].name).toBe('Night </script><script>window.pwned=1</script> & Day');
  expect(document.head.querySelectorAll('script:not([type="application/ld+json"])')).toHaveLength(0);
  expect((window as unknown as { pwned?: number }).pwned).toBeUndefined();

  await visit({ ...home, structuredData: null }, '/about');
  await waitFor(() => expect(blocks()).toHaveLength(0));
  await visit(home, '/');
  await waitFor(() => { expect(blocks()).toHaveLength(1); expect(blocks()[0].textContent).toBe(homeData); });
});

it('drops structured data that is not escaped for a raw script context', async () => {
  await mount({ ...home, structuredData: '{"name":"</script><script>window.pwned=1</script>"}' });
  await waitFor(() => expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', home.canonicalUrl));
  expect(blocks()).toHaveLength(0);
  expect(document.head.querySelectorAll('script')).toHaveLength(0);
  expect((window as unknown as { pwned?: number }).pwned).toBeUndefined();
  await visit({ ...home, structuredData: '{"name":"A & B"}' }, '/');
  await waitFor(() => expect(document.head.querySelector('link[rel="canonical"]')).toHaveAttribute('href', home.canonicalUrl));
  expect(blocks()).toHaveLength(0);
});
