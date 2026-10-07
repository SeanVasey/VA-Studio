import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProductionCustomerIdentity from '../../resources/js/Pages/ProductionCustomerIdentity';
import { captureProductionIdentityProof, productionIdentityRequest, takeProductionIdentityProof } from '../../resources/js/lib/production-customer-identity';
vi.mock('@inertiajs/react', () => ({ Head: () => null }));
const id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', proof = 'a'.repeat(64);
afterEach(() => { vi.restoreAllMocks(); window.history.replaceState(null, '', '/'); captureProductionIdentityProof(); document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; });
describe('new account access privacy and replay', () => {
  it('clears fragments/query/history and consumes the proof once without storage or props', () => {
    window.history.replaceState({ private: true }, '', `/customer/access?ignored=private#enroll.${id}.${proof}`);
    const storage = vi.spyOn(Storage.prototype, 'setItem'); captureProductionIdentityProof();
    expect(window.location.pathname + window.location.search + window.location.hash).toBe('/customer/access');
    expect(window.history.state).toBeNull(); expect(takeProductionIdentityProof()).toEqual({ id, proof }); expect(takeProductionIdentityProof()).toBeNull(); expect(storage).not.toHaveBeenCalled();
  });
  it.each(['', '#enroll.bad.proof', `#enroll.${id}.${proof}&email=private`, `#ENROLL.${id}.${proof}`])('rejects malformed bearer %s', fragment => {
    window.history.replaceState(null, '', '/customer/access' + fragment); captureProductionIdentityProof();
    expect(takeProductionIdentityProof()).toBeNull(); expect(window.location.hash).toBe('');
  });
  it('retries identical request after uncertain response and accepts only the fixed minimal envelope', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('lost reply')).mockResolvedValueOnce(new Response('{"accepted":true}', { status: 202 }));
    render(<ProductionCustomerIdentity mode="enroll" rehearsal />);
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'buyer@example.test' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Request private link' }).closest('form')!);
    await screen.findByRole('button', { name: 'Retry same request' });
    const first = fetcher.mock.calls[0][1]?.body;
    fireEvent.submit(screen.getByRole('button', { name: 'Retry same request' }).closest('form')!);
    await screen.findByRole('status'); expect(fetcher.mock.calls[1][1]?.body).toBe(first);
    expect(JSON.parse(String(first)).requestKey).toMatch(/^[a-f0-9]{64}$/); expect(screen.queryByLabelText('Email address')).not.toBeInTheDocument();
  });
  it('keeps completion proof out of rendered markup and sends it only in the fixed POST body', async () => {
    window.history.replaceState(null, '', `/customer/access#recover.${id}.${proof}`); captureProductionIdentityProof();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{"completed":true,"next":"/customer/sign-in"}', { status: 200 }));
    const page = render(<ProductionCustomerIdentity mode="complete" rehearsal />);
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Declared buyer' } }); fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'MailboxPassword123' } });
    expect(page.container.innerHTML).not.toContain(proof);
    fireEvent.submit(screen.getByRole('button', { name: 'Complete account access' }).closest('form')!);
    await screen.findByRole('status'); expect(fetcher.mock.calls[0][0]).toBe('/customer/identity/complete');
    expect(JSON.parse(String(fetcher.mock.calls[0][1]?.body))).toMatchObject({ id, proof, password: 'MailboxPassword123' });
    expect(screen.queryByLabelText('Password')).not.toBeInTheDocument(); expect(takeProductionIdentityProof()).toBeNull();
  });
  it.each(['enroll', 'recover', 'sign-in'] as const)('erases %s private input and permanently closes the departed page even when fetch ignores abort', async mode => {
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const page = render(<ProductionCustomerIdentity mode={mode} rehearsal />);
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'buyer@example.test' } });
    if (mode === 'sign-in') fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'MailboxPassword123' } });
    const form = page.container.querySelector('form')!; fireEvent.submit(form);
    const signal = fetcher.mock.calls[0][1]?.signal as AbortSignal;
    fireEvent(window, new Event('pagehide'));
    expect(signal.aborted).toBe(true); expect(screen.getByLabelText('Email address')).toHaveValue('');
    if (mode === 'sign-in') expect(screen.getByLabelText('Password')).toHaveValue('');
    const restore = new Event('pageshow'); Object.defineProperty(restore, 'persisted', { value: true }); fireEvent(window, restore);
    expect(screen.getByRole('button', { name: 'Open a fresh account page' })).toBeDisabled();
    fireEvent.submit(form); expect(fetcher).toHaveBeenCalledTimes(1);
    await act(async () => { finish(new Response(mode === 'sign-in' ? '{"authenticated":true,"next":"/customer"}' : '{"accepted":true}', { status: mode === 'sign-in' ? 200 : 202 })); });
    expect(screen.queryByRole('status')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Retry same request' })).not.toBeInTheDocument();
  });
  it('erases a completion attempt, refuses BFCache restoration and aborts on unmount', async () => {
    window.history.replaceState(null, '', `/customer/access#enroll.${id}.${proof}`); captureProductionIdentityProof();
    let finish!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    const page = render(<ProductionCustomerIdentity mode="complete" rehearsal />);
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Declared buyer' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'MailboxPassword123' } });
    fireEvent.submit(page.container.querySelector('form')!); const signal = fetcher.mock.calls[0][1]?.signal as AbortSignal;
    fireEvent(window, new Event('pagehide'));
    expect(screen.getByLabelText('Password')).toHaveValue(''); expect(screen.getByLabelText('Your name')).toHaveValue(''); expect(signal.aborted).toBe(true);
    const restore = new Event('pageshow'); Object.defineProperty(restore, 'persisted', { value: true }); fireEvent(window, restore);
    expect(screen.getByRole('button', { name: 'Open a fresh account page' })).toBeDisabled(); expect(takeProductionIdentityProof()).toBeNull();
    fireEvent.submit(page.container.querySelector('form')!); expect(fetcher).toHaveBeenCalledTimes(1);
    page.unmount(); await act(async () => { finish(new Response('{"completed":true,"next":"/customer/sign-in"}', { status: 200 })); });
    expect(page.container.innerHTML).toBe('');
  });
  it('preserves a fresh completion link through React effect rehearsal and aborts real removal', async () => {
    window.history.replaceState(null, '', `/customer/access#enroll.${id}.${proof}`); captureProductionIdentityProof();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(() => {}));
    const page = render(<StrictMode><ProductionCustomerIdentity mode="complete" rehearsal /></StrictMode>);
    await act(async () => {});
    expect(screen.getByRole('button', { name: 'Complete account access' })).not.toBeDisabled();
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Declared buyer' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'MailboxPassword123' } });
    fireEvent.submit(page.container.querySelector('form')!);
    expect(fetcher).toHaveBeenCalledTimes(1); const signal = fetcher.mock.calls[0][1]?.signal as AbortSignal;
    page.unmount(); expect(signal.aborted).toBe(true); await act(async () => {}); expect(takeProductionIdentityProof()).toBeNull();
  });
  it('rejects duplicate keys and arbitrary redirects in success envelopes and uses current CSRF cookie', async () => {
    document.cookie = 'XSRF-TOKEN=current%2Btoken; path=/';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(new Response('{"accepted":true,"accepted":true}', { status: 202 })).mockResolvedValueOnce(new Response('{"completed":true,"next":"https://foreign.example"}', { status: 200 }));
    const signal = new AbortController().signal;
    expect(await productionIdentityRequest('request', '{}', signal)).toBe('uncertain');
    expect(await productionIdentityRequest('complete', '{}', signal)).toBe('uncertain');
    expect(fetcher.mock.calls[0][1]).toMatchObject({ credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { 'X-XSRF-TOKEN': 'current+token' } });
  });
});
