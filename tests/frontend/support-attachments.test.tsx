import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SupportAttachments, validSupportAttachment, validSupportAttachmentList, type SupportAttachmentList } from '../../resources/js/components/SupportAttachments';

const source = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const privateFile = () => new File(['Synthetic attachment.'], 'private-synthetic.txt', { type: 'text/plain' });
const file = { name: 'private-synthetic.txt', mime: 'text/plain', sizeBytes: 21, sha256: 'a'.repeat(64) };
const attachment = (state: 'quarantined' | 'ready' | 'failed' = 'quarantined') => ({ attachmentId: id, sourceVersion: 0, state, receivedBytes: 21, totalBytes: 21, file,
  expiresAt: '2030-10-07T00:00:00+00:00', attempt: state === 'quarantined' ? 0 : 1, canRetry: state !== 'ready', canDownload: state === 'ready' });
const library = (items: ReturnType<typeof attachment>[] = []): SupportAttachmentList => ({ sourceVersion: 0, canIntake: true, attachments: items,
  policy: { maxBytes: 5242880, maxFiles: 10, retentionSeconds: 86400, provenance: 'synthetic_technical_policy' } });
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const base = `/private-support/inquiries/${source}/attachments`;
const props = { sourceId: source, sourceKind: 'inquiry' as const, renderScope: 'server-page-scope' };
async function open() { fireEvent.click(screen.getByRole('button', { name: 'Open private attachments' })); await screen.findByRole('button', { name: 'Refresh private attachments' }); }
beforeEach(() => { const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'synthetic-token'; document.head.append(meta); });
afterEach(() => { document.querySelectorAll('meta[name="csrf-token"]').forEach(meta => meta.remove()); document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'; });

describe('bounded private attachment projection', () => {
  it('admits exact confirmed states without storage, owner, account or URL fields', () => {
    expect(validSupportAttachmentList(library())).toBe(true); expect(validSupportAttachment(attachment('ready'))).toBe(true);
    expect(validSupportAttachment({ ...attachment(), state: 'receiving', receivedBytes: 0, canRetry: false })).toBe(true);
  });
  it.each([
    { ...attachment(), privateUrl: '/private/original' }, { ...attachment(), file: { ...file, ownerHash: 'private' } },
    { ...attachment(), file: { ...file, name: 'bad\nname.txt' } }, { ...attachment(), file: { ...file, sizeBytes: 5242881 } },
    { ...attachment(), state: 'receiving' }, { ...attachment(), canDownload: true }, { ...attachment(), attempt: 4 },
  ])('refuses privately extended or contradictory manifests', value => expect(validSupportAttachment(value)).toBe(false));
  it('rejects duplicates and unknown policy fields', () => {
    expect(validSupportAttachmentList(library([attachment(), attachment()]))).toBe(false);
    expect(validSupportAttachmentList({ ...library(), policy: { ...library().policy, productionApproved: true } })).toBe(false);
  });
});

describe('real private upload, scan and retained-original actions', () => {
  it('loads deliberately, sends one file with stable request identity and only shows confirmed progress', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library())).mockResolvedValueOnce(response({ replayed: false, attachment: attachment() }, 201));
    const storage = vi.spyOn(Storage.prototype, 'setItem'); render(<SupportAttachments {...props} />); expect(fetcher).not.toHaveBeenCalled(); await open();
    expect(fetcher.mock.calls[0]).toEqual([base, expect.objectContaining({ credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', signal: expect.any(AbortSignal) })]);
    const selected = privateFile(); fireEvent.change(screen.getByLabelText('Choose private file'), { target: { files: [selected] } }); fireEvent.click(screen.getByRole('button', { name: 'Send private file' }));
    await screen.findByText(/21 of 21 bytes confirmed/); expect(fetcher.mock.calls[1][0]).toBe(`${base}/upload`);
    expect(fetcher.mock.calls[1][1]).toEqual(expect.objectContaining({ method: 'POST', body: selected, headers: expect.objectContaining({ 'Content-Type': 'application/octet-stream', 'X-Attachment-Name': btoa(selected.name), 'X-Source-Version': '0', 'X-CSRF-TOKEN': 'synthetic-token', 'X-Request-Key': expect.stringMatching(/^[a-f0-9-]{36}$/) }) }));
    expect(storage).not.toHaveBeenCalled(); expect(screen.queryByRole('button', { name: /Download private/ })).not.toBeInTheDocument();
  });
  it('an unknown upload offers an explicit same-file same-key retry without automatic network work', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library())).mockRejectedValueOnce(new Error('Synthetic unknown acknowledgement'))
      .mockResolvedValueOnce(response({ replayed: true, attachment: attachment() }));
    render(<SupportAttachments {...props} />); await open(); const selected = privateFile(); fireEvent.change(screen.getByLabelText('Choose private file'), { target: { files: [selected] } });
    fireEvent.click(screen.getByRole('button', { name: 'Send private file' })); await screen.findByRole('button', { name: 'Retry same upload' }); expect(fetcher).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: 'Retry same upload' })); await screen.findByText(/21 of 21 bytes confirmed/);
    expect(fetcher.mock.calls[2][1]?.body).toBe(selected);
    expect((fetcher.mock.calls[2][1]?.headers as Record<string, string>)['X-Request-Key']).toBe((fetcher.mock.calls[1][1]?.headers as Record<string, string>)['X-Request-Key']);
  });
  it('scan failure stays quarantined and retry carries current attempt plus source revision', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library([attachment()])))
      .mockResolvedValueOnce(response({ attachment: attachment('failed') })).mockResolvedValueOnce(response({ attachment: { ...attachment('ready'), attempt: 2 } }));
    render(<SupportAttachments {...props} />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Scan private-synthetic.txt' }));
    await screen.findByRole('button', { name: 'Retry scan private-synthetic.txt' }); expect(screen.queryByRole('button', { name: 'Download private-synthetic.txt' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry scan private-synthetic.txt' })); await screen.findByRole('button', { name: 'Download private-synthetic.txt' });
    expect(JSON.parse(String(fetcher.mock.calls[2][1]?.body))).toEqual({ sourceVersion: 0, attempt: 1 });
  });
  it('403 erases filenames and draft input permanently for this mounted scope', async () => {
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library([attachment()]))).mockResolvedValueOnce(response({}, 403));
    render(<SupportAttachments {...props} />); await open(); fireEvent.change(screen.getByLabelText('Choose private file'), { target: { files: [privateFile()] } });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh private attachments' })); await screen.findByText(/Open the page again after restoring access/);
    expect(screen.queryByText('private-synthetic.txt', { exact: true })).not.toBeInTheDocument(); expect(screen.queryByLabelText('Choose private file')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /private attachments$/ })).not.toBeInTheDocument(); expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it('clears private input before the next account/source read and does not revive it on 503', async () => {
    let resolve!: (value: Response) => void; const deferred = new Promise<Response>(done => { resolve = done; });
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library([attachment()]))).mockReturnValueOnce(deferred);
    render(<SupportAttachments {...props} />); await open(); fireEvent.change(screen.getByLabelText('Choose private file'), { target: { files: [privateFile()] } });
    fireEvent.click(screen.getByRole('button', { name: 'Refresh private attachments' }));
    expect(screen.queryByText('private-synthetic.txt', { exact: true })).not.toBeInTheDocument(); expect(screen.queryByLabelText('Choose private file')).not.toBeInTheDocument();
    await act(async () => { resolve(response({}, 503)); }); await screen.findByText(/could not be confirmed/);
    expect(screen.queryByLabelText('Choose private file')).not.toBeInTheDocument();
  });
  it('source/page scope changes erase old data before a later response resolves', async () => {
    let resolve!: (value: Response) => void; const deferred = new Promise<Response>(done => { resolve = done; });
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library([attachment()]))).mockReturnValueOnce(deferred);
    const view = render(<SupportAttachments {...props} />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Refresh private attachments' }));
    const signal = fetcher.mock.calls[1][1]?.signal as AbortSignal; view.rerender(<SupportAttachments {...props} renderScope="fresh-other-account-scope" />);
    expect(signal.aborted).toBe(true); await act(async () => { resolve(response(library([attachment('ready')]))); });
    expect(screen.queryByText('private-synthetic.txt', { exact: true })).not.toBeInTheDocument(); expect(fetcher).toHaveBeenCalledTimes(2);
  });
  it('pagehide/unmount closes requests and late scan JSON never revives private data', async () => {
    let resolve!: (value: unknown) => void; const deferred = new Promise<unknown>(done => { resolve = done; });
    const pending = response({}); vi.spyOn(pending, 'json').mockReturnValue(deferred);
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library([attachment()]))).mockResolvedValueOnce(pending);
    render(<SupportAttachments {...props} />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Scan private-synthetic.txt' }));
    await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2)); await act(async () => { window.dispatchEvent(new Event('pagehide')); resolve({ attachment: attachment('ready') }); });
    expect(screen.queryByText('private-synthetic.txt', { exact: true })).not.toBeInTheDocument(); expect(screen.queryByRole('button', { name: /Download/ })).not.toBeInTheDocument();
  });
  it('retained deletion exposes pending cleanup without claiming physical removal', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(response(library([attachment('ready')])))
      .mockResolvedValueOnce(response({ attachment: { ...attachment('ready'), state: 'deleted', canDownload: false, cleanup: 'pending' } }));
    render(<SupportAttachments {...props} />); await open(); fireEvent.click(screen.getByRole('button', { name: 'Delete private-synthetic.txt' }));
    await screen.findByText(/cleanup is pending/); expect(screen.queryByRole('button', { name: /Download/ })).not.toBeInTheDocument();
  });
});
