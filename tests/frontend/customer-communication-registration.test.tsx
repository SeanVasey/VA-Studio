import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { defaultSiteContent } from '../../resources/js/lib/site-content';

vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/components/OwnedTestOrderHistory', () => ({ OwnedTestOrderHistory: () => null }));
vi.mock('../../resources/js/components/CustomerOrderLookup', () => ({ CustomerOrderLookup: () => null }));
const props = { testOnly: true as const, siteContent: defaultSiteContent, customer: { name: 'Synthetic account' } };
const scope = 'a'.repeat(32);
const preferences = { schema: 1, purposes: [{ purpose: 'email_marketing', version: 0, status: 'unknown', canGrant: true, suppression: { status: 'not_requested' },
  notice: { version: 'synthetic-v1', hash: 'b'.repeat(64), text: 'PRIVATE synthetic notice for the current account.' } }] };
const json = (value: unknown) => new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } });
afterEach(() => { vi.restoreAllMocks(); document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; });

describe('authenticated communication preference registration', () => {
  it.each([undefined, '', 'account-id-1'])('does not mount without an opaque current server scope: %s', current => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(<CustomerLibrary {...props} communicationPreferencesAvailable communicationPreferencesScope={current} />);
    expect(screen.queryByRole('region', { name: 'Your communication preferences' })).not.toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('keeps the affirmative choice unchecked and erases the mounted notice before sign-out completes', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async input => {
      if (String(input) === '/account/communication-preferences') return json({ preferences });
      return new Promise<Response>(() => {});
    });
    render(<CustomerLibrary {...props} communicationPreferencesAvailable communicationPreferencesScope={scope} />);
    fireEvent.click(screen.getByRole('button', { name: 'Open communication preferences' }));
    expect(await screen.findByText(preferences.purposes[0].notice.text)).toBeInTheDocument();
    expect(screen.getByRole('checkbox')).not.toBeChecked();
    fireEvent.click(screen.getByRole('checkbox'));
    expect(screen.getByRole('checkbox')).toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(screen.queryByText(preferences.purposes[0].notice.text)).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(fetcher).toHaveBeenCalledWith('/account/sign-out', expect.objectContaining({ credentials: 'same-origin', cache: 'no-store' }));
  });

  it('unmounts the old private preference subtree when the server scope changes', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(json({ preferences }));
    const view = render(<CustomerLibrary {...props} communicationPreferencesAvailable communicationPreferencesScope={scope} />);
    fireEvent.click(screen.getByRole('button', { name: 'Open communication preferences' }));
    await screen.findByText(preferences.purposes[0].notice.text);
    fireEvent.click(screen.getByRole('checkbox'));
    view.rerender(<CustomerLibrary {...props} communicationPreferencesAvailable communicationPreferencesScope={'c'.repeat(32)} />);
    expect(screen.queryByText(preferences.purposes[0].notice.text)).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open communication preferences' })).toBeEnabled();
  });
});
