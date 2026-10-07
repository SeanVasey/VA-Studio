import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { defaultSiteContent } from '../../resources/js/lib/site-content';

vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
vi.mock('../../resources/js/lib/customer-session', async original => ({ ...await original<typeof import('../../resources/js/lib/customer-session')>(), navigateCustomerSession: vi.fn() }));
const page = (scope?: string | null, enabled = true) => <CustomerLibrary testOnly siteContent={defaultSiteContent} customer={{ name: 'Synthetic same customer name' }} testListeningLibraryEnabled={enabled} listeningLibraryScope={scope} />;
const response = (name = 'Independent private name') => new Response(JSON.stringify({ library: { listeningSchema: 1, version: 1, favorites: [], playlists: [{ id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', name, tracks: [] }], limits: { favorites: 50, playlists: 10, playlistTracks: 25 } } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
async function open() { fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' })); await screen.findByRole('button', { name: 'Open playlist Independent private name' }); }

describe('independent real customer page listening lifecycle', () => {
  it.each([undefined, null, '', 'invalid', 'a'.repeat(31), 'A'.repeat(32)])('requires a valid fresh opaque server scope (%s)', scope => {
    const fetcher = vi.spyOn(globalThis, 'fetch');
    render(page(scope));
    expect(screen.queryByRole('button', { name: 'Open saved tracks' })).not.toBeInTheDocument();
    expect(fetcher).not.toHaveBeenCalled();
  });
  it('does not mount with a disabled capability even when the scope is valid', () => {
    render(page('a'.repeat(32), false));
    expect(screen.queryByRole('button', { name: 'Open saved tracks' })).not.toBeInTheDocument();
  });
  it('remounts private state on scope change without automatically reloading the next account', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response());
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    const view = render(page('a'.repeat(32))); expect(fetcher).not.toHaveBeenCalled(); await open();
    view.rerender(page('b'.repeat(32)));
    expect(screen.queryByText('Independent private name')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open saved tracks' })).toBeEnabled();
    expect(fetcher).toHaveBeenCalledTimes(1); expect(storage).not.toHaveBeenCalled();
  });
  it('unmounts and aborts a pending private response as soon as sign-out starts', async () => {
    let release!: (value: Response) => void;
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementationOnce(() => new Promise<Response>(resolve => { release = resolve; }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ authenticated: false, next: '/account/sign-in' }), { headers: { 'Content-Type': 'application/json' } }));
    render(page('a'.repeat(32)));
    fireEvent.click(screen.getByRole('button', { name: 'Open saved tracks' }));
    const signal = fetcher.mock.calls[0][1]?.signal;
    fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    expect(signal?.aborted).toBe(true);
    expect(screen.queryByRole('button', { name: 'Open saved tracks' })).not.toBeInTheDocument();
    await act(async () => { release(response()); });
    expect(screen.queryByText('Independent private name')).not.toBeInTheDocument();
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  });
  it('erases actual page private state on pagehide without refetching on return', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response());
    render(page('a'.repeat(32))); await open();
    act(() => window.dispatchEvent(new Event('pagehide')));
    expect(screen.queryByText('Independent private name')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Open saved tracks' })).toBeEnabled();
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
});
