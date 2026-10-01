import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CheckoutReturn from '../../resources/js/Pages/CheckoutReturn';
import { defaultSiteContent } from '../../resources/js/lib/site-content';

const orderId = '74000000-0000-4000-8000-000000000001';
function response(status: 'not_started' | 'complete' | 'open') {
  return new Response(JSON.stringify({ checkout: {
    checkoutSchema: 1, orderId, id: status === 'not_started' ? null : '74000000-0000-4000-8000-000000000002',
    currency: 'USD', totalMinor: 4280, status, testOnly: true,
    paymentStatus: 'not_verified', finalizationStatus: 'not_started', contractStatus: 'not_started', fulfillmentStatus: 'not_started',
    url: status === 'open' ? 'https://checkout.stripe.com/c/pay/cs_test_ReturnFixture' : null,
    expiresAt: status === 'not_started' ? null : new Date(Date.now() + 60_000).toISOString(),
    observedAt: status === 'not_started' ? null : new Date().toISOString(),
  } }));
}

afterEach(() => window.history.replaceState({}, '', '/'));

describe('read-only checkout return', () => {
  it('displays retained verification despite contradictory redirect parameters without performing a payment operation', async () => {
    window.history.replaceState({}, '', '/checkout/return?canceled=true&payment_status=unpaid');
    const body = await response('complete').json();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ checkout: {
      ...body.checkout, paymentStatus: 'verified', finalizationStatus: 'paid', contractStatus: 'pending', fulfillmentStatus: 'pending_contracts',
    } })));
    render(<CheckoutReturn orderId={orderId} />);
    expect(await screen.findByRole('status')).toHaveTextContent('Test payment verified and order finalized');
    expect(screen.getByRole('status')).toHaveTextContent('download access is not available yet');
    expect(screen.queryByRole('button', { name: /Open Stripe|Retry Stripe|Check Stripe/ })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]).toEqual([`/orders/${orderId}/checkout`, expect.objectContaining({ method: 'GET' })]);
  });

  it.each(['success=true&payment_status=paid', 'canceled=true', 'session_id=cs_live_untrusted&status=complete'])('does not interpret redirect parameters as payment evidence: %s', async query => {
    window.history.replaceState({}, '', `/checkout/return?${query}`);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response('not_started'));
    const store = vi.spyOn(Storage.prototype, 'setItem');
    render(<CheckoutReturn orderId={orderId} />);
    await act(async () => {});
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0]).toEqual([`/orders/${orderId}/checkout`, expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' })]);
    expect(fetcher.mock.calls[0][1]?.method ?? 'GET').toBe('GET');
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByText(/payment successful|payment verified|order paid|license granted|download ready/i)).not.toBeInTheDocument();
    expect(store).not.toHaveBeenCalled();
  });

  it('shows a completed provider session without asserting payment or starting fulfillment', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response('complete'));
    render(<CheckoutReturn orderId={orderId} />);
    await screen.findByRole('button', { name: 'Check Stripe test checkout status' });
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls.every(([, init]) => (init?.method ?? 'GET') === 'GET')).toBe(true);
    expect(screen.queryByRole('link', { name: 'Continue to Stripe test checkout' })).not.toBeInTheDocument();
    expect(screen.queryByText(/payment successful|payment verified|order paid|license granted|download ready/i)).not.toBeInTheDocument();
  });
});

describe('private return page chrome', () => {
  it('reuses approved chrome, a named focusable main and inert public text without another request', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response('not_started'));
    const hostile = '<img src=x onerror=alert(1)>';
    const siteContent = { ...defaultSiteContent, navigation: [{ label: hostile, href: '/#catalog' as const }], footer: { description: hostile } };
    render(<CheckoutReturn orderId={orderId} siteContent={siteContent} />);
    expect(screen.getByRole('heading', { level: 1, name: 'CHECKOUT STATUS' })).toHaveFocus();
    const main = screen.getByRole('main', { name: 'CHECKOUT STATUS' });
    fireEvent.click(screen.getByRole('link', { name: 'Skip to content' }));
    expect(main).toHaveFocus();
    expect(screen.getByRole('banner')).toBeInTheDocument();
    expect(screen.getByRole('contentinfo')).toBeInTheDocument();
    for (const logo of screen.getAllByRole('img', { name: 'VASEY.AUDIO' })) {
      expect(logo).toHaveAttribute('src', '/brand/vasey-audio-logo.png');
      expect(logo).toHaveAttribute('width', '420'); expect(logo).toHaveAttribute('height', '100');
    }
    expect(screen.getAllByRole('link', { name: hostile })).toHaveLength(2);
    expect(screen.getByRole('contentinfo')).toHaveTextContent(hostile);
    expect(document.querySelector('[onerror]')).toBeNull();
    expect(screen.getByLabelText('Audio preview player')).toBeInTheDocument();
    await within(screen.getByRole('region', { name: 'Stripe test checkout' })).findByRole('status');
    expect(fetcher.mock.calls).toEqual([[`/orders/${orderId}/checkout`, expect.objectContaining({ method: 'GET', cache: 'no-store' })]]);
    expect(screen.queryByRole('button', { name: 'Open Stripe test checkout' })).not.toBeInTheDocument();
  });

  it('keeps modified clicks native and gives ordinary Inertia visits a reachable destination heading', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response('not_started'));
    const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
    render(<CheckoutReturn orderId={orderId} />);
    const licensing = within(screen.getByRole('navigation', { name: 'Main navigation' })).getByRole('link', { name: 'Licensing' });
    fireEvent.click(licensing, { ctrlKey: true });
    expect(visit).not.toHaveBeenCalled();
    fireEvent.click(licensing);
    expect(visit).toHaveBeenCalledTimes(1);
    expect(visit.mock.calls[0][0]).toBe(window.location.origin + '/#licenses');
    expect(visit.mock.calls[0][1]).toEqual(expect.objectContaining({ preserveScroll: false }));
    const section = document.createElement('section'); section.id = 'licenses';
    const heading = document.createElement('h2'); heading.textContent = 'Public license comparison'; section.appendChild(heading); document.body.appendChild(section);
    try {
      act(() => visit.mock.calls[0][1]?.onSuccess?.({} as Page));
      expect(heading).toHaveAttribute('tabindex', '-1'); expect(heading).toHaveFocus();
    } finally { section.remove(); }
    const menu = screen.getByRole('button', { name: 'Menu' });
    fireEvent.click(menu);
    expect(screen.getByRole('button', { name: 'Close menu' })).toHaveAttribute('aria-expanded', 'true');
    fireEvent.keyDown(screen.getByRole('navigation', { name: 'Main navigation' }), { key: 'Escape' });
    expect(screen.getByRole('button', { name: 'Menu' })).toHaveAttribute('aria-expanded', 'false');
    expect(screen.getByRole('button', { name: 'Menu' })).toHaveFocus();
  });
});
