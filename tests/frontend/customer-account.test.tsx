import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import CustomerSignIn from '../../resources/js/Pages/CustomerSignIn';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import Storefront from '../../resources/js/Pages/Storefront';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
import { changeCustomerSession, navigateCustomerSession } from '../../resources/js/lib/customer-session';

vi.mock('@inertiajs/react', async importOriginal => ({ ...await importOriginal<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/lib/customer-session', async importOriginal => ({
  ...await importOriginal<typeof import('../../resources/js/lib/customer-session')>(), navigateCustomerSession: vi.fn(),
}));
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const signIn = () => render(<CustomerSignIn testOnly siteContent={defaultSiteContent} />);
const library = () => render(<CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic Customer' }} />);
const id = '730000ab-0000-4000-8000-000000000001';
const summary = { id, createdAt: '2026-10-01T12:00:00.000000Z', testOnly: true, payable: false,
  currency: 'USD', totalMinor: 4999, status: 'paid_exception', paymentStatus: 'verified',
  finalizationStatus: 'paid_exception', contractStatus: 'blocked', fulfillmentStatus: 'blocked' };
const history = { history: { orderHistorySchema: 1, testOnly: true, orders: [summary], limit: 20, nextCursor: null } };
async function enterCredentials() {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText('Email address'), 'synthetic@example.test');
  await user.type(screen.getByLabelText('Password'), 'Synthetic password');
  return user;
}
afterEach(() => {
  vi.useRealTimers(); vi.mocked(navigateCustomerSession).mockClear();
  document.head.querySelectorAll('[data-account-token]').forEach(element => element.remove());
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
});
function token() {
  const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'synthetic-document-token'; meta.dataset.accountToken = ''; document.head.append(meta);
}

describe('customer sign-in', () => {
  it('posts only credentials with CSRF and replaces the document after strict success, without storage or Inertia navigation', async () => {
    token(); const storage = vi.spyOn(Storage.prototype, 'setItem'), visit = vi.spyOn(router, 'visit');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ authenticated: true, next: '/account' }));
    signIn(); const user = await enterCredentials();
    expect(fetcher).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(fetcher).toHaveBeenCalledExactlyOnceWith('/account/sign-in', expect.objectContaining({
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: expect.any(AbortSignal),
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'synthetic-document-token' },
      body: JSON.stringify({ email: 'synthetic@example.test', password: 'Synthetic password' }),
    }));
    expect(navigateCustomerSession).toHaveBeenCalledExactlyOnceWith('/account');
    expect(screen.getByLabelText('Password')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled();
    expect(storage).not.toHaveBeenCalled(); expect(visit).not.toHaveBeenCalled();
    expect(screen.queryByRole('link', { name: /register|forgot|recover|claim/i })).not.toBeInTheDocument();
  });

  it('uses the current encrypted CSRF cookie rather than a retained document token', async () => {
    token(); document.cookie = 'XSRF-TOKEN=synthetic%2Bcookie%3D; path=/';
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ authenticated: false, next: '/account/sign-in' }));
    expect(await changeCustomerSession('sign-out', null, new AbortController().signal)).toEqual({ kind: 'complete', next: '/account/sign-in' });
    expect(fetcher.mock.calls[0][1]?.headers).toEqual({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'synthetic+cookie=' });
  });

  it.each([422, 429])('clears the password and permits a deliberate retry after generic %s without reflecting server details', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ message: 'PRIVATE account details' }, status));
    signIn(); const user = await enterCredentials(); await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('alert')).toHaveFocus();
    expect(screen.getByRole('alert')).toHaveTextContent(status === 422 ? 'Check your details' : 'Wait before trying again');
    expect(screen.getByLabelText('Password')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled();
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument(); expect(navigateCustomerSession).not.toHaveBeenCalled();
  });

  it.each([419, 403, 500])('requires fresh session state after %s rather than retrying the old form', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({}, status));
    signIn(); const user = await enterCredentials(); await user.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Sign-in could not be confirmed');
    expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled(); expect(navigateCustomerSession).not.toHaveBeenCalled();
  });

  it.each([
    ['external redirect', { authenticated: true, next: 'https://example.test/account' }],
    ['protocol-relative redirect', { authenticated: true, next: '//example.test' }],
    ['query redirect', { authenticated: true, next: '/account?email=private' }],
    ['wrong auth state', { authenticated: false, next: '/account' }],
    ['extra private fields', { authenticated: true, next: '/account', customer: 'PRIVATE' }],
    ['missing state', { next: '/account' }], ['array', []], ['null', null],
  ])('rejects %s in an otherwise successful response', async (_name, body) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json(body));
    const result = await changeCustomerSession('sign-in', { email: 'a@example.test', password: 'synthetic' }, new AbortController().signal);
    expect(result.kind).toBe('reload'); expect(navigateCustomerSession).not.toHaveBeenCalled();
  });

  it.each(['network', 'html', 'oversized', 'redirect'])('treats %s as an ambiguous transition', async kind => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    if (kind === 'network') fetcher.mockRejectedValue(new Error('PRIVATE response'));
    else if (kind === 'html') fetcher.mockResolvedValue(new Response('<html>PRIVATE</html>'));
    else if (kind === 'oversized') fetcher.mockResolvedValue(new Response(' '.repeat(4097)));
    else { const response = json({ authenticated: true, next: '/account' }); Object.defineProperty(response, 'redirected', { value: true }); fetcher.mockResolvedValue(response); }
    const result = await changeCustomerSession('sign-in', { email: 'a@example.test', password: 'synthetic' }, new AbortController().signal);
    expect(result.kind).toBe('reload'); expect(JSON.stringify(result)).not.toContain('PRIVATE');
  });

  it('prevents duplicate requests and ignores a successful response after unmount', async () => {
    let resolve!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(done => { resolve = done; }));
    const view = signIn(); await enterCredentials();
    const form = screen.getByRole('button', { name: 'Sign in' }).closest('form')!;
    fireEvent.submit(form); fireEvent.submit(form); expect(fetcher).toHaveBeenCalledTimes(1);
    expect(screen.getByLabelText('Password')).toHaveValue('');
    const signal = fetcher.mock.calls[0][1]?.signal;
    view.unmount(); expect(signal?.aborted).toBe(true);
    await act(async () => resolve(json({ authenticated: true, next: '/account' })));
    expect(navigateCustomerSession).not.toHaveBeenCalled();
  });

  it('bounds a stalled request and requires fresh state after timeout', async () => {
    vi.useFakeTimers();
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation((_input, init) => new Promise((_done, reject) => {
      init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
    }));
    signIn(); fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'a@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'synthetic' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Sign in' }).closest('form')!);
    await act(async () => vi.advanceTimersByTimeAsync(20_000));
    expect(fetcher.mock.calls[0][1]?.signal?.aborted).toBe(true);
    expect(screen.getByRole('alert')).toHaveTextContent('Open a fresh sign-in page');
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled();
  });
});

describe('customer library', () => {
  it('reuses owner-scoped history and retained payment status without browser identity, payment writes or automatic guest claims', async () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => String(input) === '/orders/history' ? json(history) : json({}, 503));
    library(); const user = userEvent.setup(); expect(fetcher).not.toHaveBeenCalled();
    expect(screen.getByText('Synthetic Customer')).toBeInTheDocument();
    const region = within(screen.getByRole('region', { name: 'Your account test orders' }));
    expect(region.getByText(/Earlier guest orders/)).toBeInTheDocument();
    await user.click(region.getByRole('button', { name: 'Browse account orders' }));
    expect(await region.findByRole('heading', { name: 'Account orders' })).toHaveFocus();
    await user.click(region.getByRole('button', { name: `View test order status ${id}` }));
    expect(await region.findByRole('alert')).toHaveTextContent('previously verified test payment remains recorded');
    expect(region.queryByRole('button', { name: /Open Stripe|Retry Stripe|Download/ })).not.toBeInTheDocument();
    expect(fetcher.mock.calls.map(([input, init]) => [input, init?.method ?? 'GET'])).toEqual([['/orders/history', 'GET'], [`/orders/${id}/checkout`, 'GET']]);
    expect(storage).not.toHaveBeenCalled();
  });

  it.each([true, false])('shows the account-specific empty or unavailable state: empty=%s', async empty => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(empty ? json({ history: { ...history.history, orders: [] } }) : json({ message: 'PRIVATE' }, 403));
    library(); await userEvent.setup().click(screen.getByRole('button', { name: 'Browse account orders' }));
    if (empty) expect(await screen.findByRole('status')).toHaveTextContent('No test orders belong to this account.');
    else expect(await screen.findByRole('alert')).toHaveTextContent('Your account’s test order history could not be loaded. Refresh the list or sign in again.');
    expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument();
  });

  it.each([true, false])('hides all customer data immediately on sign-out and keeps it hidden on success=%s', async success => {
    let resolve!: (response: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise(done => { resolve = done; }));
    library(); await userEvent.setup().click(screen.getByRole('button', { name: 'Sign out' }));
    expect(screen.queryByText('Synthetic Customer')).not.toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Your account test orders' })).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledExactlyOnceWith('/account/sign-out', expect.objectContaining({ method: 'POST', body: '{}', cache: 'no-store', redirect: 'error' }));
    await act(async () => resolve(success ? json({ authenticated: false, next: '/account/sign-in' }) : json({}, 419)));
    if (success) expect(navigateCustomerSession).toHaveBeenCalledExactlyOnceWith('/account/sign-in');
    else {
      expect(screen.getByRole('alert')).toHaveFocus(); expect(screen.getByRole('alert')).toHaveTextContent('Sign-out could not be confirmed');
      expect(screen.getByRole('link', { name: 'Open a fresh sign-in page' })).toHaveAttribute('href', '/account/sign-in');
      expect(navigateCustomerSession).not.toHaveBeenCalled();
    }
    expect(screen.queryByText('Synthetic Customer')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Sign out' })).not.toBeInTheDocument();
  });

  it('rejects a sign-in-shaped sign-out reply', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ authenticated: true, next: '/account' }));
    expect((await changeCustomerSession('sign-out', null, new AbortController().signal)).kind).toBe('reload');
  });
});

it.each([
  ['enabled catalog', true, false, false, true], ['disabled catalog', false, false, false, false],
  ['design preview', true, true, false, false], ['staff preview', true, false, true, false],
])('exposes a native customer route only for %s', (_label, enabled, designPreview, sitePreview, visible) => {
  const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
  render(<Storefront tracks={[]} licenseTiers={[]} customerAccountEnabled={enabled} designPreview={designPreview} sitePreview={sitePreview} />);
  const link = within(screen.getByRole('navigation', { name: 'Main navigation' })).queryByRole('link', { name: 'Your library' });
  expect(link !== null).toBe(visible);
  if (link) {
    expect(link).toHaveAttribute('href', '/account');
    let prevented: boolean | undefined;
    document.addEventListener('click', event => { prevented = event.defaultPrevented; event.preventDefault(); }, { once: true });
    fireEvent.click(link); expect(prevented).toBe(false); expect(visit).not.toHaveBeenCalled();
  }
});
