import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomerCommunicationPreferences, validCommunicationPreferences, type CommunicationPreferences } from '../../resources/js/components/CustomerCommunicationPreferences';

const notice = { version: 'synthetic-v1', hash: 'a'.repeat(64), text: 'Synthetic reviewed fixture only. No production legal notice.' };
function preferences(version = 0, status: 'unknown' | 'granted' | 'withdrawn' = 'unknown', enabled = true): CommunicationPreferences {
  return { schema: 1, purposes: [{ purpose: 'email_marketing', version, status, notice: enabled ? notice : null, canGrant: enabled, suppression: { status: 'not_requested' } }] };
}
const response = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } });
const loaded = (value: unknown) => response({ preferences: value });
const optIn = 'I choose to opt in to email marketing under the notice above.';
async function open() { fireEvent.click(screen.getByRole('button', { name: 'Open communication preferences' })); await screen.findByRole('button', { name: 'Refresh communication preferences' }); }
beforeEach(() => { const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'synthetic-consent-token'; document.head.append(meta); });
afterEach(() => { vi.useRealTimers(); document.querySelectorAll('meta[name="csrf-token"]').forEach(meta => meta.remove()); });

describe('bounded exact communication projection', () => {
  it('accepts unknown defaults, current opt in and withdrawn with disabled notice', () => {
    expect(validCommunicationPreferences(preferences())).toBe(true);
    expect(validCommunicationPreferences(preferences(1, 'granted'))).toBe(true);
    expect(validCommunicationPreferences(preferences(2, 'withdrawn', false))).toBe(true);
  });
  it.each([
    { ...preferences(), accountId: 1 },
    { ...preferences(), purposes: [preferences().purposes[0], preferences().purposes[0]] },
    { ...preferences(), purposes: [{ ...preferences().purposes[0], purpose: 'analytics' }] },
    { ...preferences(), purposes: [{ ...preferences().purposes[0], version: '0' }] },
    preferences(0, 'granted'),
    preferences(1, 'granted', false),
    { ...preferences(), purposes: [{ ...preferences().purposes[0], notice: null }] },
    { ...preferences(), purposes: [{ ...preferences().purposes[0], notice: { ...notice, href: 'https://private.example' } }] },
    { ...preferences(), purposes: [{ ...preferences().purposes[0], notice: { ...notice, text: 'a'.repeat(2001) } }] },
    { ...preferences(), purposes: [{ ...preferences().purposes[0], notice: { ...notice, text: 'bad\u202Etext' } }] },
  ])('refuses extended, private, malformed or over-bound projections', value => expect(validCommunicationPreferences(value)).toBe(false));
});

describe('actual customer consent choice', () => {
  it('starts unchecked, shows exact notice, persists explicit opt in then withdrawal with fresh versions', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences()))
      .mockResolvedValueOnce(loaded(preferences(1, 'granted'))).mockResolvedValueOnce(loaded(preferences(2, 'withdrawn')));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open();
    expect(screen.getByLabelText(optIn)).not.toBeChecked(); expect(screen.getByText(notice.text)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save opt-in choice' })).toBeDisabled();
    fireEvent.click(screen.getByLabelText(optIn)); fireEvent.click(screen.getByRole('button', { name: 'Save opt-in choice' }));
    await screen.findByText('Your opt-in choice was saved.'); expect(screen.getByLabelText(optIn)).not.toBeChecked();
    expect(JSON.parse(String(fetcher.mock.calls[1][1]?.body))).toEqual({ action: 'grant-consent', version: 0, purpose: 'email_marketing', noticeVersion: notice.version, noticeHash: notice.hash, affirmative: true });
    expect(fetcher.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'synthetic-consent-token' }) }));
    fireEvent.click(screen.getByRole('button', { name: 'Withdraw email marketing consent' }));
    await screen.findByText('Your withdrawn choice was saved.');
    expect(JSON.parse(String(fetcher.mock.calls[2][1]?.body))).toEqual({ action: 'withdraw-consent', version: 1, purpose: 'email_marketing' });
  });
  it('withdraws from unknown when opt in and notice are disabled without an affirmative checkbox', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences(0, 'unknown', false))).mockResolvedValueOnce(loaded(preferences(1, 'withdrawn', false)));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Withdraw email marketing consent' }));
    await screen.findByText('Your withdrawn choice was saved.');
    expect(JSON.parse(String(fetcher.mock.calls[1][1]?.body))).toEqual({ action: 'withdraw-consent', version: 0, purpose: 'email_marketing' });
  });
  it('never retries a lost choice and requires a fresh GET and another affirmative action', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences())).mockRejectedValueOnce(new Error('PRIVATE transport details')).mockResolvedValueOnce(loaded(preferences(1, 'granted')));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open();
    fireEvent.click(screen.getByLabelText(optIn)); fireEvent.click(screen.getByRole('button', { name: 'Save opt-in choice' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Your choice could not be confirmed');
    expect(screen.queryByLabelText(optIn)).not.toBeInTheDocument(); expect(screen.queryByText(notice.text)).not.toBeInTheDocument();
    await open(); expect(screen.getByLabelText(optIn)).not.toBeChecked();
    expect(fetcher.mock.calls.map(([, options]) => options?.method)).toEqual(['GET', 'POST', 'GET']);
  });
  it.each([409, 503, 'wrong version', 'changed notice', 'wrong state'])('rejects %s without confirming a choice', async failure => {
    const bad = failure === 'changed notice' ? { ...preferences(1, 'granted'), purposes: [{ ...preferences(1, 'granted').purposes[0], notice: { ...notice, text: 'Different server notice' } }] }
      : failure === 'wrong state' ? preferences(1, 'withdrawn') : preferences(2, 'granted');
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences())).mockResolvedValueOnce(typeof failure === 'number' ? response({}, failure) : loaded(bad));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open(); fireEvent.click(screen.getByLabelText(optIn)); fireEvent.click(screen.getByRole('button', { name: 'Save opt-in choice' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Your choice could not be confirmed'); expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });
  it.each([401, 403, 419])('clears private choices after revoked or expired authority %s', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences(1, 'granted'))).mockResolvedValueOnce(response({ detail: 'PRIVATE details' }, status));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Withdraw email marketing consent' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Sign in again'); expect(screen.queryByText(notice.text)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open communication preferences' })).toBeDisabled();
  });
  it('drops the previous scope immediately and ignores its late committed-choice response', async () => {
    let resolve!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences())).mockImplementationOnce(() => new Promise<Response>(done => { resolve = done; }));
    const mounted = render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open();
    fireEvent.click(screen.getByLabelText(optIn)); fireEvent.click(screen.getByRole('button', { name: 'Save opt-in choice' }));
    mounted.rerender(<CustomerCommunicationPreferences scope="synthetic-scope-B" />);
    expect(screen.queryByText(notice.text)).not.toBeInTheDocument();
    await act(async () => { resolve(loaded(preferences(1, 'granted'))); });
    expect(screen.queryByText('Your saved choice is opt in.')).not.toBeInTheDocument(); expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it('clears on pagehide and ignores late reads', async () => {
    let resolve!: (value: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise<Response>(done => { resolve = done; }));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); fireEvent.click(screen.getByRole('button', { name: 'Open communication preferences' }));
    fireEvent(window, new Event('pagehide')); await act(async () => { resolve(loaded(preferences(1, 'granted'))); });
    expect(screen.queryByText(notice.text)).not.toBeInTheDocument(); expect(screen.queryByText('Your saved choice is opt in.')).not.toBeInTheDocument();
  });
  it('bounds streamed responses before displaying any notice or private extension', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response({ preferences: preferences(), detail: 'PRIVATE'.repeat(6000) }));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); fireEvent.click(screen.getByRole('button', { name: 'Open communication preferences' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Reload'); expect(screen.queryByText(notice.text)).not.toBeInTheDocument();
  });
  it('cancels an unknown timed-out mutation and clears the affirmative draft', async () => {
    let resolve!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences())).mockImplementationOnce(() => new Promise<Response>(done => { resolve = done; }));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open(); vi.useFakeTimers();
    fireEvent.click(screen.getByLabelText(optIn)); fireEvent.click(screen.getByRole('button', { name: 'Save opt-in choice' }));
    act(() => { vi.advanceTimersByTime(20001); });
    expect(screen.getByRole('alert')).toHaveTextContent('Your choice could not be confirmed'); expect(fetcher.mock.calls[1][1]?.signal?.aborted).toBe(true);
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument(); await act(async () => { resolve(loaded(preferences(1, 'granted'))); });
    expect(screen.queryByText('Your saved choice is opt in.')).not.toBeInTheDocument();
  });
  it('blocks duplicate clicks while a current withdrawal is pending', async () => {
    let resolve!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(loaded(preferences(1, 'withdrawn', false))).mockImplementationOnce(() => new Promise<Response>(done => { resolve = done; }));
    render(<CustomerCommunicationPreferences scope="synthetic-scope-A" />); await open();
    const button = screen.getByRole('button', { name: 'Withdraw email marketing consent' }); fireEvent.click(button); fireEvent.click(button);
    expect(fetcher).toHaveBeenCalledTimes(2); await act(async () => { resolve(loaded(preferences(2, 'withdrawn', false))); });
    await waitFor(() => expect(button).toBeEnabled());
  });
});
