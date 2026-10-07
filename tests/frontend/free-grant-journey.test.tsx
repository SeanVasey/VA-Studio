import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FreeGrantJourney, validFreeDefinition, validFreeOrigin, type FreeDefinition, type FreeOrigin } from '../../resources/js/components/FreeGrantJourney';
const definitionId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', originId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', authorizationId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const definition: FreeDefinition = { id: definitionId, definitionHash: 'a'.repeat(64), reviewHash: 'b'.repeat(64), version: 1, open: true, title: 'Synthetic exact WAV grant', purpose: 'free-license-grant', assentText: 'Explicit synthetic free-purpose text. No purchase or marketing enrollment.', termsReference: 'EXPLICIT-SYNTHETIC-FREE-REVIEW',
  license: { licenseVersionId: '1', name: 'Nonbinding synthetic license', version: 1, type: 'non-exclusive', features: ['WAV'], deliverableRoles: ['master_wav'], termsText: 'EXACT RETAINED NONBINDING TERMS <script>private()</script>' },
  product: { id: 1, title: 'Synthetic recording', artist: 'Synthetic artist', slug: 'synthetic' }, testOnly: true, maxOrigins: 2, maxDownloads: 3, tokenTtlSeconds: 60, assets: [{ id: 1, role: 'master_wav', sha256: 'c'.repeat(64), size_bytes: 1024, mime_type: 'audio/wav' }] };
const origin: FreeOrigin = { id: originId, definitionId, title: definition.title, originHash: 'd'.repeat(64), purpose: 'free-license-grant', declaredName: 'Private buyer declaration', acceptedAt: '2026-10-07T01:02:03Z', assentText: definition.assentText, license: definition.license, collection: 'none', testOnly: true, documentStatus: 'pending', renderAttempts: 0, maxDownloads: 3, files: [] };
const complete: FreeOrigin = { ...origin, documentStatus: 'complete', renderAttempts: 1, files: [{ kind: 'master_wav', sha256: 'c'.repeat(64), sizeBytes: 1024, mimeType: 'audio/wav' }, { kind: 'contract', sha256: 'e'.repeat(64), sizeBytes: 2048, mimeType: 'application/pdf' }] };
const listing = (origins: FreeOrigin[] = []) => ({ schemaVersion: 1, definitions: [definition], origins, definitionLimit: 50, originLimit: 20 });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });
const reviewed = () => response({ definition, declaredName: origin.declaredName, assentHash: 'f'.repeat(64) });
beforeEach(() => { document.head.insertAdjacentHTML('beforeend', `<meta data-free-csrf name="csrf-token" content="${'c'.repeat(40)}">`); });
afterEach(() => { vi.useRealTimers(); document.head.querySelectorAll('[data-free-csrf]').forEach(e => e.remove()); });
async function enterReview() { fireEvent.click(screen.getByRole('button', { name: 'Open free grants' })); await screen.findByText(definition.title); fireEvent.click(screen.getByRole('button', { name: `Review ${definition.title}` })); fireEvent.change(screen.getByLabelText('Your declared name'), { target: { value: origin.declaredName } }); fireEvent.click(screen.getByRole('button', { name: 'Review exact free terms' })); await screen.findByLabelText('Exact free assent'); }

describe('mounted explicit free purpose journey', () => {
  it('requires server review and affirmative assent before saving, prepares an original and submits only an explicit native download', async () => {
    const token = 'SYNTHETIC_PRIVATE_TOKEN'.padEnd(43, 'a');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(reviewed()).mockResolvedValueOnce(response({ origin })).mockResolvedValueOnce(response({ origin: complete }))
      .mockResolvedValueOnce(response({ authorization: { id: authorizationId, token, expiresAt: '2026-10-07 01:03:03', kind: 'master_wav', filename: `free-grant-${originId}-master_wav.wav`, mimeType: 'audio/wav' } }));
    const forms: { action: string; fields: Record<string, string> }[] = [];
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function (this: HTMLFormElement) { forms.push({ action: this.getAttribute('action')!, fields: Object.fromEntries(Array.from(this.querySelectorAll('input'), f => [f.name, f.value])) }); });
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    render(<FreeGrantJourney />); expect(fetcher).not.toHaveBeenCalled(); await enterReview();
    expect(screen.getByRole('button', { name: 'Accept this free grant' })).toBeDisabled();
    expect(screen.getByText(definition.license.termsText)).toBeInTheDocument(); expect(document.querySelector('script')).toBeNull();
    fireEvent.click(screen.getByRole('checkbox')); fireEvent.click(screen.getByRole('button', { name: 'Accept this free grant' })); await screen.findByLabelText('Retained free grant');
    const accepted = JSON.parse(String(fetcher.mock.calls[2][1]?.body));
    expect(accepted).toEqual({ requestKey: expect.any(String), definitionHash: definition.definitionHash, reviewHash: definition.reviewHash, expectedVersion: 1, declaredName: origin.declaredName, affirmed: true, assentHash: 'f'.repeat(64) });
    fireEvent.click(screen.getByRole('button', { name: 'Prepare original PDF' })); await screen.findByRole('button', { name: 'Authorize master_wav download' });
    fireEvent.click(screen.getByRole('button', { name: 'Authorize master_wav download' })); await screen.findByRole('button', { name: 'Download authorized file' });
    expect(submit).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Download authorized file' }));
    expect(forms).toEqual([{ action: `/free-grants/authorizations/${authorizationId}/redeem`, fields: { token, _token: 'c'.repeat(40) } }]);
    expect(fetcher.mock.calls.some(([path]) => String(path).includes(token))).toBe(false);
    expect(document.querySelector(`input[value="${token}"]`)).toBeNull(); expect(document.querySelector('form[hidden]')).toBeNull();
    expect(storage).not.toHaveBeenCalled(); expect(screen.getByRole('alert')).toHaveTextContent('an interrupted attempt can be consumed');
    expect(fetcher.mock.calls.every(([, init]) => init?.cache === 'no-store' && init.credentials === 'same-origin' && init.redirect === 'error')).toBe(true);
  });
  it('holds the same assent request after a lost response, refreshes saved state, and retries only deliberately with the same immutable body', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(reviewed()).mockRejectedValueOnce(new Error('PRIVATE TRANSPORT DETAIL')).mockResolvedValueOnce(response(listing([origin]))).mockResolvedValueOnce(response({ origin }));
    render(<FreeGrantJourney />); await enterReview(); fireEvent.click(screen.getByRole('checkbox')); fireEvent.click(screen.getByRole('button', { name: 'Accept this free grant' }));
    await screen.findByRole('button', { name: 'Retry the exact request' }); await screen.findByRole('alert');
    const request = fetcher.mock.calls[2][1]?.body;
    fireEvent.click(screen.getByRole('button', { name: 'Refresh free grants' })); await screen.findByRole('button', { name: `Open saved grant ${origin.title}` });
    expect(fetcher).toHaveBeenCalledTimes(4); fireEvent.click(screen.getByRole('button', { name: 'Retry the exact request' }));
    await screen.findByLabelText('Retained free grant'); expect(fetcher.mock.calls[4][1]?.body).toBe(request); expect(screen.queryByText(/PRIVATE TRANSPORT/)).not.toBeInTheDocument();
  });
  it.each([403, 404, 419])('clears all selected terms, private unsaved name, pending assent and download secrets on a %s read denial', async status => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing())).mockResolvedValueOnce(response({}, status));
    render(<FreeGrantJourney />); fireEvent.click(screen.getByRole('button', { name: 'Open free grants' })); await screen.findByText(definition.title);
    fireEvent.click(screen.getByRole('button', { name: `Review ${definition.title}` })); fireEvent.change(screen.getByLabelText('Your declared name'), { target: { value: 'FIRST_ACCOUNT_PRIVATE_UNSAVED_NAME' } });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh free grants' })); await screen.findByRole('link', { name: 'Open a fresh sign-in page' });
    expect(screen.queryByLabelText('Your declared name')).not.toBeInTheDocument(); expect(screen.queryByText(definition.title)).not.toBeInTheDocument(); expect(screen.getByRole('button', { name: 'Open free grants' })).toBeDisabled(); expect(document.body.textContent).not.toContain('FIRST_ACCOUNT_PRIVATE_UNSAVED_NAME');
  });
  it('ignores a late authorization after page departure and never auto-submits a native file request', async () => {
    let finish!: (r: Response) => void;
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(listing([complete]))).mockResolvedValueOnce(response({ origin: complete })).mockImplementationOnce(() => new Promise(r => { finish = r; }));
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(() => {});
    render(<FreeGrantJourney />); fireEvent.click(screen.getByRole('button', { name: 'Open free grants' })); await screen.findByRole('button', { name: `Open saved grant ${origin.title}` });
    fireEvent.click(screen.getByRole('button', { name: `Open saved grant ${origin.title}` })); await screen.findByRole('button', { name: 'Authorize contract download' });
    fireEvent.click(screen.getByRole('button', { name: 'Authorize contract download' })); await waitFor(() => expect(finish).toBeDefined());
    await act(async () => { window.dispatchEvent(new Event('pagehide')); finish(response({ authorization: { token: 'LATE_PRIVATE_TOKEN' } })); });
    expect(screen.queryByLabelText('Retained free grant')).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: 'Download authorized file' })).not.toBeInTheDocument(); expect(submit).not.toHaveBeenCalled();
  });
  it('rejects malformed private extensions and paid provenance in free projections', () => {
    expect(validFreeDefinition(definition)).toBe(true); expect(validFreeOrigin(complete)).toBe(true);
    expect(validFreeDefinition({ ...definition, ownerKey: 'PRIVATE' })).toBe(false); expect(validFreeDefinition({ ...definition, testOnly: false })).toBe(false);
    expect(validFreeOrigin({ ...complete, collection: 'paid' })).toBe(false); expect(validFreeOrigin({ ...complete, files: [{ ...complete.files[0], storage_path: '/private/master' }] })).toBe(false);
  });
});
