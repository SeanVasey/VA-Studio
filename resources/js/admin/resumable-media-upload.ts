export interface UploadSession {
  id: string; trackId: number; role: 'artwork' | 'master_wav' | 'stems_zip'; originalName: string;
  sizeBytes: number; sha256: string; receivedBytes: number; chunkBytes: number;
  status: 'uploading' | 'completed' | 'cancelled' | 'expired'; expiresAt: string; assetId: number | null; cleanupPending: boolean;
}
export interface UploadState { session: UploadSession | null; busy: boolean; needsInspection: boolean; message: string }
interface Options {
  baseUrl: string; csrfToken: string; onChange: (state: UploadState) => void;
  fetcher?: typeof fetch; digest?: (file: File) => Promise<string>; remember?: (id: string) => void;
}
class UploadMessage extends Error {}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const CHUNK_BYTES = 8 * 1024 * 1024;

function sessionFrom(value: unknown, expectedId?: string): UploadSession {
  const s = value as UploadSession;
  if (!s || !UUID.test(s.id) || (expectedId && s.id !== expectedId)
    || !Number.isSafeInteger(s.trackId) || s.trackId < 1 || !['artwork', 'master_wav', 'stems_zip'].includes(s.role)
    || typeof s.originalName !== 'string' || s.originalName.length > 255
    || !Number.isSafeInteger(s.sizeBytes) || s.sizeBytes < 1 || s.sizeBytes > 200 * 1024 * 1024
    || !/^[a-f0-9]{64}$/.test(s.sha256) || !Number.isSafeInteger(s.receivedBytes) || s.receivedBytes < 0 || s.receivedBytes > s.sizeBytes
    || typeof s.cleanupPending !== 'boolean' || s.chunkBytes !== CHUNK_BYTES || !['uploading', 'completed', 'cancelled', 'expired'].includes(s.status)
    || typeof s.expiresAt !== 'string' || !Number.isFinite(Date.parse(s.expiresAt))
    || !(s.assetId === null || (Number.isSafeInteger(s.assetId) && s.assetId > 0))
    || (s.status === 'completed' && (s.assetId === null || s.receivedBytes !== s.sizeBytes))) {
    throw new UploadMessage('Upload status could not be verified. Inspect the upload before continuing.');
  }
  return s;
}

async function fileDigest(file: File): Promise<string> {
  if (!globalThis.crypto?.subtle) throw new UploadMessage('Secure file verification is unavailable. Use HTTPS or localhost, or the existing whole-file upload.');
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
  return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
}

function description(session: UploadSession): string {
  if (session.status === 'completed' && session.cleanupPending) return `Upload saved as media #${session.assetId}. Transport cleanup is still pending; choose Finish upload to retry cleanup. No processing or publication has been requested.`;
  if (session.status === 'cancelled' && session.cleanupPending) return 'Upload cancelled. Transport cleanup is still pending; choose Cancel upload again to retry cleanup.';
  if (session.status === 'completed') return `Upload saved as media #${session.assetId}. Processing and publication have not been requested. Return to Media assets to process and review it.`;
  if (session.status === 'cancelled') return 'Upload cancelled. Choose a file and start a new upload when ready.';
  if (session.status === 'expired') return 'This upload expired. Cancel it before starting the same file again.';
  if (session.receivedBytes === session.sizeBytes) return 'All bytes received. Choose Finish upload to verify and save the private media asset.';
  return `${session.receivedBytes.toLocaleString()} of ${session.sizeBytes.toLocaleString()} bytes received. Reselect the same file to continue.`;
}

/** Server receipts, never the browser's attempted offset, decide how much has been retained. */
export class ResumableUploadClient {
  state: UploadState = { session: null, busy: false, needsInspection: false, message: 'Choose a track, role and file to start, or inspect an existing upload.' };
  private generation = 0;
  private controller: AbortController | null = null;
  private readonly fetcher: typeof fetch;
  private readonly digest: (file: File) => Promise<string>;

  constructor(private readonly options: Options) {
    // Native browser fetch requires its global receiver when called through this client.
    this.fetcher = options.fetcher ?? globalThis.fetch.bind(globalThis);
    this.digest = options.digest ?? fileDigest;
  }
  private update(changes: Partial<UploadState>) { this.state = { ...this.state, ...changes }; this.options.onChange(this.state); }
  private accept(session: UploadSession) { this.options.remember?.(session.id); this.update({ session, needsInspection: false, message: description(session) }); }
  private async request(path: string, signal: AbortSignal, body?: FormData | object): Promise<UploadSession> {
    if (!this.options.csrfToken) throw new UploadMessage('Your session cannot be verified. Reload the page before continuing.');
    const headers: Record<string, string> = { Accept: 'application/json', 'X-CSRF-TOKEN': this.options.csrfToken };
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await this.fetcher(this.options.baseUrl + path, {
      method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', signal, headers,
      ...(body === undefined ? {} : { body: body instanceof FormData ? body : JSON.stringify(body) }),
    });
    if (!response.ok || response.redirected) {
      if ([401, 403, 419].includes(response.status) || response.redirected) throw new UploadMessage('Your upload access or session needs attention. Reload and sign in, then inspect the upload.');
      if (response.status === 429) throw new UploadMessage('Too many upload requests. Wait, then inspect the upload before continuing.');
      if (response.status === 413) throw new UploadMessage('The server rejected the chunk size. Check its upload request limit or use the whole-file upload.');
      throw new UploadMessage('The upload request was not confirmed. Inspect its current state before retrying.');
    }
    const result = await response.json();
    const expectedId = path.split('/')[1];
    return sessionFrom(result?.session, expectedId || undefined);
  }
  private async run(operation: (signal: AbortSignal, current: () => boolean) => Promise<void>) {
    if (this.state.busy) return;
    const generation = ++this.generation;
    this.controller = new AbortController();
    const current = () => generation === this.generation;
    this.update({ busy: true });
    try { await operation(this.controller.signal, current); }
    catch (error) {
      if (current()) this.update({ needsInspection: true, message: error instanceof UploadMessage
        ? error.message : 'The upload result could not be confirmed. Inspect its current state before retrying. If no upload ID was received, start again with the same file, track and role.' });
    } finally { if (current()) { this.controller = null; this.update({ busy: false }); } }
  }
  pause() {
    if (!this.state.busy) return;
    this.generation++;
    this.controller?.abort(); this.controller = null;
    this.update({ busy: false, needsInspection: true, message: 'Paused. The last request may have completed. Inspect the upload or continue with the same file to recover its current offset.' });
  }
  changeId(id: string) {
    if (!this.state.busy && this.state.session?.id !== id) {
      this.update({ session: null, needsInspection: true, message: 'Inspect this upload ID before continuing, finishing or cancelling it.' });
    }
  }
  async start(file: File, trackId: number, role: UploadSession['role']) {
    await this.run(async (signal, current) => {
      if (!Number.isSafeInteger(trackId) || trackId < 1 || !['artwork', 'master_wav', 'stems_zip'].includes(role)
        || file.size < 1 || file.size > (role === 'artwork' ? 20 : 200) * 1024 * 1024) {
        throw new UploadMessage('The file or selection is invalid. Artwork is limited to 20 MiB; WAV masters and stems ZIPs to 200 MiB.');
      }
      this.update({ message: 'Verifying the selected file before upload…' });
      const sha256 = await this.digest(file);
      if (!current()) return;
      const session = await this.request('', signal, { trackId, role, sizeBytes: file.size, sha256, originalName: file.name });
      if (!current()) return;
      if (session.trackId !== trackId || session.role !== role || session.sizeBytes !== file.size || session.sha256 !== sha256) {
        throw new UploadMessage('Upload status could not be verified. Inspect the upload before continuing.');
      }
      this.accept(session);
      if (session.status === 'uploading') await this.transfer(file, signal, current);
    });
  }
  async inspect(id: string) {
    await this.run(async (signal, current) => {
      if (!UUID.test(id)) throw new UploadMessage('The upload ID is invalid. Use the full ID returned when the upload started.');
      const session = await this.request('/' + id, signal);
      if (current()) this.accept(session);
    });
  }
  async resume(file: File, id: string) {
    await this.run(async (signal, current) => {
      if (!UUID.test(id)) throw new UploadMessage('The upload ID is invalid. Inspect the upload before continuing.');
      const session = await this.request('/' + id, signal);
      if (!current()) return;
      this.accept(session);
      if (session.status !== 'uploading') return;
      if (file.size !== session.sizeBytes) throw new UploadMessage('Reselect the same file. Its size does not match this upload.');
      this.update({ message: 'Verifying the selected file before resuming…' });
      const sha256 = await this.digest(file);
      if (!current()) return;
      if (sha256 !== session.sha256) throw new UploadMessage('Reselect the same file. Its SHA-256 does not match this upload.');
      await this.transfer(file, signal, current);
    });
  }
  private async transfer(file: File, signal: AbortSignal, current: () => boolean) {
    while (current() && this.state.session?.status === 'uploading' && this.state.session.receivedBytes < this.state.session.sizeBytes) {
      const session = this.state.session;
      const end = Math.min(session.receivedBytes + session.chunkBytes, session.sizeBytes);
      const form = new FormData();
      form.append('offset', String(session.receivedBytes)); form.append('chunk', file.slice(session.receivedBytes, end), 'chunk.bin');
      this.update({ message: `Uploading ${session.originalName}: ${session.receivedBytes.toLocaleString()} of ${session.sizeBytes.toLocaleString()} bytes confirmed…` });
      const receipt = await this.request('/' + session.id + '/chunks', signal, form);
      if (!current()) return;
      if (receipt.status !== 'uploading' || receipt.receivedBytes !== end || receipt.sha256 !== session.sha256 || receipt.sizeBytes !== session.sizeBytes) {
        throw new UploadMessage('Upload status changed. Inspect the upload before continuing.');
      }
      this.accept(receipt);
    }
  }
  async finish() {
    if (!this.state.session || this.state.needsInspection) return;
    const id = this.state.session.id;
    await this.run(async (signal, current) => {
      const checked = await this.request('/' + id, signal);
      if (!current()) return;
      this.accept(checked);
      if (checked.status === 'completed' && !checked.cleanupPending) return;
      if (!((checked.status === 'completed' && checked.cleanupPending) || (checked.status === 'uploading' && checked.receivedBytes === checked.sizeBytes))) throw new UploadMessage('The upload is not ready to finish. Inspect it and continue with the same file.');
      this.update({ message: 'Verifying and saving the private upload…' });
      const session = await this.request('/' + id + '/complete', signal, {});
      if (current()) this.accept(session);
    });
  }
  async cancel() {
    if (!this.state.session) return;
    const id = this.state.session.id;
    await this.run(async (signal, current) => {
      const session = await this.request('/' + id + '/cancel', signal, {});
      if (current()) this.accept(session);
    });
  }
}

function mountUpload(element: HTMLElement) {
  const file = element.querySelector<HTMLInputElement>('[data-upload-file]')!;
  const id = element.querySelector<HTMLInputElement>('[data-upload-id]')!;
  const status = element.querySelector<HTMLElement>('[data-upload-status]')!;
  const details = element.querySelector<HTMLElement>('[data-upload-details]')!;
  const progress = element.querySelector<HTMLProgressElement>('progress')!;
  const key = 'vasey-resumable-upload-' + element.dataset.operator;
  try { id.value = sessionStorage.getItem(key) ?? ''; } catch { /* Manual upload ID recovery remains available. */ }
  const action = (name: string) => element.querySelector<HTMLButtonElement>(`[data-upload-action="${name}"]`)!;
  const client = new ResumableUploadClient({
    baseUrl: element.dataset.baseUrl!, csrfToken: document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    remember: value => { id.value = value; try { sessionStorage.setItem(key, value); } catch { /* Keep the visible ID. */ } },
    onChange: state => {
      status.textContent = state.message;
      const session = state.session;
      details.textContent = session ? `${session.originalName} · Track #${session.trackId} · ${session.role} · ${session.status} · Expires ${session.expiresAt}` : '';
      progress.max = session?.sizeBytes ?? 1; progress.value = session?.receivedBytes ?? 0;
      file.disabled = state.busy; id.disabled = state.busy;
      for (const name of ['start', 'inspect', 'resume']) action(name).disabled = state.busy;
      action('pause').disabled = !state.busy;
      action('finish').disabled = state.busy || state.needsInspection || !session || !((session.status === 'completed' && session.cleanupPending) || (session.status === 'uploading' && session.receivedBytes === session.sizeBytes));
      action('cancel').disabled = state.busy || !session || session.status === 'completed' || (session.status === 'cancelled' && !session.cleanupPending);
    },
  });
  const selected = (): File | null => { if (file.files?.[0]) return file.files[0]; status.textContent = 'Choose the file from your device before starting or continuing.'; file.focus(); return null; };
  id.addEventListener('input', () => client.changeId(id.value.trim()));
  element.addEventListener('click', event => {
    const button = (event.target as Element).closest<HTMLButtonElement>('[data-upload-action]');
    if (!button || button.disabled) return;
    switch (button.dataset.uploadAction) {
      case 'inspect': void client.inspect(id.value.trim()); break;
      case 'resume': { const item = selected(); if (item) void client.resume(item, id.value.trim()); break; }
      case 'pause': client.pause(); break;
      case 'finish': void client.finish(); break;
      case 'cancel': void client.cancel(); break;
    }
  });
  window.addEventListener('resumable-upload-context', event => {
    const item = selected();
    if (item) { const context = (event as CustomEvent).detail; void client.start(item, Number(context.trackId), context.role); }
  });
}

if (typeof document !== 'undefined') document.querySelectorAll<HTMLElement>('[data-resumable-upload]').forEach(mountUpload);
