import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import { LicenseDisclosure } from '../../resources/js/components/LicenseDisclosure';
import { fixtureTracks } from '../../resources/js/test/fixtures';
import type { Offer } from '../../resources/js/lib/catalog';

const offer = { ...fixtureTracks[0].offers[0], licenseUrl: '/tracks/test/offers/1/license' };
const second = { ...fixtureTracks[0].offers[1], licenseUrl: '/tracks/test/offers/2/license' };
const terms = (selected: Offer, text = 'NONBINDING synthetic policy. Retained text and conditions.') => ({
  offerId: selected.id, offerRevisionId: selected.offerRevisionId, licenseVersionId: selected.licenseVersionId,
  name: selected.licenseName, version: 1, type: 'non-exclusive', features: ['Synthetic WAV'], deliverableRoles: ['master_wav'], termsText: text,
});
const response = (selected = offer, text?: string) => new Response(JSON.stringify(terms(selected, text)));

it('loads terms only when requested and renders the entire policy as inert text', async () => {
  const source = 'NONBINDING test policy\n<script>window.injected=true</script>\nExact final clause.';
  const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(offer, source));
  const user = userEvent.setup();
  render(<LicenseDisclosure offer={offer} />);
  expect(fetcher).not.toHaveBeenCalled();
  await user.click(screen.getByRole('button', { name: 'Read full terms' }));
  const region = await screen.findByRole('region', { name: 'Published license text' });
  expect(region.textContent).toBe(source);
  expect(region.querySelector('script')).toBeNull();
  expect(fetcher).toHaveBeenCalledWith(window.location.origin + offer.licenseUrl, expect.objectContaining({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }));
  expect(screen.getByRole('button', { name: 'Hide full terms' })).toHaveAttribute('aria-expanded', 'true');
  await user.click(screen.getByRole('button', { name: 'Hide full terms' }));
  expect(screen.queryByRole('region', { name: 'Published license text' })).not.toBeInTheDocument();
});

it('offers recovery after a failed request without substituting generic terms', async () => {
  const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(response());
  const user = userEvent.setup();
  render(<LicenseDisclosure offer={offer} defaultOpen />);
  expect(await screen.findByRole('alert')).toHaveTextContent('These terms could not be loaded.');
  expect(screen.queryByRole('region', { name: 'Published license text' })).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Retry terms' }));
  expect(await screen.findByRole('region', { name: 'Published license text' })).toHaveTextContent('Retained text and conditions.');
  expect(fetcher).toHaveBeenCalledTimes(2);
});

it.each(['offerId', 'offerRevisionId', 'licenseVersionId'])('rejects a disclosure for a different %s', async field => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ...terms(offer), [field]: 'unrelated' })));
  render(<LicenseDisclosure offer={offer} defaultOpen />);
  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(screen.queryByRole('region', { name: 'Published license text' })).not.toBeInTheDocument();
});

it('ignores an older response when the user selects a different license', async () => {
  let finishOld!: (value: Response) => void;
  const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve; })).mockResolvedValueOnce(response(second, 'SECOND synthetic policy'));
  const view = render(<LicenseDisclosure offer={offer} defaultOpen />);
  expect(screen.getByRole('status')).toHaveTextContent('Loading');
  view.rerender(<LicenseDisclosure offer={second} defaultOpen />);
  expect(await screen.findByRole('region', { name: 'Published license text' })).toHaveTextContent('SECOND synthetic policy');
  await act(async () => { finishOld(response(offer, 'OLD synthetic policy')); });
  expect(screen.getByRole('region', { name: 'Published license text' })).not.toHaveTextContent('OLD');
  expect((fetcher.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
});

it('removes the previous terms immediately while a changed revision loads', async () => {
  let finishNew!: (value: Response) => void;
  vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response()).mockImplementationOnce(() => new Promise(resolve => { finishNew = resolve; }));
  const view = render(<LicenseDisclosure offer={offer} defaultOpen />);
  await screen.findByRole('region', { name: 'Published license text' });
  view.rerender(<LicenseDisclosure offer={second} defaultOpen />);
  expect(screen.queryByRole('region', { name: 'Published license text' })).not.toBeInTheDocument();
  expect(screen.getByRole('status')).toHaveTextContent('Loading');
  await act(async () => { finishNew(response(second)); });
  await waitFor(() => expect(screen.getByRole('heading', { level: 3 })).toHaveTextContent(second.licenseName));
});

it('does not fetch a foreign-origin terms URL', async () => {
  const fetcher = vi.spyOn(globalThis, 'fetch');
  render(<LicenseDisclosure offer={{ ...offer, licenseUrl: 'https://foreign.example/terms' }} defaultOpen />);
  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(fetcher).not.toHaveBeenCalled();
});

it.each([
  { quoteId: 'another-quote', disclosureSchema: 1, disclosureHash: 'a'.repeat(64) },
  { quoteId: 'selected-quote', disclosureSchema: 2, disclosureHash: 'a'.repeat(64) },
  { quoteId: 'selected-quote', disclosureSchema: 1, disclosureHash: 'invalid' },
  { quoteId: 'selected-quote', disclosureSchema: 3, testOnly: true, disclosureHash: 'a'.repeat(64) },
])('rejects a mismatched quote disclosure envelope: %j', async envelope => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ...terms(offer), ...envelope })));
  render(<LicenseDisclosure offer={offer} quoteId="selected-quote" defaultOpen />);
  expect(await screen.findByRole('alert')).toBeInTheDocument();
  expect(screen.queryByRole('region', { name: 'Published license text' })).not.toBeInTheDocument();
});

it('renders a version-two owned exclusive disclosure with its explicit test boundary', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ...terms(offer), type: 'exclusive',
    quoteId: 'selected-quote', disclosureSchema: 2, testOnly: true, disclosureHash: 'a'.repeat(64) })));
  render(<LicenseDisclosure offer={offer} quoteId="selected-quote" defaultOpen />);
  expect(await screen.findByRole('region', { name: 'Published license text' })).toHaveTextContent('Retained text and conditions.');
  expect(screen.getByText('Test selection only. Purchasing is unavailable.')).toBeInTheDocument();
});
