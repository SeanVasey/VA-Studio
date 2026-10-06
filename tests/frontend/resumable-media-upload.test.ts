import { describe, expect, it, vi } from 'vitest';
import { ResumableUploadClient, type UploadSession, type UploadState } from '../../resources/js/admin/resumable-media-upload';

const id = '11111111-1111-4111-8111-111111111111';
const hash = 'a'.repeat(64);
const session = (changes: Partial<UploadSession> = {}): UploadSession => ({ id, trackId: 1, role: 'master_wav', originalName: 'master.wav', sizeBytes: 10,
  sha256: hash, receivedBytes: 0, chunkBytes: 8 * 1024 * 1024, status: 'uploading', expiresAt: '2026-10-07T00:00:00Z', assetId: null, cleanupPending: false, ...changes });
const reply = (value: UploadSession) => new Response(JSON.stringify({ session: value }), { status: 200 });
const file = (size = 10) => new File([new Uint8Array(size)], 'master.wav');
function fixture() {
  const fetcher = vi.fn<typeof fetch>();
  const digest = vi.fn().mockResolvedValue(hash);
  const remember = vi.fn();
  const states: UploadState[] = [];
  const client = new ResumableUploadClient({ baseUrl: '/admin/resumable-uploads', csrfToken: 'test-csrf', fetcher, digest, remember,
    onChange: state => states.push(state) });
  return { client, fetcher, digest, remember, states };
}

describe('resumable private upload client', () => {
  it('hashes the original then sends exact server-sized sequential chunks with CSRF', async () => {
    const f = fixture(); const size = 8 * 1024 * 1024 + 3;
    f.fetcher.mockResolvedValueOnce(reply(session({ sizeBytes: size })))
      .mockResolvedValueOnce(reply(session({ sizeBytes: size, receivedBytes: 8 * 1024 * 1024 })))
      .mockResolvedValueOnce(reply(session({ sizeBytes: size, receivedBytes: size })));
    await f.client.start(file(size), 1, 'master_wav');
    expect(f.digest).toHaveBeenCalledOnce();
    expect(JSON.parse(f.fetcher.mock.calls[0][1]!.body as string)).toEqual({ trackId: 1, role: 'master_wav', sizeBytes: size, sha256: hash, originalName: 'master.wav' });
    const first = f.fetcher.mock.calls[1][1]!.body as FormData;
    const last = f.fetcher.mock.calls[2][1]!.body as FormData;
    expect(first.get('offset')).toBe('0'); expect((first.get('chunk') as File).size).toBe(8 * 1024 * 1024);
    expect(last.get('offset')).toBe(String(8 * 1024 * 1024)); expect((last.get('chunk') as File).size).toBe(3);
    expect(f.fetcher.mock.calls[1][1]).toMatchObject({ credentials: 'same-origin', cache: 'no-store', headers: { 'X-CSRF-TOKEN': 'test-csrf' } });
    expect(f.client.state.message).toContain('Finish upload');
    expect(f.fetcher).toHaveBeenCalledTimes(3); // Never finalize or process implicitly.
  });

  it('recovers a lost chunk response from a fresh server offset before resuming', async () => {
    const f = fixture();
    f.fetcher.mockResolvedValueOnce(reply(session())).mockRejectedValueOnce(new Error('Private transport details /tmp/not-for-user'));
    await f.client.start(file(), 1, 'master_wav');
    expect(f.client.state.needsInspection).toBe(true);
    expect(f.client.state.session?.receivedBytes).toBe(0);
    expect(f.client.state.message).not.toContain('/tmp');
    f.fetcher.mockResolvedValueOnce(reply(session({ receivedBytes: 10 })));
    await f.client.resume(file(), id);
    expect(f.fetcher.mock.calls[2][0]).toBe('/admin/resumable-uploads/' + id);
    expect(f.fetcher).toHaveBeenCalledTimes(3); // The already committed chunk is not resent.
    expect(f.client.state.needsInspection).toBe(false);
  });

  it('rejects a different file before appending to an inspected upload', async () => {
    const f = fixture(); f.fetcher.mockImplementation(async () => reply(session())); f.digest.mockResolvedValue('b'.repeat(64));
    await f.client.resume(file(), id);
    expect(f.client.state.message).toContain('SHA-256 does not match');
    expect(f.fetcher).toHaveBeenCalledTimes(1);
    await f.client.resume(file(11), id);
    expect(f.client.state.message).toContain('size does not match');
    expect(f.digest).toHaveBeenCalledTimes(1);
  });

  it('requires inspection after uncertain completion and recognizes a durable completed asset', async () => {
    const f = fixture();
    f.fetcher.mockResolvedValueOnce(reply(session({ receivedBytes: 10 })));
    await f.client.inspect(id);
    f.fetcher.mockResolvedValueOnce(reply(session({ receivedBytes: 10 }))).mockRejectedValueOnce(new Error('lost acknowledgement'));
    await f.client.finish();
    expect(f.client.state.needsInspection).toBe(true);
    await f.client.finish();
    expect(f.fetcher).toHaveBeenCalledTimes(3);
    f.fetcher.mockResolvedValueOnce(reply(session({ receivedBytes: 10, status: 'completed', assetId: 47 })));
    await f.client.inspect(id);
    expect(f.client.state.message).toContain('media #47');
    expect(f.client.state.message).toContain('publication have not been requested');
  });

  it('ignores a late receipt after pause and recovers by inspecting the saved session', async () => {
    const f = fixture();
    let resolve!: (value: Response) => void;
    f.fetcher.mockResolvedValueOnce(reply(session())).mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    const start = f.client.start(file(), 1, 'master_wav');
    await vi.waitFor(() => expect(f.fetcher).toHaveBeenCalledTimes(2));
    f.client.pause();
    resolve(reply(session({ receivedBytes: 10 })));
    await start;
    expect(f.client.state.session?.receivedBytes).toBe(0);
    expect(f.client.state.needsInspection).toBe(true);
    f.fetcher.mockResolvedValueOnce(reply(session({ receivedBytes: 10 })));
    await f.client.inspect(id);
    expect(f.client.state.session?.receivedBytes).toBe(10);
  });

  it('stops after access withdrawal without retrying or falsely reporting an uploaded asset', async () => {
    const f = fixture(); f.fetcher.mockResolvedValueOnce(reply(session())).mockResolvedValueOnce(new Response('private debug text', { status: 403 }));
    await f.client.start(file(), 1, 'master_wav');
    expect(f.fetcher).toHaveBeenCalledTimes(2);
    expect(f.client.state.message).toContain('sign in');
    expect(f.client.state.message).not.toContain('private debug');
    expect(f.client.state.session?.status).toBe('uploading');
  });

  it('returns an expired matching start without sending bytes or silently resetting the session', async () => {
    const f = fixture(); f.fetcher.mockResolvedValueOnce(reply(session({ status: 'expired' })));
    await f.client.start(file(), 1, 'master_wav');
    expect(f.fetcher).toHaveBeenCalledTimes(1);
    expect(f.client.state.message).toContain('Cancel it');
    f.fetcher.mockResolvedValueOnce(reply(session({ status: 'cancelled' })));
    await f.client.cancel();
    expect(f.fetcher.mock.calls[1][0]).toBe('/admin/resumable-uploads/' + id + '/cancel');
    expect(f.client.state.session?.status).toBe('cancelled');
  });

  it('rejects a mismatched server upload identity without replacing current state', async () => {
    const f = fixture(); f.fetcher.mockResolvedValueOnce(reply(session({ id: '22222222-2222-4222-8222-222222222222' })));
    await f.client.inspect(id);
    expect(f.client.state.session).toBeNull();
    expect(f.client.state.needsInspection).toBe(true);
    expect(f.remember).not.toHaveBeenCalled();
  });

  it('retries terminal transport cleanup without starting or sending another source', async () => {
    const f = fixture();
    f.fetcher.mockResolvedValueOnce(reply(session({ status: 'completed', receivedBytes: 10, assetId: 47, cleanupPending: true })));
    await f.client.inspect(id);
    expect(f.client.state.message).toContain('cleanup is still pending');
    f.fetcher.mockResolvedValueOnce(reply(session({ status: 'completed', receivedBytes: 10, assetId: 47, cleanupPending: true })))
      .mockResolvedValueOnce(reply(session({ status: 'completed', receivedBytes: 10, assetId: 47 })));
    await f.client.finish();
    expect(f.fetcher.mock.calls.map(call => call[0])).toEqual([
      '/admin/resumable-uploads/' + id, '/admin/resumable-uploads/' + id, '/admin/resumable-uploads/' + id + '/complete',
    ]);
    expect(f.digest).not.toHaveBeenCalled();
    expect(f.client.state.session?.cleanupPending).toBe(false);
  });

  it('requires inspection of an edited upload ID before finish or cancel can act', async () => {
    const f = fixture();
    f.fetcher.mockResolvedValueOnce(reply(session({ receivedBytes: 10 })));
    await f.client.inspect(id);
    f.client.changeId('22222222-2222-4222-8222-222222222222');
    expect(f.client.state.session).toBeNull();
    expect(f.client.state.needsInspection).toBe(true);
    await f.client.finish();
    await f.client.cancel();
    expect(f.fetcher).toHaveBeenCalledTimes(1);
  });
});
