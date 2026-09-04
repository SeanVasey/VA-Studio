import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import Storefront from '../../resources/js/Pages/Storefront';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';

function renderCatalog() { return render(<Storefront tracks={fixtureTracks} licenseTiers={fixtureTiers} />); }

describe('storefront user flows', () => {
  it('renders an honest empty production catalog without invented products, prices or playback', () => {
    render(<Storefront tracks={[]} licenseTiers={[]} />);
    expect(screen.getByRole('heading', { name: 'A NEW CHAPTER IN SOUND.' })).toBeInTheDocument();
    expect(screen.queryByText(/\$29/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Sample catalog/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Play / })).not.toBeInTheDocument();
  });
  it('filters the catalog and recovers from an empty search', async () => {
    const user = userEvent.setup(); renderCatalog();
    await user.type(screen.getByRole('searchbox', { name: 'Search tracks' }), 'quiet');
    expect(screen.getAllByRole('article', { name: /\[DEMO\]/ })).toHaveLength(1);
    await user.clear(screen.getByRole('searchbox'));
    await user.type(screen.getByRole('searchbox'), 'not-a-track');
    expect(screen.getByRole('heading', { name: 'NO MATCHES. KEEP EXPLORING.' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Reset filters/ }));
    expect(screen.getAllByRole('article', { name: /\[DEMO\]/ })).toHaveLength(4);
  });
  it('disables playback for missing previews with a reason', () => {
    renderCatalog();
    expect(screen.getAllByRole('button', { name: /^Preview unavailable for / })).toHaveLength(4);
    for (const button of screen.getAllByRole('button', { name: /^Preview unavailable for / })) expect(button).toBeDisabled();
  });
  it('adds, changes and removes an exact product-license selection', async () => {
    const user = userEvent.setup(); renderCatalog();
    await user.click(screen.getAllByRole('button', { name: /^Choose license for / })[0]);
    const dialog = screen.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.' });
    await user.click(within(dialog).getByRole('radio', { name: /Premium license/ }));
    await user.click(within(dialog).getByRole('button', { name: /Add license/ }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Open cart, 1 item' }));
    expect(within(screen.getByRole('dialog')).getByText('Premium license')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Change license' }));
    await user.click(screen.getByRole('radio', { name: /Trackout license/ }));
    await user.click(screen.getByRole('button', { name: /Add license/ }));
    await user.click(screen.getByRole('button', { name: 'Open cart, 1 item' }));
    expect(within(screen.getByRole('dialog')).getByText('Trackout license')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /^Remove Midnight/ }));
    expect(screen.getByRole('heading', { name: 'ROOM FOR YOUR NEXT RECORD.' })).toBeInTheDocument();
  });
  it('handles checkout unavailability without producing a success state or losing the cart', async () => {
    const user = userEvent.setup();
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ message: 'Unavailable' }), { status: 503 }));
    renderCatalog();
    await user.click(screen.getAllByRole('button', { name: /^Choose license for / })[0]);
    await user.click(screen.getByRole('button', { name: /Add license/ }));
    await user.click(screen.getByRole('button', { name: 'Open cart, 1 item' }));
    await user.click(screen.getByRole('button', { name: 'Check checkout availability' }));
    expect(await screen.findByText(/Checkout is not available yet. No payment was taken./)).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith('/checkout', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }));
    const request = JSON.parse(String(fetchMock.mock.calls[0][1]?.body));
    expect(request.items[0]).toEqual({ trackId: fixtureTracks[0].id, offerId: fixtureTracks[0].offers[0].id, licenseVersionId: fixtureTracks[0].offers[0].licenseVersionId });
    expect(request.items[0]).not.toHaveProperty('priceMinor');
    expect(screen.getByRole('button', { name: 'Open cart, 1 item' })).toBeInTheDocument();
    expect(screen.queryByText('Payment successful')).not.toBeInTheDocument();
  });
  it('offers an actual track link when clipboard permission fails', async () => {
    const user = userEvent.setup();
    vi.spyOn(navigator.clipboard, 'writeText').mockRejectedValue(new Error('Permission denied'));
    renderCatalog();
    await user.click(screen.getByRole('button', { name: `Share ${fixtureTracks[0].title}` }));
    expect(await screen.findByRole('link', { name: /Open track link/ })).toHaveAttribute('href', `${window.location.origin}${fixtureTracks[0].shareUrl}`);
  });
  it('does not restore a persisted cart with an unknown license version', () => {
    sessionStorage.setItem('vaseyaudio-cart-v1', JSON.stringify([{ trackId: fixtureTracks[0].id, offerId: fixtureTracks[0].offers[0].id, licenseVersionId: 'superseded-version' }]));
    renderCatalog();
    expect(screen.getByRole('button', { name: 'Open cart, 0 items' })).toBeInTheDocument();
  });
});
