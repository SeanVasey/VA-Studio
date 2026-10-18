import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PaidTaxJourney, validTaxAuthorization, validTaxListing, validTaxOrigin, validTaxStatus, type TaxOrigin } from '../../resources/js/components/PaidTaxJourney';
import PaidTaxLicenses from '../../resources/js/Pages/PaidTaxLicenses';

vi.mock('@inertiajs/react', () => ({ Head: ({ children }: { children?: unknown }) => <>{children}</> }));

// Shapes copied from a genuine local synthetic Tax response (values replaced); no real buyer, payment or token.
const batchId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', orderId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', lineId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const authorizationId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', token = 'PRIVATE_SYNTHETIC_TAX_TOKEN'.padEnd(43, 'a');
const tax = { authority: 'provider_calculated_buyer_reviewed', automatic_tax: { enabled: true, provider: 'stripe', status: 'complete' }, calculator: 'stripe_checkout_automatic_tax',
  currency: 'USD', line_subtotal_minor: 4999, line_tax_minor: 437, line_total_minor: 5436, order_subtotal_minor: 4999, order_tax_minor: 437, order_total_minor: 5436, tax_behavior: 'exclusive' } as const;
const pending: TaxOrigin = { id: batchId, orderId, purpose: 'paid-tax-license-grant', schemaVersion: 2, provenance: 'synthetic_rehearsal', fulfilled: false, lines: [{ id: lineId, originHash: 'a'.repeat(64), position: 1,
  title: 'Synthetic quote recording', license: { licenseVersionId: '1', name: 'Explicit synthetic license', version: 1, type: 'non-exclusive', features: ['WAV'], deliverableRoles: ['master_wav'], termsText: 'RETAINED PRIVATE TAX TERMS <script>private()</script>' },
  declaredName: 'Declared synthetic buyer', assentedAt: '2026-10-17T17:39:52Z', preTax: { currency: 'USD', line_amount_minor: 4999, order_subtotal_minor: 4999 }, tax: { ...tax },
  documentStatus: 'pending', attempts: 0, files: [] }] };
const complete: TaxOrigin = { ...pending, fulfilled: true, lines: [{ ...pending.lines[0], documentStatus: 'complete', attempts: 1,
  files: [{ kind: 'contract', sha256: 'b'.repeat(64), sizeBytes: 814764 }, { kind: 'master_wav', sha256: 'c'.repeat(64), sizeBytes: 211758 }] }] };
const entry = (n: number) => ({ id: `aaaaaaaa-aaaa-4aaa-8aaa-${String(n).padStart(12, '0')}`, orderId: `bbbbbbbb-bbbb-4bbb-8bbb-${String(n).padStart(12, '0')}`, createdAt: '2026-10-17T17:39:53Z', lineCount: 1, provenance: 'synthetic_rehearsal' });
const listing = (origins = [{ id: batchId, orderId, createdAt: '2026-10-17T17:39:53Z', lineCount: 1, provenance: 'synthetic_rehearsal' }], hasMore = false) => ({ schemaVersion: 2, originLimit: 20, hasMore, origins });
const authorization = (kind: 'contract' | 'master_wav' = 'master_wav') => ({ id: authorizationId, token, expiresAt: '2026-10-17 17:41:58', kind,
  filename: `paid-tax-license-${lineId}-${kind}${kind === 'contract' ? '.pdf' : '.wav'}`, mimeType: kind === 'contract' ? 'application/pdf' : 'audio/wav' });
const status = (state: 'unused' | 'attempted' = 'unused') => ({ status: { schemaVersion: 2, originId: batchId, fulfilled: true, lines: [{ id: lineId, attemptCount: state === 'attempted' ? 1 : 0, maxDownloads: 3, historyLimit: 20,
  history: [{ id: authorizationId, kind: 'master_wav', issuedAt: '2026-10-17 17:39:58', expiresAt: '2026-10-17 17:41:58', status: state, attemptedAt: state === 'attempted' ? '2026-10-17 17:40:00' : null }], renderRetryAllowed: false, renderRetryAfter: null }] } });
const response = (body: unknown, code = 200) => new Response(JSON.stringify(body), { status: code });
const button = (name: string | RegExp) => screen.getByRole('button', { name });

beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-tax-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { document.head.querySelectorAll('[data-tax-csrf]').forEach(e => e.remove()); document.querySelectorAll('iframe, form').forEach(e => e.remove()); });

async function openOrder() {
  fireEvent.click(button('Open saved Tax licenses'));
  fireEvent.click(await screen.findByRole('button', { name: `Open saved order ${orderId}` }));
  return screen.findByRole('heading', { level: 2, name: `Order ${orderId}` });
}

describe('Tax journey shape validation', () => {
  it('accepts only the exact bounded listing with a consistent sentinel', () => {
    expect(validTaxListing(listing())).toBe(true);
    expect(validTaxListing(listing([], false))).toBe(true);
    expect(validTaxListing(listing(Array.from({ length: 20 }, (_, n) => entry(n)), true))).toBe(true);
    expect(validTaxListing(listing(Array.from({ length: 21 }, (_, n) => entry(n)), true))).toBe(false);
    expect(validTaxListing(listing([entry(1)], true))).toBe(false);
    expect(validTaxListing(listing([entry(1), entry(1)]))).toBe(false);
    expect(validTaxListing({ ...listing(), token })).toBe(false);
    expect(validTaxListing(listing([{ ...entry(1), providerPaymentId: 'pi_private' } as never]))).toBe(false);
    expect(validTaxListing(listing([{ ...entry(1), createdAt: '2026-10-17 17:39:53' }]))).toBe(false);
    expect(validTaxListing({ ...listing(), schemaVersion: 1 })).toBe(false);
  });
  it('accepts the exact Tax projection and refuses shape, range or premature-file drift', () => {
    expect(validTaxOrigin(pending)).toBe(true);
    expect(validTaxOrigin(complete)).toBe(true);
    const line = pending.lines[0];
    for (const bad of [{ ...line, tax: { ...tax, line_total_minor: -1 } }, { ...line, tax: { ...tax, order_tax_minor: 1.5 } }, { ...line, tax: { ...tax, tax_behavior: 'reverse_charge' } },
      { ...line, tax: { ...tax, line_subtotal_minor: 0 } }, { ...line, preTax: { ...line.preTax, line_amount_minor: 0 } }, { ...line, preTax: { ...line.preTax, currency: 'EUR' } },
      { ...line, tax: { ...tax, automatic_tax: { enabled: true, status: 'requires_location_inputs', provider: 'stripe' } } },
      { ...line, files: complete.lines[0].files }, { ...line, private: 'x' }, { ...line, assentedAt: '2026-10-17 17:39:52' }]) {
      expect(validTaxOrigin({ ...pending, lines: [bad] })).toBe(false);
    }
    expect(validTaxOrigin({ ...pending, purpose: 'paid-license-grant' })).toBe(false);
    // The client does not re-derive the tax rule: a structurally valid projection whose amounts relate differently is shown as the server sent it.
    expect(validTaxOrigin({ ...pending, lines: [{ ...line, tax: { ...tax, line_total_minor: 5435 } }] })).toBe(true);
    expect(validTaxOrigin({ ...complete, lines: [{ ...complete.lines[0], files: [complete.lines[0].files[0]] }] })).toBe(false);
  });
  it('binds status and authorization answers to the open order, line and requested kind', () => {
    expect(validTaxStatus(status().status, complete)).toBe(true);
    expect(validTaxStatus({ ...status().status, originId: lineId }, complete)).toBe(false);
    expect(validTaxAuthorization(authorization(), complete.lines[0], 'master_wav')).toBe(true);
    expect(validTaxAuthorization(authorization(), complete.lines[0], 'contract')).toBe(false);
    expect(validTaxAuthorization({ ...authorization(), filename: 'other.wav' }, complete.lines[0], 'master_wav')).toBe(false);
    expect(validTaxAuthorization({ ...authorization(), token: 'short' }, complete.lines[0], 'master_wav')).toBe(false);
    expect(validTaxAuthorization(authorization(), pending.lines[0], 'master_wav')).toBe(false);
  });
});

describe('Tax journey', () => {
  it('renders a shell with headings and no private data before any explicit read', () => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<PaidTaxLicenses />);
    expect(screen.getByRole('heading', { level: 1, name: 'Tax licenses' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Your Tax licenses' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
    expect(screen.getByRole('link', { name: 'Skip to content' })).toHaveAttribute('href', '#main');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('lists newest saved orders, reports the sentinel, opens one and moves focus to it', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing([{ id: batchId, orderId, createdAt: '2026-10-17T17:39:53Z', lineCount: 1, provenance: 'synthetic_rehearsal' },
      ...Array.from({ length: 19 }, (_, n) => entry(n))], true))).mockResolvedValueOnce(response({ origin: pending }));
    render(<PaidTaxJourney />);
    fireEvent.click(button('Open saved Tax licenses'));
    expect(await screen.findByText(/Older saved orders exist and are not shown here\./)).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Saved Tax license orders' })).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(20);
    fireEvent.click(button(`Open saved order ${orderId}`));
    const heading = await screen.findByRole('heading', { level: 2, name: `Order ${orderId}` });
    await waitFor(() => expect(heading).toHaveFocus());
    expect(fetcher.mock.calls.map(c => c[0])).toEqual(['/paid-tax-grants/index', `/paid-tax-grants/origins/${batchId}`]);
    for (const call of fetcher.mock.calls) {
      expect(call[1]).toMatchObject({ method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error' });
    }
    expect(screen.getByText('Subtotal USD 49.99, tax USD 4.37 (exclusive), total USD 54.36, from the original accepted order.')).toBeInTheDocument();
    expect(screen.getByText(pending.lines[0].license.termsText)).toBeInTheDocument();
    expect(document.querySelector('script')).toBeNull();
    expect(screen.queryByRole('button', { name: /Authorize/ })).not.toBeInTheDocument();
  });

  it('prepares, authorizes and redeems by native form only: no token in a URL, history, storage or the page', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: pending }))
      .mockResolvedValueOnce(response({ origin: complete, busy: false })).mockResolvedValueOnce(response({ authorization: authorization() }));
    const forms: { action: string; method: string; target: string; fields: Record<string, string> }[] = [];
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) {
      forms.push({ action: this.getAttribute('action')!, method: this.method, target: this.target, fields: Object.fromEntries(Array.from(this.querySelectorAll('input'), f => [f.name, f.value])) });
    });
    const storage = vi.spyOn(Storage.prototype, 'setItem'), push = vi.spyOn(history, 'pushState'), replace = vi.spyOn(history, 'replaceState');
    const start = window.location.href;
    render(<PaidTaxJourney />); await openOrder();
    fireEvent.click(button('Prepare original licenses and files'));
    fireEvent.click(await screen.findByRole('button', { name: 'Authorize master_wav for Synthetic quote recording' }));
    expect(screen.getByRole('status')).toHaveTextContent('Your files are ready.');
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    expect(fetcher.mock.calls[2]).toEqual([`/paid-tax-grants/origins/${batchId}/document`, expect.objectContaining({ method: 'POST', body: '{}' })]);
    const authorize = fetcher.mock.calls[3];
    expect(authorize[0]).toBe(`/paid-tax-grants/origins/${batchId}/lines/${lineId}/authorize`);
    expect(Object.keys(JSON.parse(String(authorize[1]?.body))).sort()).toEqual(['kind', 'nonce', 'originHash', 'requestKey']);
    expect((authorize[1]?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('c'.repeat(40));
    expect(submit).toHaveBeenCalledOnce();
    expect(forms[0]).toMatchObject({ action: `/paid-tax-grants/origins/${batchId}/authorizations/${authorizationId}/redeem`, method: 'post', fields: { token, _token: 'c'.repeat(40) } });
    expect(forms[0].action).not.toContain(token);
    expect(document.querySelector('form')).toBeNull();
    expect(document.querySelector('iframe')).toHaveAttribute('referrerpolicy', 'no-referrer');
    expect(document.body.innerHTML).not.toContain(token);
    expect(fetcher.mock.calls.every(c => !String(c[0]).includes(token))).toBe(true);
    expect([storage, push, replace].every(spy => spy.mock.calls.length === 0)).toBe(true);
    expect(window.location.href).toBe(start);
    // A submitted authorization is not offered again.
    expect(screen.queryByRole('button', { name: 'Download authorized file' })).not.toBeInTheDocument();
  });

  it.each([[403], [404], [419]])('clears everything and points to a fresh sign-in on an HTTP %i denial', async code => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete })).mockResolvedValueOnce(response({ code: 'PAID_TAX_UNAVAILABLE' }, code));
    render(<PaidTaxJourney />); await openOrder();
    fireEvent.click(button('Refresh preparation and download status'));
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Access changed.'); await waitFor(() => expect(alert).toHaveFocus());
    expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/customer/sign-in');
    expect(screen.queryByRole('heading', { name: `Order ${orderId}` })).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(complete.lines[0].license.termsText);
    expect(button('Open saved Tax licenses')).toBeDisabled();
  });

  it('treats a sealed late denial after 200 headers as a denial and shows no list', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ error: 'Paid Tax request unavailable.', status: 403 }));
    render(<PaidTaxJourney />);
    fireEvent.click(button('Open saved Tax licenses'));
    await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByRole('heading', { name: 'Saved Tax license orders' })).not.toBeInTheDocument();
  });

  it.each([['409 refusal', () => response({ code: 'PAID_TAX_UNAVAILABLE' }, 409)], ['late 503 body', () => response({ error: 'Paid Tax request unavailable.', status: 503 })],
    ['malformed listing', () => response({ ...listing(), origins: [{ ...entry(1), token }] })], ['network loss', () => Promise.reject(new Error('offline'))]])('shows only an unconfirmed message on %s', async (_, answer) => {
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => answer() as Promise<Response>);
    render(<PaidTaxJourney />);
    fireEvent.click(button('Open saved Tax licenses'));
    expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(screen.queryByRole('heading', { name: 'Saved Tax license orders' })).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(token);
    expect(button('Open saved Tax licenses')).toBeEnabled();
  });

  it('keeps an uncertain authorize as an exact replay until saved status is reviewed, and can discard it', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockRejectedValueOnce(new Error('lost')).mockResolvedValueOnce(response(status())).mockResolvedValueOnce(response({ authorization: authorization() }));
    render(<PaidTaxJourney />); await openOrder();
    fireEvent.click(button('Authorize master_wav for Synthetic quote recording'));
    const retry = await screen.findByRole('button', { name: 'Retry the exact request' });
    expect(retry).toBeDisabled(); expect(button('Authorize master_wav for Synthetic quote recording')).toBeDisabled();
    fireEvent.click(button('Refresh preparation and download status'));
    await waitFor(() => expect(retry).toBeEnabled());
    fireEvent.click(retry);
    await screen.findByRole('button', { name: 'Download authorized file' });
    expect(fetcher.mock.calls[4][0]).toBe(fetcher.mock.calls[2][0]);
    expect(fetcher.mock.calls[4][1]?.body).toBe(fetcher.mock.calls[2][1]?.body);
    expect(screen.queryByRole('button', { name: 'Retry the exact request' })).not.toBeInTheDocument();
  });

  it('offers a refused download again only when saved status shows that authorization unused', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response({ authorization: authorization() })).mockResolvedValueOnce(response(status('attempted'))).mockResolvedValueOnce(response(status('unused')));
    vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    render(<PaidTaxJourney />); await openOrder();
    fireEvent.click(button('Authorize master_wav for Synthetic quote recording'));
    fireEvent.click(await screen.findByRole('button', { name: 'Download authorized file' }));
    const frame = document.querySelector<HTMLIFrameElement>('iframe[title="Tax license attachment response"]')!;
    Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: 'http://localhost/paid-tax-grants/origins/x/authorizations/y/redeem' }, body: { textContent: JSON.stringify({ code: 'PAID_TAX_UNAVAILABLE' }) } } });
    act(() => { frame.dispatchEvent(new Event('load')); });
    expect(await screen.findByRole('alert')).toHaveTextContent('The download was refused.');
    expect(document.querySelector('iframe')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Retry the authorized download' })).not.toBeInTheDocument();
    fireEvent.click(button('Refresh preparation and download status'));
    await screen.findByLabelText('Download status for Synthetic quote recording');
    expect(screen.queryByRole('button', { name: 'Retry the authorized download' })).not.toBeInTheDocument();
    fireEvent.click(button('Refresh preparation and download status'));
    expect(await screen.findByRole('button', { name: 'Retry the authorized download' })).toBeInTheDocument();
  });

  it('clears shown private data when the tab is hidden and everything on page departure', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({ origin: complete }));
    const visibility = vi.spyOn(document, 'visibilityState', 'get');
    render(<PaidTaxJourney />); await openOrder();
    visibility.mockReturnValue('hidden');
    act(() => { document.dispatchEvent(new Event('visibilitychange')); });
    expect(screen.queryByRole('heading', { name: `Order ${orderId}` })).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(complete.lines[0].license.termsText);
    visibility.mockReturnValue('visible');
    act(() => { window.dispatchEvent(new Event('pagehide')); });
    expect(screen.queryByRole('heading', { name: 'Saved Tax license orders' })).not.toBeInTheDocument();
  });

  it('marks the journey busy while a request is in flight and disables its controls', async () => {
    let finish!: (r: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    render(<PaidTaxJourney />);
    const region = screen.getByRole('heading', { level: 2, name: 'Your Tax licenses' }).closest('section')!;
    fireEvent.click(button('Open saved Tax licenses'));
    await waitFor(() => expect(region).toHaveAttribute('aria-busy', 'true'));
    expect(button('Open saved Tax licenses')).toBeDisabled();
    await act(async () => { finish(response(listing([], false))); });
    await waitFor(() => expect(region).toHaveAttribute('aria-busy', 'false'));
    expect(screen.getByText('No saved Tax licenses yet.')).toBeInTheDocument();
  });
});
