import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createInertiaApp, router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import Editorial from '../../resources/js/Pages/Editorial';
import CheckoutReturn from '../../resources/js/Pages/CheckoutReturn';
import { InstallApp } from '../../resources/js/components/InstallApp';
import { createInstallController, type InstallState } from '../../resources/js/lib/install-app';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { player } from '../../resources/js/lib/audio';
import { fixtureTracks } from '../../resources/js/test/fixtures';

const controllers: ReturnType<typeof createInstallController>[] = [];
let media: MediaQueryList;
let isStandalone = false;
const metadata = { title: 'Public store', description: 'Public description', canonicalUrl: '/', imageUrl: '/images/storefront-hero.jpg', imageAlt: 'Studio', type: 'website', robots: 'noindex, nofollow' };
const publicProps = { errors: {}, tracks: [], licenseTiers: [], metadata, siteContent: defaultSiteContent };
const orderId = '74000000-0000-4000-8000-000000000011';

beforeEach(() => {
  isStandalone = false;
  media = new EventTarget() as MediaQueryList;
  Object.defineProperty(media, 'matches', { get: () => isStandalone });
  vi.stubGlobal('matchMedia', vi.fn(() => media));
});
afterEach(() => {
  controllers.splice(0).forEach(controller => controller.dispose());
  vi.unstubAllGlobals();
  document.head.querySelectorAll('[data-inertia], [data-install-test]').forEach(node => node.remove());
  window.history.replaceState({}, '', '/');
});

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
function promptEvent(prompt: () => Promise<unknown> = vi.fn(async () => ({ outcome: 'accepted' })), userChoice?: Promise<{ outcome: string }>) {
  const event = Object.assign(new Event('beforeinstallprompt', { cancelable: true }), { prompt, userChoice });
  window.dispatchEvent(event);
  return event;
}
function controller() {
  const states: InstallState[] = [];
  const current = createInstallController(window, state => states.push(state));
  controllers.push(current);
  return { ...current, states };
}
async function mount(component = 'Install', props: Record<string, unknown> = {}) {
  const host = document.createElement('div'); host.id = 'install-test-app'; document.body.appendChild(host);
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  const page: Page = { component, props: { errors: {}, ...props }, url: '/', version: null, rescuedProps: [], flash: {}, rememberedState: {} };
  await act(async () => {
    await createInertiaApp({
      id: host.id, page, progress: false,
      resolve: name => name === 'Storefront' ? Storefront : name === 'Editorial' ? Editorial : name === 'CheckoutReturn' ? CheckoutReturn : InstallApp,
      setup({ App, props: appProps }) { render(<App {...appProps} />, { container: host }); },
    });
  });
}
async function expectInstallHead(present: boolean) {
  await waitFor(() => {
    for (const [rel, href, key] of [['manifest', '/manifest.webmanifest', 'install:manifest'], ['apple-touch-icon', '/brand/apple-touch-icon.png', 'install:touch-icon']]) {
      const tags = document.head.querySelectorAll(`link[rel="${rel}"]`);
      expect(tags).toHaveLength(present ? 1 : 0);
      if (present) { expect(tags[0]).toHaveAttribute('href', href); expect(tags[0]).toHaveAttribute('data-inertia', key); }
    }
  });
}

describe('public-page installation lifetime (synthetic browser events prove state only)', () => {
  it('does nothing without a callable captured prompt, even when an unrelated event is dispatched', async () => {
    const current = controller();
    const invalid = new Event('beforeinstallprompt', { cancelable: true });
    window.dispatchEvent(invalid);
    await current.install();
    expect(invalid.defaultPrevented).toBe(false);
    expect(current.states).toEqual(['manual']);
  });

  it('never prompts on capture and consumes the event synchronously before two competing clicks', async () => {
    const current = controller(), result = deferred<{ outcome: string }>();
    const event = promptEvent(vi.fn(() => result.promise));
    expect(event.defaultPrevented).toBe(true); expect(event.prompt).not.toHaveBeenCalled();
    const first = current.install(), second = current.install();
    expect(event.prompt).toHaveBeenCalledTimes(1); expect(current.states.at(-1)).toBe('pending');
    result.resolve({ outcome: 'accepted' }); await Promise.all([first, second]);
    expect(current.states.at(-1)).toBe('accepted'); expect(current.states).not.toContain('installed');
    await current.install(); expect(event.prompt).toHaveBeenCalledTimes(1);
    window.dispatchEvent(event); await current.install();
    expect(current.states.at(-1)).toBe('accepted'); expect(event.prompt).toHaveBeenCalledTimes(1);
  });

  it('uses the older userChoice promise and requires a new event after dismissal', async () => {
    const current = controller(), choice = deferred<{ outcome: string }>();
    const event = promptEvent(vi.fn(async () => undefined), choice.promise);
    const pending = current.install(); choice.resolve({ outcome: 'dismissed' }); await pending;
    expect(current.states.at(-1)).toBe('dismissed');
    await current.install(); expect(event.prompt).toHaveBeenCalledTimes(1);
    promptEvent(); expect(current.states.at(-1)).toBe('available');
  });

  it.each(['throw', 'reject'])('offers recovery for a %s without retaining a consumed event', async mode => {
    const current = controller();
    const event = promptEvent(vi.fn(() => { if (mode === 'throw') throw new Error('Unavailable'); return Promise.reject(new Error('Unavailable')); }));
    await current.install(); expect(current.states.at(-1)).toBe('error');
    await current.install(); expect(event.prompt).toHaveBeenCalledTimes(1);
  });

  it.each(['accepted', 'dismissed', 'rejected'])('lets observed appinstalled beat a late %s choice', async outcome => {
    const current = controller(), choice = deferred<{ outcome: string }>();
    promptEvent(vi.fn(() => choice.promise)); const pending = current.install();
    window.dispatchEvent(new Event('appinstalled'));
    if (outcome === 'rejected') choice.reject(new Error('Too late')); else choice.resolve({ outcome });
    await pending; expect(current.states.at(-1)).toBe('installed');
    const event = promptEvent(); expect(event.prompt).not.toHaveBeenCalled(); expect(event.defaultPrevented).toBe(false);
  });

  it('observes existing standalone mode and later standalone changes without prompting', async () => {
    isStandalone = true; const initial = controller();
    expect(initial.states).toEqual(['installed']); initial.dispose();
    isStandalone = false; const current = controller(), result = deferred<{ outcome: string }>();
    promptEvent(vi.fn(() => result.promise)); const pending = current.install();
    isStandalone = true; media.dispatchEvent(new Event('change'));
    result.resolve({ outcome: 'dismissed' }); await pending;
    expect(current.states.at(-1)).toBe('installed');
  });

  it('uses Safari standalone evidence even without matchMedia', () => {
    vi.stubGlobal('matchMedia', undefined);
    Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: true });
    try { expect(controller().states).toEqual(['installed']); }
    finally { Reflect.deleteProperty(window.navigator, 'standalone'); }
  });

  it('uses and removes legacy media listeners while giving standalone changes precedence', () => {
    const legacy = { matches: false, addListener: vi.fn(), removeListener: vi.fn() };
    vi.stubGlobal('matchMedia', () => legacy);
    const current = controller(); expect(current.states).toEqual(['manual']);
    legacy.matches = true; legacy.addListener.mock.calls[0][0]();
    expect(current.states.at(-1)).toBe('installed'); current.dispose();
    expect(legacy.removeListener).toHaveBeenCalledWith(legacy.addListener.mock.calls[0][0]);
  });

  it('keeps window events/manual recovery usable without media listeners and rechecks standalone before prompting', async () => {
    const partial = { matches: false };
    vi.stubGlobal('matchMedia', () => partial);
    const removed = vi.spyOn(window, 'removeEventListener');
    const current = controller(); const event = promptEvent();
    expect(current.states.at(-1)).toBe('available'); partial.matches = true;
    await current.install(); expect(current.states.at(-1)).toBe('installed'); expect(event.prompt).not.toHaveBeenCalled();
    current.dispose(); expect(removed.mock.calls.map(([type]) => type)).toContain('beforeinstallprompt');
  });

  it.each(['accepted', 'dismissed', 'rejected'])('removes all listeners and ignores a late %s after disposal', async outcome => {
    const removed = vi.spyOn(window, 'removeEventListener'), mediaRemoved = vi.spyOn(media, 'removeEventListener');
    const current = controller(), result = deferred<{ outcome: string }>();
    promptEvent(vi.fn(() => result.promise)); const pending = current.install(); current.dispose();
    const before = [...current.states];
    window.dispatchEvent(new Event('appinstalled')); isStandalone = true; media.dispatchEvent(new Event('change'));
    const later = promptEvent();
    if (outcome === 'rejected') result.reject(new Error('Too late')); else result.resolve({ outcome });
    await pending; await current.install();
    expect(current.states).toEqual(before); expect(later.defaultPrevented).toBe(false);
    expect(removed.mock.calls.map(([type]) => type)).toContain('beforeinstallprompt');
    expect(removed.mock.calls.map(([type]) => type)).toContain('appinstalled');
    expect(mediaRemoved.mock.calls.map(([type]) => type)).toContain('change');
  });
});

it('keeps manual guidance accessible without support and never treats accepted choice as installed', async () => {
  await mount('Storefront', publicProps);
  const disclosure = screen.getByText('Add VASEY.AUDIO to your device');
  expect(disclosure.tagName).toBe('SUMMARY'); expect(disclosure.closest('details')).not.toHaveAttribute('open');
  fireEvent.click(disclosure);
  expect(screen.getByText(/internet connection is required/)).toBeVisible();
  expect(screen.getByText(/Open as Web App if shown/)).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Install VASEY.AUDIO' })).not.toBeInTheDocument();
  let event!: ReturnType<typeof promptEvent>;
  act(() => { event = promptEvent(); });
  await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Install VASEY.AUDIO' })); });
  expect(event.prompt).toHaveBeenCalledTimes(1);
  expect(within(disclosure.closest('details')!).getByRole('status')).toHaveTextContent('Installation request accepted');
  expect(screen.queryByText(/is installed|successfully installed/i)).not.toBeInTheDocument();
  await expectInstallHead(true);
  act(() => { window.dispatchEvent(new Event('appinstalled')); });
  expect(screen.queryByText('Add VASEY.AUDIO to your device')).not.toBeInTheDocument();
  await expectInstallHead(true);
});

it.each([{ sitePreview: true }, { designPreview: true }])('excludes guidance and metadata from a private/design storefront: %j', async flags => {
  await mount('Storefront', { ...publicProps, ...flags });
  expect(screen.queryByText('Add VASEY.AUDIO to your device')).not.toBeInTheDocument();
  await expectInstallHead(false);
  const event = promptEvent(); expect(event.defaultPrevented).toBe(false);
});

it('gates editorial previews explicitly and restores installation metadata on a public editorial visit', async () => {
  const editorial = { section: 'about', kind: 'page', path: '/about', title: 'About', description: 'About the store', paragraphs: [], entries: [], email: null, contactHref: null, video: null };
  await mount('Editorial', { ...publicProps, editorial, sitePreview: true, sitePreviewBase: '/admin/site-releases/1/preview' });
  await expectInstallHead(false); expect(screen.queryByText('Add VASEY.AUDIO to your device')).not.toBeInTheDocument();
  await act(async () => { router.push({ component: 'Editorial', url: '/about', props: { ...publicProps, editorial, sitePreview: false } }); });
  await expectInstallHead(true); expect(screen.getByText('Add VASEY.AUDIO to your device')).toBeInTheDocument();
});

it('adopts server keys and removes/re-adds installation head and state across real Inertia private return navigation', async () => {
  document.head.insertAdjacentHTML('beforeend', '<link data-inertia="install:manifest" rel="manifest" href="/manifest.webmanifest"><link data-inertia="install:touch-icon" rel="apple-touch-icon" sizes="180x180" href="/brand/apple-touch-icon.png"><meta data-install-test name="theme-color" content="#052e3a"><meta data-install-test name="csrf-token" content="unchanged">');
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ checkout: { checkoutSchema: 1, orderId, id: null, currency: 'USD', totalMinor: 4280, status: 'not_started', testOnly: true, paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started', url: null, expiresAt: null, observedAt: null } })));
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {});
  vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
  const play = vi.spyOn(HTMLMediaElement.prototype, 'play').mockImplementation(function (this: HTMLMediaElement) { this.dispatchEvent(new Event('playing')); return Promise.resolve(); });
  const track = { ...fixtureTracks[0], previewUrl: '/media/tagged-preview' };
  try {
    await mount('Storefront', { ...publicProps, tracks: [track] }); await expectInstallHead(true);
    await act(() => player.play(track));
    const audio = play.mock.contexts[0] as HTMLMediaElement;
    let event!: ReturnType<typeof promptEvent>; const result = deferred<{ outcome: string }>();
    act(() => { event = promptEvent(vi.fn(() => result.promise)); });
    fireEvent.click(screen.getByText('Add VASEY.AUDIO to your device'));
    act(() => { fireEvent.click(screen.getByRole('button', { name: 'Install VASEY.AUDIO' })); });
    await act(async () => { router.push({ component: 'CheckoutReturn', url: `/orders/${orderId}/checkout/return?success=true`, props: { errors: {}, orderId, siteContent: defaultSiteContent } }); });
    await expectInstallHead(false);
    expect(screen.queryByText('Add VASEY.AUDIO to your device')).not.toBeInTheDocument();
    await waitFor(() => expect(document.head.querySelector('meta[name="referrer"]')).toHaveAttribute('content', 'no-referrer'));
    expect(document.head.innerHTML).not.toContain(orderId);
    await act(async () => { result.resolve({ outcome: 'accepted' }); });
    await act(async () => { router.push({ component: 'Storefront', url: '/', props: { ...publicProps, tracks: [track] } }); });
    await expectInstallHead(true);
    expect(screen.queryByRole('button', { name: 'Install VASEY.AUDIO' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Installation request accepted/)).not.toBeInTheDocument();
    expect(document.head.querySelector('meta[name="referrer"]')).toBeNull();
    expect(document.head.querySelectorAll('meta[name="theme-color"]')).toHaveLength(1);
    expect(document.head.querySelector('meta[name="theme-color"]')).toHaveAttribute('content', '#052e3a');
    expect(document.head.querySelector('meta[name="csrf-token"]')).toHaveAttribute('content', 'unchanged');
    expect(play).toHaveBeenCalledTimes(1); expect(audio.src).toBe(window.location.origin + track.previewUrl);
    expect(event.prompt).toHaveBeenCalledTimes(1);
  } finally { act(() => player.close()); }
});
