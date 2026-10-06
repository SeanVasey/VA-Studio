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
afterEach(() => { vi.useRealTimers(); document.head.querySelectorAll('[data-delivery-csrf]').forEach(element => element.remove()); });

describe('stalled delivery request recovery', () => {
  it.each(['fetch', 'body'])('releases a stalled status %s at the effective deadline', async stage => {
    const { fetcher, submit } = setup();
    fetcher.mockReset().mockImplementationOnce(() => stage === 'fetch' ? new Promise<Response>(() => {})
      : Promise.resolve(new Response(new ReadableStream<Uint8Array>({ start() {} }))));
    vi.useFakeTimers();
    render(<TestOwnerDelivery orderId={orderId} />);
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeDisabled();
    await act(async () => { await vi.advanceTimersByTimeAsync(19_999); });
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeDisabled();
    await act(async () => { await vi.advanceTimersByTimeAsync(1); });
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeEnabled();
    expect(screen.getByRole('alert')).toHaveTextContent('could not be loaded');
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    expect(submit).not.toHaveBeenCalled();
  });

  it.each(['fetch', 'body'])('makes a stalled authorization %s recoverable without replacing its key', async stage => {
    const { fetcher, submit } = setup();
    fetcher.mockImplementationOnce(() => stage === 'fetch' ? new Promise<Response>(() => {})
      : Promise.resolve(new Response(new ReadableStream<Uint8Array>({ start() {} }), { status: 201 })))
      .mockResolvedValueOnce(json({ code: 'DELIVERY_ALREADY_ISSUED' }, 409));
    render(<TestOwnerDelivery orderId={orderId} />);
    const download = await screen.findByRole('button', { name: 'Download Original contract' });
    vi.useFakeTimers(); fireEvent.click(download);
    await act(async () => { await vi.advanceTimersByTimeAsync(19_999); });
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeDisabled();
    await act(async () => { await vi.advanceTimersByTimeAsync(1); });
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Retry the same authorization request' })).toBeEnabled();
    expect(download).toBeDisabled();
    expect(screen.getByRole('alert')).toHaveTextContent('unconfirmed');
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Retry the same authorization request' })); });
    const requests = posts(fetcher);
    expect(requests).toHaveLength(2);
    expect(requests[0][1]?.body).toBe(requests[1][1]?.body);
    expect(requests[0][1]?.headers).toEqual(requests[1][1]?.headers);
    expect(requests[0][1]?.signal?.aborted).toBe(true);
    expect(submit).not.toHaveBeenCalled();
  });

  it('ignores an old status result while its fresh successor owns the deadline and controls', async () => {
    const { fetcher, submit } = setup(); let old!: (response: Response) => void, fresh!: (response: Response) => void;
    fetcher.mockReset().mockImplementationOnce(() => new Promise<Response>(resolve => { old = resolve; }))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { fresh = resolve; }));
    vi.useFakeTimers(); render(<TestOwnerDelivery orderId={orderId} />);
    await act(async () => { await vi.advanceTimersByTimeAsync(20_000); });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' }));
    await act(async () => old(json({ delivery: listing({ history: [history()] }) })));
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeDisabled();
    expect(screen.queryByText(/No recorded stream attempt/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Download Original contract' })).not.toBeInTheDocument();
    await act(async () => fresh(json({ delivery: listing({ items: [] }) })));
    expect(screen.getByText('No test download items are available.')).toBeInTheDocument();
    expect(vi.getTimerCount()).toBe(0); expect(submit).not.toHaveBeenCalled();
    await act(async () => { await vi.advanceTimersByTimeAsync(20_000); });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('keeps an uncertain issuance identity through a stalled read-only refresh and explicit retry', async () => {
    const { fetcher, submit } = setup();
    fetcher.mockRejectedValueOnce(new Error('SYNTHETIC PRIVATE FAILURE'))
      .mockImplementationOnce(() => new Promise<Response>(() => {}))
      .mockResolvedValueOnce(json({ code: 'DELIVERY_ALREADY_ISSUED' }, 409));
    render(<TestOwnerDelivery orderId={orderId} />);
    const download = await screen.findByRole('button', { name: 'Download Original contract' });
    await act(async () => fireEvent.click(download));
    vi.useFakeTimers(); fireEvent.click(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' }));
    await act(async () => { await vi.advanceTimersByTimeAsync(20_000); });
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Retry the same authorization request' })));
    expect(posts(fetcher)).toHaveLength(2);
    expect(posts(fetcher)[0][1]?.headers).toEqual(posts(fetcher)[1][1]?.headers);
    expect(posts(fetcher)[0][1]?.body).toBe(posts(fetcher)[1][1]?.body);
    expect(screen.getByRole('alert')).toHaveTextContent('secret cannot be recovered');
    expect(submit).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0);
  });

  it('discards a late timed-out token while a deliberate replacement is pending, then submits only the current response', async () => {
    const { fetcher, submit, storage } = setup(); let old!: (response: Response) => void, replacement!: (response: Response) => void;
    fetcher.mockImplementationOnce(() => new Promise<Response>(resolve => { old = resolve; }))
      .mockResolvedValueOnce(json({ code: 'DELIVERY_ALREADY_ISSUED' }, 409))
      .mockImplementationOnce(() => new Promise<Response>(resolve => { replacement = resolve; }));
    render(<TestOwnerDelivery orderId={orderId} />);
    const download = await screen.findByRole('button', { name: 'Download Original contract' });
    vi.useFakeTimers(); fireEvent.click(download);
    await act(async () => { await vi.advanceTimersByTimeAsync(20_000); });
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Retry the same authorization request' })));
    fireEvent.click(screen.getByRole('button', { name: 'Request another Original contract' }));
    const oldResponse = json({ authorization: authorization() }, 201);
    await act(async () => old(oldResponse));
    expect(oldResponse.body?.locked).toBe(false);
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeDisabled();
    expect(submit).not.toHaveBeenCalled(); expect(document.querySelector('iframe')).toBeNull();
    await act(async () => { await vi.advanceTimersByTimeAsync(19_999); });
    expect(screen.getByRole('button', { name: 'Request another Original contract' })).toBeDisabled();
    await act(async () => replacement(json({ authorization: authorization() }, 201)));
    const requests = posts(fetcher);
    expect(requests).toHaveLength(3);
    expect(requests[0][1]?.headers).toEqual(requests[1][1]?.headers);
    expect(requests[2][1]?.headers).not.toEqual(requests[0][1]?.headers);
    expect(submit).toHaveBeenCalledOnce(); expect(vi.getTimerCount()).toBe(0);
    expect(document.documentElement.innerHTML).not.toContain(token); expect(storage).not.toHaveBeenCalled();
  });

  it('clears private state and pending reads on pagehide, then requires an explicit fresh read', async () => {
    const { fetcher, submit, storage } = setup(listing({ history: [history()] })); let old!: (response: Response) => void;
    fetcher.mockImplementationOnce(() => new Promise<Response>(resolve => { old = resolve; }))
      .mockResolvedValueOnce(json({ delivery: listing({ items: [] }) }));
    render(<TestOwnerDelivery orderId={orderId} />);
    expect(await screen.findByText(/No recorded stream attempt/)).toBeInTheDocument();
    vi.useFakeTimers(); fireEvent.click(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' }));
    fireEvent(window, new Event('pagehide'));
    expect(document.body.textContent).not.toContain(grantId);
    expect(screen.queryByText(/No recorded stream attempt/)).not.toBeInTheDocument();
    expect(vi.getTimerCount()).toBe(0); expect(fetcher).toHaveBeenCalledTimes(2);
    await act(async () => old(json({ delivery: listing() })));
    expect(screen.queryByRole('button', { name: 'Download Original contract' })).not.toBeInTheDocument();
    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })));
    expect(screen.getByText('No test download items are available.')).toBeInTheDocument();
    expect(submit).not.toHaveBeenCalled(); expect(storage).not.toHaveBeenCalled();
  });

  it('ignores a reader that returns old authorization bytes after cancellation and a newer request', async () => {
    const { fetcher, submit } = setup();
    let oldRead!: (result: ReadableStreamReadResult<Uint8Array>) => void, retry!: (response: Response) => void;
    const reader = { read: vi.fn(() => new Promise<ReadableStreamReadResult<Uint8Array>>(resolve => { oldRead = resolve; })),
      cancel: vi.fn(() => new Promise<void>(() => {})), releaseLock: vi.fn() };
    const response = new Response(null, { status: 201 });
    Object.defineProperty(response, 'body', { value: { getReader: () => reader } });
    fetcher.mockResolvedValueOnce(response).mockImplementationOnce(() => new Promise<Response>(resolve => { retry = resolve; }));
    render(<TestOwnerDelivery orderId={orderId} />);
    const download = await screen.findByRole('button', { name: 'Download Original contract' });
    vi.useFakeTimers(); fireEvent.click(download);
    await act(async () => { await vi.advanceTimersByTimeAsync(20_000); });
    expect(reader.cancel).toHaveBeenCalledOnce(); expect(reader.releaseLock).toHaveBeenCalledOnce();
    fireEvent.click(screen.getByRole('button', { name: 'Retry the same authorization request' }));
    await act(async () => oldRead({ done: false, value: new TextEncoder().encode(JSON.stringify({ authorization: authorization() })) }));
    expect(screen.getByRole('button', { name: 'Refresh downloads and recent attempts' })).toBeDisabled();
    expect(reader.read).toHaveBeenCalledOnce(); expect(submit).not.toHaveBeenCalled();
    await act(async () => retry(json({ code: 'DELIVERY_ALREADY_ISSUED' }, 409)));
    expect(screen.getByRole('alert')).toHaveTextContent('secret cannot be recovered');
    expect(submit).not.toHaveBeenCalled(); expect(vi.getTimerCount()).toBe(0);
    expect(document.documentElement.innerHTML).not.toContain(token);
  });
});

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

  it.each(['unmount', 'replace', 'pagehide'])('discards a late authorization after %s without submitting or persisting its secret', async action => {
    const { fetcher, submit, storage, user } = setup(); let finish!: (response: Response) => void;
    fetcher.mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; })).mockResolvedValueOnce(json({ delivery: listing({ orderId: grantId, items: [] }) }));
    const view = render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    if (action === 'unmount') view.unmount(); else if (action === 'pagehide') fireEvent(window, new Event('pagehide')); else view.rerender(<TestOwnerDelivery orderId={grantId} />);
    await act(async () => finish(json({ authorization: authorization() }, 201)));
    expect(submit).not.toHaveBeenCalled(); expect(storage).not.toHaveBeenCalled(); expect(document.querySelector('iframe')).toBeNull();
    expect((posts(fetcher)[0][1]?.signal as AbortSignal).aborted).toBe(true);
  });

  it.each(['unmount', 'pagehide'])('cleans attachment frames on %s', async action => {
    const { fetcher, submit, user } = setup(); fetcher.mockResolvedValueOnce(json({ authorization: authorization() }, 201));
    const view = render(<TestOwnerDelivery orderId={orderId} />); await user.click(await screen.findByRole('button', { name: 'Download Original contract' }));
    expect(submit).toHaveBeenCalledOnce();
    if (action === 'unmount') view.unmount(); else fireEvent(window, new Event('pagehide'));
    expect(document.querySelector('iframe')).toBeNull();
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

  it.each([-600_000, -10_000, 0, 60_000, 600_000])('leaves expiry to server redemption when the browser clock differs by %s ms', offset => {
    const serverNow = now().getTime();
    const response = authorization();
    vi.spyOn(Date, 'now').mockReturnValue(serverNow + offset);
    expect(validAuthorization(response, item())).toBe(true);
  });

  it('stops reading an oversized body and refuses malformed JSON', async () => {
    await expect(deliveryJson(new Response('x'.repeat(128 * 1024 + 1)))).rejects.toThrow('too large');
    await expect(deliveryJson(new Response('<html>private diagnostic</html>'))).rejects.toThrow();
  });

  it.each(['before read', 'pending body'])('releases the JSON reader on abort %s even if underlying cancellation never settles', async when => {
    const controller = new AbortController();
    const cancel = vi.fn(() => new Promise<void>(() => {}));
    const response = new Response(new ReadableStream<Uint8Array>({
      start(stream) { stream.enqueue(new TextEncoder().encode('{"authorization":')); }, cancel,
    }));
    if (when === 'before read') controller.abort();
    const result = deliveryJson(response, controller.signal);
    const refused = expect(result).rejects.toThrow('interrupted');
    if (when === 'pending body') { await Promise.resolve(); controller.abort(); }
    await refused;
    expect(cancel).toHaveBeenCalledOnce(); expect(response.body?.locked).toBe(false);
  });

  it('keeps the byte and fatal UTF-8 bounds with an abortable response reader', async () => {
    const controller = new AbortController();
    await expect(deliveryJson(new Response('x'.repeat(128 * 1024 + 1)), controller.signal)).rejects.toThrow('too large');
    await expect(deliveryJson(new Response(new Uint8Array([0xc3, 0x28])), controller.signal)).rejects.toThrow();
    await expect(deliveryJson(new Response('<html>private diagnostic</html>'), controller.signal)).rejects.toThrow();
  });
});
