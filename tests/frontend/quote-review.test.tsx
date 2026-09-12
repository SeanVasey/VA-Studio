import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { QuoteReview } from '../../resources/js/components/QuoteReview';
import { savedCartSelection } from '../../resources/js/lib/catalog';
import { fixtureTracks } from '../../resources/js/test/fixtures';

const lines = [{ track: fixtureTracks[0], offer: fixtureTracks[0].offers[0] }];
const reviewed = (overrides = {}) => ({ id: 'review-test-only', expiresAt: new Date(Date.now() + 60000).toISOString(), currency: 'USD', subtotalMinor: 4900, taxMinor: null, totalMinor: null, taxStatus: 'unresolved', payable: false,
  items: [{ ...savedCartSelection(lines[0]), title: 'Server-verified title', artist: 'Test', licenseName: 'Reviewed license', priceMinor: 4900, currency: 'USD', deliverableRoles: ['master_wav'], features: ['Synthetic reviewed feature'], licenseUrl: '/quotes/review-test-only/offers/1/license' }], ...overrides });
const response = (quote = reviewed()) => new Response(JSON.stringify({ quote }), { status: 200 });

describe('server selection review', () => {
  it('uses server display values and sends only the selected identities with a retry key', async () => {
    const user = userEvent.setup();
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue(response());
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText('Server-verified title')).toBeInTheDocument();
    expect(screen.getAllByText(/\$49\b/).length).toBeGreaterThan(0);
    expect(screen.getByText(/Tax and final total are not determined/)).toBeInTheDocument();
    expect(screen.queryByText(/Payment successful/)).not.toBeInTheDocument();
    const init = fetch.mock.calls[0][1]!;
    expect(fetch.mock.calls[0][0]).toBe('/quotes');
    expect(init.credentials).toBe('same-origin');
    expect(JSON.parse(String(init.body))).toEqual({ items: lines.map(savedCartSelection) });
    expect((init.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[a-f0-9-]{36}$/);
  });
  it('reuses the same idempotency key after an uncertain transport failure', async () => {
    const user = userEvent.setup();
    const fetch = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('connection interrupted')).mockResolvedValueOnce(response());
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText(/Unable to complete the review/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText('SELECTION REVIEWED')).toBeInTheDocument();
    expect(fetch.mock.calls[0][1]?.headers).toEqual(fetch.mock.calls[1][1]?.headers);
  });
  it('discards an in-flight response when the cart changes and uses a new key', async () => {
    const user = userEvent.setup();
    let resolve!: (value: Response) => void;
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise<Response>(done => { resolve = done; })).mockResolvedValueOnce(new Response('{}', { status: 409 }));
    const view = render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    const changed = [{ track: lines[0].track, offer: { ...lines[0].offer, offerRevisionId: 'different-revision' } }];
    view.rerender(<QuoteReview lines={changed} designPreview={false} />);
    await act(async () => resolve(response()));
    expect(screen.queryByText('SELECTION REVIEWED')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText(/Refresh the catalog/)).toBeInTheDocument();
    expect(fetch.mock.calls[0][1]?.headers).not.toEqual(fetch.mock.calls[1][1]?.headers);
  });
  it('rejects a server response falsely marked payable', async () => {
    const user = userEvent.setup();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(reviewed({ payable: true, totalMinor: 4900 })));
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText(/Unable to complete the review/)).toBeInTheDocument();
    expect(screen.queryByText('SELECTION REVIEWED')).not.toBeInTheDocument();
  });
  it('rejects a response containing a different commercial revision', async () => {
    const user = userEvent.setup();
    const incorrect = reviewed();
    incorrect.items[0].offerRevisionId = 'another-revision';
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response(incorrect));
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText(/Unable to complete the review/)).toBeInTheDocument();
    expect(screen.queryByText('SELECTION REVIEWED')).not.toBeInTheDocument();
  });
  it('retains the retry key across closing and reopening an interrupted review', async () => {
    const user = userEvent.setup();
    const fetch = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('connection interrupted')).mockResolvedValueOnce(response());
    const first = render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText(/Unable to complete the review/)).toBeInTheDocument();
    first.unmount();
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText('SELECTION REVIEWED')).toBeInTheDocument();
    expect(fetch.mock.calls[0][1]?.headers).toEqual(fetch.mock.calls[1][1]?.headers);
  });
  it('restarts expired reviews with a new retry key without claiming a current quote', async () => {
    const user = userEvent.setup();
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(reviewed({ expiresAt: new Date(Date.now() - 1000).toISOString() }))).mockResolvedValueOnce(response());
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(await screen.findByText(/This review expired/)).toBeInTheDocument();
    expect(screen.queryByText('SELECTION REVIEWED')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Review selection again' }));
    expect(await screen.findByText('SELECTION REVIEWED')).toBeInTheDocument();
    expect(fetch.mock.calls[0][1]?.headers).not.toEqual(fetch.mock.calls[1][1]?.headers);
  });
  it('never requests a server quote for design fixtures', async () => {
    const user = userEvent.setup();
    const fetch = vi.spyOn(globalThis, 'fetch');
    render(<QuoteReview lines={lines} designPreview />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    expect(fetch).not.toHaveBeenCalled();
  });
  it('does not duplicate a pending request on repeated clicks', async () => {
    const user = userEvent.setup();
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise<Response>(() => {}));
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.dblClick(screen.getByRole('button', { name: 'Review selection' }));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
  });

  it('loads full terms from the reviewed quote URL and never substitutes the catalog terms', async () => {
    const user = userEvent.setup();
    const quote = reviewed();
    const item = quote.items[0];
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(quote)).mockResolvedValueOnce(new Response(JSON.stringify({
      quoteId: quote.id, disclosureSchema: 1, disclosureHash: 'a'.repeat(64),
      offerId: item.offerId, offerRevisionId: item.offerRevisionId, licenseVersionId: item.licenseVersionId,
      name: item.licenseName, version: 1, type: 'non-exclusive', features: item.features, deliverableRoles: item.deliverableRoles,
      termsText: 'NONBINDING frozen quote terms. Full retained policy.',
    })));
    render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    await user.click(await screen.findByRole('button', { name: 'Read full terms' }));
    expect(await screen.findByRole('region', { name: 'Published license text' })).toHaveTextContent('NONBINDING frozen quote terms. Full retained policy.');
    expect(fetcher.mock.calls[1][0]).toBe(window.location.origin + item.licenseUrl);
    expect(fetcher.mock.calls[1][1]).toEqual(expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' }));
  });

  it('discards pending terms when a quote is restarted after its selection changes', async () => {
    const user = userEvent.setup();
    const quote = reviewed();
    let finishTerms!: (response: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(quote)).mockImplementationOnce(() => new Promise(resolve => { finishTerms = resolve; }));
    const view = render(<QuoteReview lines={lines} designPreview={false} />);
    await user.click(screen.getByRole('button', { name: 'Review selection' }));
    await user.click(await screen.findByRole('button', { name: 'Read full terms' }));
    view.rerender(<QuoteReview lines={[{ ...lines[0], offer: { ...lines[0].offer, offerRevisionId: 'replacement' } }]} designPreview={false} />);
    await act(async () => { finishTerms(new Response(JSON.stringify({ termsText: 'Obsolete response' }))); });
    expect(screen.queryByText('SELECTION REVIEWED')).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Published license text' })).not.toBeInTheDocument();
    expect(screen.queryByText('Obsolete response')).not.toBeInTheDocument();
  });
});
