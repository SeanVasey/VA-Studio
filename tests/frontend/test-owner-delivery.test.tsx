import { StrictMode } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TestOwnerDelivery } from '../../resources/js/components/TestOwnerDelivery';
import { deliveryJson, deliveryRoles, validAuthorization, validDelivery, type DeliveryItem, type DeliveryKind } from '../../resources/js/lib/test-delivery';

const orderId = '76000000-0000-4000-8000-000000000001';
const grantId = '76000000-0000-4000-8000-000000000002';
const authorizationId = '76000000-0000-4000-8000-000000000003';
const token = 'SYNTHETIC_ONLY_PRIVATE_TOKEN'.padEnd(43, '0');
const base = `/orders/${orderId}/delivery`;
const now = () => new Date(Math.floor(Date.now() / 1000) * 1000);
const utc = (date: Date) => date.toISOString().replace('.000Z', 'Z');
function item(kind: DeliveryKind = 'contract'): DeliveryItem {
  return { grantId, kind, filename: `${grantId}-${kind}.${deliveryRoles[kind].extension}`, mimeType: deliveryRoles[kind].mime, sizeBytes: 1024 };
}
function listing(change = {}) {
  return { deliverySchema: 1, orderId, testOnly: true, status: 'available', items: [item()], history: [], historyLimit: 20, historyHasMore: false, ...change };
}
function authorization(change = {}) {
  return { authorizationId, token, expiresAt: utc(new Date(now().getTime() + 60_000)), filename: item().filename, mimeType: item().mimeType, ...change };
}
function history(change = {}) {
  return { authorizationId, grantId, kind: 'contract', issuedAt: utc(now()), expiresAt: utc(new Date(now().getTime() + 60_000)), status: 'unused', attemptedAt: null, ...change };
}
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
function setup(list = listing()) {
  document.head.insertAdjacentHTML('beforeend', '<meta data-delivery-csrf name="csrf-token" content="synthetic-csrf">');
  const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
  const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({ delivery: list }));
  const storage = vi.spyOn(Storage.prototype, 'setItem');
  return { submit, fetcher, storage, user: userEvent.setup() };
}
const posts = (fetcher: { mock: { calls: Parameters<typeof fetch>[] } }) => fetcher.mock.calls.filter(([, init]) => init?.method === 'POST');
afterEach(() => { document.head.querySelectorAll('[data-delivery-csrf]').forEach(element => element.remove()); });

describe('test owner delivery transport and controls', () => {
  it('reads without creating access and renders only fixed roles and bounded history', async () => {
    const { fetcher, submit, storage } = setup(listing({ items: Object.keys(deliveryRoles).map(role => item(role as DeliveryKind)), history: [history()] }));
    render(<TestOwnerDelivery orderId={orderId} />);
    for (const { label } of Object.values(deliveryRoles)) expect(await screen.findByRole('button', { name: `Download ${label}` })).toBeEnabled();
    expect(screen.getByText(/No recorded stream attempt/)).toBeInTheDocument();
    expect(fetcher.mock.calls[0]).toEqual([base, expect.objectContaining({ method: 'GET', credentials: 'same-origin', cache: 'no-store' })]);
    expect(posts(fetcher)).toHaveLength(0); expect(submit).not.toHaveBeenCalled(); expect(storage).not.toHaveBeenCalled();
  });

  it('issues the exact selector with CSRF/idempotency and submits a native private POST with immediately removed fields', async () => {
    const { fetcher, submit, storage, user } = setup();
    fetcher.mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    let submittedForm: { action: string; method: string; target: string; enctype: string; fields: Record<string, FormDataEntryValue> } | undefined;
    submit.mockImplementation(function (this: HTMLFormElement) { submittedForm = { action: this.action, method: this.method, target: this.target, enctype: this.enctype, fields: Object.fromEntries(new FormData(this)) }; });
    render(<TestOwnerDelivery orderId={orderId} />);
    await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(posts(fetcher)).toHaveLength(1);
    const [url, request] = posts(fetcher)[0];
    expect(url).toBe(`${base}/authorizations`);
    expect(request).toMatchObject({ method: 'POST', credentials: 'same-origin', cache: 'no-store', body: JSON.stringify({ grantId, kind: 'contract' }), headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'synthetic-csrf' } });
    expect((request?.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[a-f0-9-]{36}$/);
    expect(submittedForm).toMatchObject({ action: `${window.location.origin}${base}/download`, method: 'post', enctype: 'application/x-www-form-urlencoded', fields: { authorizationId, token, _token: 'synthetic-csrf' } });
    expect(submittedForm?.target).toMatch(/^test-delivery-/);
    expect(document.querySelector('form')).toBeNull(); expect(document.documentElement.innerHTML).not.toContain(token);
    expect(document.querySelector('iframe')).toHaveAttribute('referrerpolicy', 'no-referrer');
    expect(screen.getByRole('status')).toHaveTextContent('cannot confirm file receipt');
    expect(screen.queryByRole('link')).not.toBeInTheDocument(); expect(storage).not.toHaveBeenCalled();
    expect(fetcher.mock.calls.every(([requestUrl]) => !String(requestUrl).includes(token))).toBe(true);
  });

  it('deduplicates clicks and preserves the same selector/key after an uncertain issuance until deliberate replacement', async () => {
    const { fetcher, submit, user } = setup(); let finish!: (response: Response) => void;
    fetcher.mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(json({ code: 'DELIVERY_ALREADY_ISSUED', message: token }, 409))
      .mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    render(<TestOwnerDelivery orderId={orderId} />);
    await user.dblClick(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(posts(fetcher)).toHaveLength(1);
    await act(async () => finish(new Response('not json', { status: 503 })));
    expect(await screen.findByRole('alert')).toHaveTextContent('unconfirmed');
    expect(screen.getByRole('button', { name: 'Download Original contract' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Retry the same authorization request' }));
    expect(screen.getByRole('alert')).toHaveTextContent('secret cannot be recovered');
    expect(submit).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Request another Original contract' }));
    const requests = posts(fetcher).map(([, init]) => init!);
    expect(requests[0].body).toBe(requests[1].body);
    expect(requests[0].headers).toEqual(requests[1].headers);
    expect((requests[2].headers as Record<string, string>)['Idempotency-Key']).not.toBe((requests[1].headers as Record<string, string>)['Idempotency-Key']);
    expect(submit).toHaveBeenCalledTimes(1); expect(document.body.textContent).not.toContain(token);
  });

  it('keeps an uncertain key across read-only refresh and requires an explicit new-request action to discard it', async () => {
    const { fetcher, user } = setup();
    fetcher.mockRejectedValueOnce(new Error('private diagnostic')).mockResolvedValueOnce(json({ delivery: listing() }))
      .mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    render(<TestOwnerDelivery orderId={orderId} />);
    await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    await user.click(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' }));
    expect(screen.getByRole('button', { name: 'Download Original contract' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Retry the same authorization request' })).toBeEnabled();
    await user.click(screen.getByRole('button', { name: 'Start a new authorization request' }));
    expect(screen.getByRole('alert')).toHaveTextContent('earlier request may still count');
    await user.click(screen.getByRole('button', { name: 'Download Original contract' }));
    expect(posts(fetcher)).toHaveLength(2);
    expect(posts(fetcher)[0][1]?.headers).not.toEqual(posts(fetcher)[1][1]?.headers);
  });

  it.each([
    [404, 'DELIVERY_NOT_FOUND', 'unavailable for this session'], [422, 'INVALID_DELIVERY_REQUEST', 'could not be accepted'],
    [409, 'DELIVERY_CONFLICT', 'conflicts with a saved request'], [410, 'DELIVERY_EXPIRED', 'expired'],
    [409, 'DELIVERY_ATTEMPTED', 'already has a stream attempt'], [429, 'DELIVERY_RATE_LIMITED', 'temporary test download limit'],
    [419, 'SESSION_EXPIRED', 'session expired'],
  ])('handles %s %s with fixed private copy and no attachment', async (status, code, copy) => {
    const { fetcher, submit, user } = setup(); fetcher.mockResolvedValueOnce(json({ code, message: `PRIVATE ${token}` }, status as number));
    render(<TestOwnerDelivery orderId={orderId} />);
    await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(screen.getByRole('alert')).toHaveTextContent(copy as string); expect(document.body.textContent).not.toContain('PRIVATE');
    expect(submit).not.toHaveBeenCalled();
  });

  it.each([
    ['expired', { expiresAt: utc(new Date(now().getTime() - 1000)) }], ['future', { expiresAt: utc(new Date(now().getTime() + 600_000)) }],
    ['token shape', { token: 'secret?url' }], ['wrong file', { filename: '../private.pdf' }], ['wrong grant', { filename: `${orderId}-contract.pdf` }],
    ['wrong MIME', { mimeType: 'text/html' }], ['extra metadata', { privatePath: 'PRIVATE' }], ['invalid time', { expiresAt: '2026-02-30T00:00:00Z' }],
  ])('rejects %s issuance without streaming and preserves the operation for an explicit retry', async (_name, change) => {
    const { fetcher, submit, user } = setup(); fetcher.mockResolvedValueOnce(json({ authorization: authorization(change) }, 201));
    render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(screen.getByRole('alert')).toHaveTextContent('unconfirmed'); expect(screen.getByRole('button', { name: 'Retry the same authorization request' })).toBeEnabled();
    expect(submit).not.toHaveBeenCalled(); expect(document.body.textContent).not.toContain(token);
  });

  it('denies issuance without a CSRF token', async () => {
    const { fetcher, submit, user } = setup(); document.head.querySelector('[data-delivery-csrf]')!.remove();
    render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(screen.getByRole('alert')).toHaveTextContent('session expired'); expect(posts(fetcher)).toHaveLength(0); expect(submit).not.toHaveBeenCalled();
  });

  it('shows availability and empty/history states without offering unavailable files', async () => {
    const { fetcher, user } = setup(listing({ status: 'unavailable', history: [history({ status: 'attempted', attemptedAt: utc(now()) })] }));
    fetcher.mockResolvedValueOnce(json({ delivery: listing({ items: [] }) }));
    render(<TestOwnerDelivery orderId={orderId} />);
    expect(await screen.findByText('Downloads are not available for this test order currently.')).toBeInTheDocument();
    expect(screen.getByText(/Stream attempted/)).toBeInTheDocument(); expect(screen.queryByRole('button', { name: /^Download / })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' }));
    expect(screen.getByText('No test download items are available.')).toBeInTheDocument(); expect(screen.getByText('No recent download attempts.')).toBeInTheDocument();
  });

  it('handles a native JSON error with fixed copy, drops fields, and never treats submission as receipt', async () => {
    const { fetcher, user } = setup(); fetcher.mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    const frame = document.querySelector('iframe')!;
    Object.defineProperty(frame, 'contentDocument', { configurable: true, value: { location: { href: `${window.location.origin}${base}/download` }, body: { textContent: JSON.stringify({ code: 'DELIVERY_EXPIRED', message: token }) } } });
    fireEvent.load(frame);
    expect(screen.getByRole('alert')).toHaveTextContent('expired'); expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain(token);
  });

  it('ignores an older native error after a newer explicit attempt', async () => {
    const { fetcher, user } = setup(); fetcher.mockResolvedValueOnce(json({ authorization: authorization() }, 201)).mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    const oldFrame = document.querySelector('iframe')!;
    await user.click(screen.getByRole('button', { name: 'Request another Original contract' }));
    Object.defineProperty(oldFrame, 'contentDocument', { configurable: true, value: { location: { href: `${window.location.origin}${base}/download` }, body: { textContent: JSON.stringify({ code: 'DELIVERY_EXPIRED' }) } } });
    fireEvent.load(oldFrame); expect(screen.queryByRole('alert')).not.toBeInTheDocument(); expect(screen.getByRole('status')).toHaveTextContent('cannot confirm file receipt');
  });

  it.each(['unmount', 'replace'])('discards a late authorization after %s without submitting or persisting its secret', async action => {
    const { fetcher, submit, storage, user } = setup(); let finish!: (response: Response) => void;
    fetcher.mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; })).mockResolvedValueOnce(json({ delivery: listing({ orderId: grantId, items: [] }) }));
    const view = render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    if (action === 'unmount') view.unmount(); else view.rerender(<TestOwnerDelivery orderId={grantId} />);
    await act(async () => finish(json({ authorization: authorization() }, 201)));
    expect(submit).not.toHaveBeenCalled(); expect(storage).not.toHaveBeenCalled(); expect(document.querySelector('iframe')).toBeNull();
    expect((posts(fetcher)[0][1]?.signal as AbortSignal).aborted).toBe(true);
  });

  it('cleans attachment frames on unmount', async () => {
    const { fetcher, submit, user } = setup(); fetcher.mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    const view = render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(submit).toHaveBeenCalledOnce(); view.unmount(); expect(document.querySelector('iframe')).toBeNull();
  });

  it('restarts a StrictMode read without accepting its aborted predecessor', async () => {
    document.head.insertAdjacentHTML('beforeend', '<meta data-delivery-csrf name="csrf-token" content="synthetic-csrf">');
    let finish!: (response: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; })).mockResolvedValueOnce(json({ delivery: listing({ items: [] }) }));
    render(<StrictMode><TestOwnerDelivery orderId={orderId} /></StrictMode>);
    expect(await screen.findByText('No test download items are available.')).toBeInTheDocument();
    await act(async () => finish(json({ delivery: listing() })));
    expect(screen.queryByRole('button', { name: 'Download Original contract' })).not.toBeInTheDocument();
  });
});

describe('bounded delivery schema', () => {
  it.each([
    ['different order', { orderId: grantId }], ['schema', { deliverySchema: 2 }], ['live mode', { testOnly: false }],
    ['private metadata', { privatePath: 'PRIVATE' }], ['bad role', { items: [{ ...item(), kind: 'preview' }] }],
    ['wrong MIME', { items: [{ ...item(), mimeType: 'text/html' }] }], ['duplicate selector', { items: [item(), item()] }],
    ['oversized contract', { items: [{ ...item(), sizeBytes: 16 * 1024 * 1024 + 1 }] }], ['invalid count', { items: [{ ...item(), sizeBytes: 1.5 }] }],
    ['history overflow', { history: Array(21).fill(history()) }], ['duplicate authorization', { history: [history(), history()] }],
    ['unexpected attempt', { history: [history({ attemptedAt: utc(now()) })] }], ['missing attempt', { history: [history({ status: 'attempted' })] }],
    ['outside attempt', { history: [history({ status: 'attempted', attemptedAt: utc(new Date(now().getTime() + 60_000)) })] }],
    ['history bound', { historyLimit: 21 }], ['invalid more flag', { historyHasMore: true }],
  ])('rejects %s', (_name, change) => expect(validDelivery(listing(change), orderId)).toBe(false));

  it('checks authorization expiry at the boundary without treating client validation as entitlement', () => {
    expect(validAuthorization(authorization(), item(), now().getTime())).toBe(true);
    expect(validAuthorization(authorization(), item(), now().getTime() + 60_000)).toBe(false);
  });

  it('stops reading an oversized body and refuses malformed JSON', async () => {
    await expect(deliveryJson(new Response('x'.repeat(128 * 1024 + 1)))).rejects.toThrow('too large');
    await expect(deliveryJson(new Response('<html>private diagnostic</html>'))).rejects.toThrow();
  });
});
