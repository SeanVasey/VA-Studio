import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import Storefront from '../../resources/js/Pages/Storefront';
import { fixtureTracks, fixtureTiers } from '../../resources/js/test/fixtures';
import type { CatalogPage } from '../../resources/js/lib/catalog';

const track = { ...fixtureTracks[0], id: '1', offers: fixtureTracks[0].offers.map((offer, index) => ({ ...offer, licenseUrl: `/tracks/test/offers/${index + 1}/license` })) };
const catalogPage: CatalogPage = { filters: { q: 'quiet', genre: 'Cinematic', sort: 'title' }, previousUrl: null, nextUrl: null, restartUrl: '/?q=quiet&genre=Cinematic&sort=title', currentUrl: '/?q=quiet&genre=Cinematic&sort=title&cursor=opaque', hasCursor: true };

it('renders a directly selected track outside the current catalog page and preserves the return query', async () => {
  const user = userEvent.setup();
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
  render(<Storefront tracks={[]} licenseTiers={fixtureTiers} selectedTrack={track} selectedTrackSlug={track.slug} catalogPage={catalogPage} />);
  expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(track.title);
  expect(screen.getByText(`${track.bpm} BPM`)).toBeInTheDocument();
  expect(screen.getByRole('list', { name: 'Track tags' })).toHaveTextContent(track.tags[0]);
  expect(document.querySelectorAll('#licenses')).toHaveLength(1);
  expect(screen.getByRole('button', { name: `Play ${track.title}` })).toBeDisabled();
  const back = screen.getByRole('link', { name: 'Back to results' });
  expect(back).toHaveAttribute('href', catalogPage.currentUrl);
  await user.click(back);
  expect(visit).toHaveBeenCalledWith(window.location.origin + catalogPage.currentUrl, expect.objectContaining({ preserveState: true }));
});

it('opens the chosen published offer with full terms and saves only its exact identifiers', async () => {
  const selected = track.offers[1];
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({
    offerId: selected.id, offerRevisionId: selected.offerRevisionId, licenseVersionId: selected.licenseVersionId,
    name: selected.licenseName, version: 1, type: 'non-exclusive', features: ['Synthetic WAV'], deliverableRoles: selected.deliverableRoles, termsText: 'NONBINDING full selected policy.',
  })));
  const user = userEvent.setup();
  // Local fixtures bypass server reconciliation; selection identity still uses the production cart.
  render(<Storefront tracks={[]} licenseTiers={fixtureTiers} selectedTrack={track} selectedTrackSlug={track.slug} />);
  await user.click(screen.getAllByRole('button', { name: 'Read terms & choose' })[1]);
  const dialog = screen.getByRole('dialog', { name: 'CHOOSE YOUR LICENSE.' });
  expect(within(dialog).getByRole('radio', { name: /Premium license/ })).toBeChecked();
  expect(await within(dialog).findByRole('region', { name: 'Published license text' })).toHaveTextContent('NONBINDING full selected policy.');
  await user.click(within(dialog).getByRole('button', { name: /Add license/ }));
  expect(screen.getByRole('button', { name: 'Open cart, 1 item' })).toBeInTheDocument();
  expect(JSON.parse(sessionStorage.getItem('vaseyaudio-cart-v1')!)).toEqual([{ trackId: track.id, offerId: selected.id, offerRevisionId: selected.offerRevisionId, licenseVersionId: selected.licenseVersionId }]);
});
