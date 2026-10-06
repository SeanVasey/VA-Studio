import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createInertiaApp, router } from '@inertiajs/react';
import CustomerAccessRequest from '../../resources/js/Pages/CustomerAccessRequest';
import CustomerAccessFinish from '../../resources/js/Pages/CustomerAccessFinish';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { captureCustomerIdentityProof, clearCustomerIdentityProof, currentCustomerIdentityProof, identityRequest, type IdentityPurpose } from '../../resources/js/lib/customer-identity';

vi.mock('@inertiajs/react', async importOriginal => ({
  ...await importOriginal<typeof import('@inertiajs/react')>(), Head: () => null, createInertiaApp: vi.fn(),
}));

const challengeId = '730000ab-0000-4000-8000-000000000001';
const requestKey = '730000ab-0000-4000-8000-000000000002';
const proof = 'a'.repeat(64);
const password = 'Synthetic password 123';
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const completed = () => json({ completed: true, next: '/account/sign-in' });
const accepted = () => json({ accepted: true }, 202);
function capture(purpose: IdentityPurpose = 'enroll') {
  window.history.replaceState(null, '', `/account/access#${purpose}.${challengeId}.${proof}`);
  captureCustomerIdentityProof();
}
function request(purpose: IdentityPurpose = 'enroll') {
  return render(<CustomerAccessRequest testOnly siteContent={defaultSiteContent} purpose={purpose} />);
}
function finish(purpose: IdentityPurpose = 'enroll') {
  capture(purpose);
  return render(<CustomerAccessFinish testOnly siteContent={defaultSiteContent} />);
}
function enterFinish(purpose: IdentityPurpose = 'enroll', value = password) {
  if (purpose === 'enroll') fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Synthetic Customer' } });
  fireEvent.change(screen.getByLabelText('New password'), { target: { value } });
}
function submit(label: string) {
  fireEvent.submit(screen.getByRole('button', { name: label }).closest('form')!);
}
afterEach(() => {
  vi.useRealTimers(); clearCustomerIdentityProof();
  window.history.replaceState(null, '', '/');
  document.head.querySelectorAll('[data-identity-token]').forEach(element => element.remove());
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
});

describe('customer identity proof handling', () => {
  it('removes the fragment and query before actual app bootstrap can save Inertia history', async () => {
    window.history.replaceState({ previous: 'discard' }, '', `/account/access?unexpected=private#enroll.${challengeId}.${proof}`);
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    const history = vi.spyOn(window.history, 'replaceState');
    vi.mocked(createInertiaApp).mockImplementation(() => {
      expect(window.location.pathname + window.location.search + window.location.hash).toBe('/account/access');
      expect(window.history.state).toBeNull();
      expect(currentCustomerIdentityProof()).toEqual({ purpose: 'enroll', id: challengeId, proof });
      return Promise.resolve(undefined);
    });
    await import('../../resources/js/app');
    expect(createInertiaApp).toHaveBeenCalledOnce();
    expect(history).toHaveBeenCalledExactlyOnceWith(null, '', '/account/access');
    expect(storage).not.toHaveBeenCalled();
  });

  it.each([
    '', '#enroll', `#ENROLL.${challengeId}.${proof}`, `#enroll.${challengeId.toUpperCase()}.${proof}`,
    `#enroll.${challengeId.replace('-4000-', '-1000-')}.${proof}`, `#recover.${challengeId}.${proof}x`,
    `#recover.${challengeId}.${proof.slice(1)}`, `#recover.${challengeId}.${proof.toUpperCase()}`,
    `#enroll.${challengeId}.${proof}%0A`, `#enroll.${challengeId}.${proof}&email=private`,
  ])('rejects malformed proof %s and leaves a clean native recovery path', fragment => {
    window.history.replaceState(null, '', '/account/access' + fragment);
    captureCustomerIdentityProof();
    expect(currentCustomerIdentityProof()).toBeNull(); expect(window.location.hash).toBe('');
    render(<CustomerAccessFinish testOnly siteContent={defaultSiteContent} />);
    expect(screen.getByRole('alert')).toHaveTextContent('A refreshed or incomplete page cannot recover its proof');
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Request a new test message' })).toHaveAttribute('href', '/account/recover');
  });

  it('never accepts proof on another route or reloads it from storage/history', () => {
    capture();
    window.history.replaceState({ proof }, '', `/account/sign-in#enroll.${challengeId}.${proof}`);
    captureCustomerIdentityProof(); expect(currentCustomerIdentityProof()).toBeNull();
    capture('recover'); expect(currentCustomerIdentityProof()?.purpose).toBe('recover');
    captureCustomerIdentityProof(); expect(currentCustomerIdentityProof()).toBeNull();
  });

  it('releases the module proof on mount and does not persist or restore it after unmount', () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    const view = finish(); enterFinish();
    expect(currentCustomerIdentityProof()).toBeNull(); expect(storage).not.toHaveBeenCalled();
    expect(JSON.stringify(window.history.state)).not.toContain(proof);
    view.unmount(); render(<CustomerAccessFinish testOnly siteContent={defaultSiteContent} />);
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('A refreshed or incomplete page');
  });
});

describe('customer identity response boundary', () => {
  it.each(['request', 'complete'] as const)('sends the %s body only to its fixed native endpoint with current CSRF', async action => {
    const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'stale-token'; meta.dataset.identityToken = ''; document.head.append(meta);
    document.cookie = 'XSRF-TOKEN=current%2Bcookie%3D; path=/';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(action === 'request' ? accepted() : completed());
    const signal = new AbortController().signal;
    expect(await identityRequest(action, '{"synthetic":"body"}', signal)).toBe('saved');
    expect(fetcher).toHaveBeenCalledExactlyOnceWith('/account/identity/' + action, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'current+cookie=' }, body: '{"synthetic":"body"}',
    });
  });

  it('uses the document CSRF token when the cookie is unavailable', async () => {
    const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'document-token'; meta.dataset.identityToken = ''; document.head.append(meta);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(accepted());
    await identityRequest('request', '{}', new AbortController().signal);
    expect(fetcher.mock.calls[0][1]?.headers).toEqual({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'document-token' });
  });

  it.each([
    ['request', 200, { accepted: true }], ['request', 202, { accepted: false }], ['request', 202, { accepted: 1 }],
    ['request', 202, { accepted: true, email: 'PRIVATE' }], ['request', 202, { completed: true }],
    ['request', 202, []], ['request', 202, null], ['request', 202, true],
    ['complete', 202, { completed: true, next: '/account/sign-in' }],
    ['complete', 200, { completed: false, next: '/account/sign-in' }],
    ['complete', 200, { completed: true }], ['complete', 200, { completed: true, next: '/account' }],
    ['complete', 200, { completed: true, next: '//attacker.test' }],
    ['complete', 200, { completed: true, next: 'https://attacker.test/account/sign-in' }],
    ['complete', 200, { completed: true, next: '/account/sign-in?proof=PRIVATE' }],
    ['complete', 200, { completed: true, next: '/account/sign-in', proof: 'PRIVATE' }],
    ['complete', 200, []], ['complete', 200, null], ['complete', 200, 'PRIVATE'],
  ] as const)('rejects noncontract %s response with status %s and body %j', async (action, status, body) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(body, status));
    expect(await identityRequest(action, '{}', new AbortController().signal)).toBe('uncertain');
  });

  it.each([403, 404, 429, 500, 503])('treats status %s as unconfirmed without disclosing server content', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ message: 'PRIVATE server content' }, status));
    expect(await identityRequest('complete', '{}', new AbortController().signal)).toBe('uncertain');
  });

  it.each([
    ['request', '{"accepted":false,"accepted":true}'],
    ['request', '{"accepted":true,"accepted":true}'],
    ['complete', '{"completed":false,"completed":true,"next":"/account/sign-in"}'],
    ['complete', '{"completed":true,"next":"//attacker.test","next":"/account/sign-in"}'],
  ] as const)('rejects duplicate keys in a %s envelope', async (action, body) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(body, { status: action === 'request' ? 202 : 200 }));
    expect(await identityRequest(action, '{}', new AbortController().signal)).toBe('uncertain');
  });

  it.each(['network', 'html', 'oversized', 'redirect', 'redirected rejection', 'cookie encoding'])('fails closed after %s', async kind => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    if (kind === 'network') fetcher.mockRejectedValue(new Error('PRIVATE'));
    else if (kind === 'html') fetcher.mockResolvedValue(new Response('<html>PRIVATE</html>'));
    else if (kind === 'oversized') fetcher.mockResolvedValue(new Response(' '.repeat(4097)));
    else if (kind === 'cookie encoding') { document.cookie = 'XSRF-TOKEN=%ZZ; path=/'; fetcher.mockResolvedValue(completed()); }
    else { const response = kind === 'redirect' ? completed() : json({}, 422); Object.defineProperty(response, 'redirected', { value: true }); fetcher.mockResolvedValue(response); }
    expect(await identityRequest('complete', '{}', new AbortController().signal)).toBe('uncertain');
    if (kind === 'cookie encoding') expect(fetcher).not.toHaveBeenCalled();
  });
});

describe('customer access request', () => {
  it.each(['enroll', 'recover'] as const)('submits %s with the same generic success and no automatic navigation or storage', async purpose => {
    const storage = vi.spyOn(Storage.prototype, 'setItem'), visit = vi.spyOn(router, 'visit');
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(requestKey);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(accepted());
    request(purpose); const user = userEvent.setup(); await user.type(screen.getByLabelText('Email address'), 'synthetic@example.test');
    await user.click(screen.getByRole('button', { name: 'Request test message' }));
    expect(fetcher.mock.calls[0][1]?.body).toBe(JSON.stringify({ purpose, email: 'synthetic@example.test', requestKey }));
    expect(await screen.findByRole('status')).toHaveTextContent('If the address is eligible');
    expect(screen.getByRole('status')).toHaveFocus(); expect(screen.queryByLabelText('Email address')).not.toBeInTheDocument();
    expect(storage).not.toHaveBeenCalled(); expect(visit).not.toHaveBeenCalled();
  });

  it('preserves exact request bytes after uncertainty and a later rejection, with fresh CSRF on retry', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('lost'))
      .mockResolvedValueOnce(json({ message: 'PRIVATE' }, 422)).mockResolvedValueOnce(accepted());
    request(); fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'synthetic@example.test' } });
    submit('Request test message'); expect(await screen.findByRole('alert')).toHaveTextContent('could not be confirmed');
    expect(screen.getByLabelText('Email address')).toHaveAttribute('readonly');
    document.cookie = 'XSRF-TOKEN=renewed; path=/';
    submit('Retry same request'); expect(await screen.findByRole('button', { name: 'Retry same request' })).toBeEnabled();
    submit('Retry same request'); expect(await screen.findByRole('status')).toHaveTextContent('Request accepted');
    expect(fetcher.mock.calls).toHaveLength(3);
    expect(new Set(fetcher.mock.calls.map(([, init]) => init?.body)).size).toBe(1);
    expect(fetcher.mock.calls[1][1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'renewed' });
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
  });

  it('permits correction and creates a new key only after a first definitive validation rejection', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(json({}, 422)).mockResolvedValueOnce(accepted());
    const uuid = vi.spyOn(crypto, 'randomUUID');
    request(); fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'old@example.test' } }); submit('Request test message');
    expect(await screen.findByRole('alert')).toHaveTextContent('Check the email address');
    expect(screen.getByLabelText('Email address')).not.toHaveAttribute('readonly');
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'corrected@example.test' } }); submit('Request test message');
    expect(await screen.findByRole('status')).toBeInTheDocument(); expect(uuid).toHaveBeenCalledTimes(2);
    expect(fetcher.mock.calls[0][1]?.body).not.toBe(fetcher.mock.calls[1][1]?.body);
  });

  it('retains a session-expired request and exposes a separate native sign-in link', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({}, 419)); request();
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'synthetic@example.test' } }); submit('Request test message');
    expect(await screen.findByRole('alert')).toHaveTextContent('Keep this page open');
    expect(screen.getByRole('link', { name: 'Renew sign-in in a new tab' })).toHaveAttribute('target', '_blank');
    expect(screen.getByRole('button', { name: 'Retry same request' })).toBeEnabled();
  });
});

describe('customer access completion', () => {
  it.each(['enroll', 'recover'] as const)('completes %s only once without authenticating or persisting secrets', async purpose => {
    const storage = vi.spyOn(Storage.prototype, 'setItem'), visit = vi.spyOn(router, 'visit');
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(requestKey);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(completed());
    finish(purpose); enterFinish(purpose); submit('Complete account request');
    expect(await screen.findByRole('status')).toHaveTextContent('Sign in with your new password');
    await waitFor(() => expect(screen.getByRole('status')).toHaveFocus());
    expect(fetcher.mock.calls[0][1]?.body).toBe(JSON.stringify({ purpose, id: challengeId, proof, name: purpose === 'enroll' ? 'Synthetic Customer' : '', password, requestKey }));
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Complete account request' })).not.toBeInTheDocument();
    expect(currentCustomerIdentityProof()).toBeNull(); expect(window.location.hash).toBe('');
    expect(storage).not.toHaveBeenCalled(); expect(visit).not.toHaveBeenCalled();
  });

  it.each(['short1', 'letterswithoutnumber', '123456789012', 'a'.repeat(73) + '1', '界'.repeat(24) + '1', 'valid password1\0', '😀😀😀😀😀a1'])('rejects invalid password %s before creating a request', value => {
    const fetcher = vi.spyOn(globalThis, 'fetch'), uuid = vi.spyOn(crypto, 'randomUUID');
    finish(); enterFinish('enroll', value); submit('Complete account request');
    expect(screen.getByRole('alert')).toHaveTextContent('at least 12 characters');
    expect(fetcher).not.toHaveBeenCalled(); expect(uuid).not.toHaveBeenCalled();
  });

  it('accepts Unicode letters and numbers under the same codepoint and UTF-8 limits as the server', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(completed());
    finish('recover'); enterFinish('recover', '界'.repeat(11) + '١'); submit('Complete account request');
    expect(await screen.findByRole('status')).toBeInTheDocument(); expect(fetcher).toHaveBeenCalledOnce();
  });

  it.each([419, 500, 422])('never changes the completion bytes after uncertainty, even after status %s', async status => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('lost success'))
      .mockResolvedValueOnce(json({ message: 'PRIVATE' }, status)).mockResolvedValueOnce(completed());
    const uuid = vi.spyOn(crypto, 'randomUUID');
    finish(); enterFinish(); submit('Complete account request');
    expect(await screen.findByRole('alert')).toHaveTextContent('Completion could not be confirmed');
    expect(screen.getByLabelText('Name')).toHaveAttribute('readonly'); expect(screen.getByLabelText('New password')).toHaveAttribute('readonly');
    expect(screen.queryByRole('link', { name: 'Request a new test message' })).not.toBeInTheDocument();
    submit('Retry same details'); expect(await screen.findByRole('button', { name: 'Retry same details' })).toBeEnabled();
    submit('Retry same details'); expect(await screen.findByRole('status')).toHaveTextContent('complete');
    expect(fetcher.mock.calls).toHaveLength(3); expect(uuid).toHaveBeenCalledOnce();
    expect(new Set(fetcher.mock.calls.map(([, init]) => init?.body)).size).toBe(1);
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
  });

  it('clears the password and permits correction after a first definitive rejection', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ message: 'PRIVATE' }, 422));
    finish(); enterFinish(); submit('Complete account request');
    expect(await screen.findByRole('alert')).toHaveTextContent('Check your details or request a new test message');
    expect(screen.getByLabelText('New password')).toHaveValue(''); expect(screen.getByLabelText('Name')).not.toHaveAttribute('readonly');
    expect(screen.getByRole('link', { name: 'Request a new test message' })).toHaveAttribute('href', '/account/create');
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
  });

  it('rejects duplicate submits, aborts on unmount and cannot reuse the captured proof on a new mount', async () => {
    let resolve!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(done => { resolve = done; }));
    const view = finish(); enterFinish(); submit('Complete account request'); submit('Completing request…');
    expect(fetcher).toHaveBeenCalledOnce(); const signal = fetcher.mock.calls[0][1]?.signal;
    view.unmount(); expect(signal?.aborted).toBe(true);
    await act(async () => resolve(completed()));
    render(<CustomerAccessFinish testOnly siteContent={defaultSiteContent} />);
    expect(screen.queryByRole('status')).not.toBeInTheDocument(); expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
  });

  it('forgets proof, password and request bytes when a page enters browser history', async () => {
    let resolve!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(done => { resolve = done; }));
    finish(); enterFinish(); submit('Complete account request');
    act(() => window.dispatchEvent(new Event('pagehide')));
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Retry same details' })).not.toBeInTheDocument();
    await act(async () => resolve(completed()));
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('A refreshed or incomplete page');
  });

  it('retains the exact retry body after the bounded timeout', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce((_input, init) => new Promise((_done, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    })).mockResolvedValueOnce(completed());
    finish(); enterFinish(); submit('Complete account request');
    await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    expect(screen.getByRole('alert')).toHaveTextContent('Retry the same details');
    await act(async () => submit('Retry same details'));
    expect(screen.getByRole('status')).toHaveTextContent('complete');
    expect(fetcher.mock.calls[0][1]?.body).toBe(fetcher.mock.calls[1][1]?.body);
  });
});
