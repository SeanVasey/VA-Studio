import { useEffect, useRef, useState, type FormEvent } from 'react';

type Attachment = {
  attachmentId: string; sourceVersion: number; state: 'receiving' | 'quarantined' | 'scanning' | 'ready' | 'failed' | 'deleted' | 'expired';
  receivedBytes: number; totalBytes: number; file: { name: string; mime: string; sizeBytes: number; sha256: string };
  expiresAt: string; attempt: number; canRetry: boolean; canDownload: boolean;
};
export type SupportAttachmentList = {
  sourceVersion: number; canIntake: boolean; attachments: Attachment[];
  policy: { maxBytes: number; maxFiles: number; retentionSeconds: number; provenance: string };
};
type Props = { sourceId: string; sourceKind: 'inquiry' | 'project'; audience?: 'visitor' | 'customer' | 'operator'; renderScope: string };
const uuid = (value: unknown): value is string => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const exact = (value: Record<string, unknown>, keys: string[]) => Object.keys(value).sort().join('|') === [...keys].sort().join('|');
const integer = (value: unknown, max: number) => typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= max;
export function validSupportAttachment(value: unknown): value is Attachment {
  if (!record(value) || !exact(value, ['attachmentId', 'sourceVersion', 'state', 'receivedBytes', 'totalBytes', 'file', 'expiresAt', 'attempt', 'canRetry', 'canDownload'])
    || !uuid(value.attachmentId) || !integer(value.sourceVersion, 2147483647) || !integer(value.attempt, 3)
    || !['receiving', 'quarantined', 'scanning', 'ready', 'failed', 'deleted', 'expired'].includes(String(value.state))
    || typeof value.canRetry !== 'boolean' || typeof value.canDownload !== 'boolean'
    || typeof value.expiresAt !== 'string' || value.expiresAt.length > 40 || !Number.isFinite(Date.parse(value.expiresAt))
    || !record(value.file) || !exact(value.file, ['name', 'mime', 'sizeBytes', 'sha256'])) return false;
  const file = value.file;
  return typeof file.name === 'string' && new TextEncoder().encode(file.name).length > 0 && new TextEncoder().encode(file.name).length <= 160
    && !/[\x00-\x1f\x7f/\\]/.test(file.name) && typeof file.mime === 'string' && ['text/plain', 'application/pdf', 'image/png', 'image/jpeg'].includes(file.mime)
    && integer(file.sizeBytes, 5242880) && Number(file.sizeBytes) > 0 && value.totalBytes === file.sizeBytes
    && value.receivedBytes === (value.state === 'receiving' ? 0 : file.sizeBytes)
    && typeof file.sha256 === 'string' && /^[a-f0-9]{64}$/.test(file.sha256)
    && (!value.canDownload || value.state === 'ready')
    && (!value.canRetry || (Number(value.attempt) < 3 && ['quarantined', 'scanning', 'failed'].includes(String(value.state))));
}
export function validSupportAttachmentList(value: unknown): value is SupportAttachmentList {
  if (!record(value) || !exact(value, ['sourceVersion', 'canIntake', 'attachments', 'policy']) || !integer(value.sourceVersion, 2147483647)
    || typeof value.canIntake !== 'boolean' || !Array.isArray(value.attachments) || value.attachments.length > 10 || !value.attachments.every(validSupportAttachment)
    || new Set(value.attachments.map(item => item.attachmentId)).size !== value.attachments.length || !record(value.policy)
    || !exact(value.policy, ['maxBytes', 'maxFiles', 'retentionSeconds', 'provenance'])) return false;
  return integer(value.policy.maxBytes, 5242880) && Number(value.policy.maxBytes) > 0 && integer(value.policy.maxFiles, 10) && Number(value.policy.maxFiles) > 0
    && value.attachments.length <= Number(value.policy.maxFiles) && integer(value.policy.retentionSeconds, 86400) && Number(value.policy.retentionSeconds) > 0
    && typeof value.policy.provenance === 'string' && /^[a-zA-Z0-9_]{1,80}$/.test(value.policy.provenance);
}
function csrf(): Record<string, string> {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }; } catch { /* Use the current document token below. */ } }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}

/** Root mounts with a server page scope. A changed identity remounts before any future private read. */
export function SupportAttachments(props: Props) {
  if (!uuid(props.sourceId) || !props.renderScope || props.renderScope.length > 160
    || !['inquiry', 'project'].includes(props.sourceKind) || (props.audience && !['visitor', 'customer', 'operator'].includes(props.audience))) return null;
  return <AttachmentPanel key={`${props.renderScope}|${props.audience ?? 'visitor'}|${props.sourceKind}|${props.sourceId}`} {...props} />;
}
function AttachmentPanel({ sourceId, sourceKind, audience = 'visitor' }: Props) {
  const [library, setLibrary] = useState<SupportAttachmentList | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [denied, setDenied] = useState(false);
  const [uncertainUpload, setUncertainUpload] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const draft = useRef<{ file: File; key: string } | null>(null);
  const pending = useRef<AbortController | null>(null);
  const active = useRef(true);
  const base = `/private-support/${audience === 'operator' ? 'operator/' : ''}${sourceKind === 'inquiry' ? 'inquiries' : 'projects'}/${sourceId}/attachments`;
  function clearInput() { draft.current = null; setFile(null); setUncertainUpload(false); if (fileInput.current) fileInput.current.value = ''; }
  function erase() { pending.current?.abort(); pending.current = null; clearInput(); setLibrary(null); }
  function closeAccess() { active.current = false; erase(); setDenied(true); setBusy(false); setNotice('These private attachments are unavailable. Open the page again after restoring access.'); }
  useEffect(() => {
    const hide = () => closeAccess(); window.addEventListener('pagehide', hide);
    return () => { active.current = false; pending.current?.abort(); draft.current = null; if (fileInput.current) fileInput.current.value = ''; window.removeEventListener('pagehide', hide); };
  }, []);
  async function request(path: string, options: RequestInit = {}) {
    if (!active.current) throw new Error('closed');
    const controller = new AbortController(); pending.current = controller;
    const result = await fetch(path, { ...options, credentials: 'same-origin', cache: 'no-store', redirect: 'error', referrerPolicy: 'no-referrer', signal: controller.signal,
      headers: { Accept: 'application/json', ...options.headers } });
    if (!active.current || controller.signal.aborted) throw new Error('closed');
    if ([401, 403, 404, 419].includes(result.status)) { closeAccess(); throw new Error('closed'); }
    if (!result.ok) throw new Error(result.status === 409 ? 'conflict' : 'unknown');
    return result;
  }
  async function load() {
    if (busy || !active.current) return;
    // Erase private input and old data BEFORE this account/source read. No automatic revival after denial.
    erase(); setBusy(true); setNotice('Loading private attachments…');
    try {
      const value: unknown = await (await request(base)).json();
      if (!validSupportAttachmentList(value)) throw new Error('unknown');
      if (active.current) { setLibrary(value); setNotice(''); }
    } catch { if (active.current) setNotice('Attachments could not be confirmed. Try loading them again.'); }
    finally { if (active.current) setBusy(false); }
  }
  async function upload(event: FormEvent) {
    event.preventDefault(); if (busy || !active.current || !library?.canIntake || !file) return;
    if (!draft.current || draft.current.file !== file) draft.current = { file, key: crypto.randomUUID() };
    const attempt = draft.current; setBusy(true); setNotice('Sending the file. Waiting for server confirmation…');
    try {
      const encodedName = btoa(String.fromCharCode(...new TextEncoder().encode(file.name)));
      const response = await request(`${base}/upload`, { method: 'POST', headers: { 'Content-Type': 'application/octet-stream', 'X-Attachment-Name': encodedName,
        'X-Request-Key': attempt.key, 'X-Source-Version': String(library.sourceVersion), ...csrf() }, body: file });
      const body: unknown = await response.json();
      if (!record(body) || !exact(body, ['replayed', 'attachment']) || typeof body.replayed !== 'boolean' || !validSupportAttachment(body.attachment)) throw new Error('unknown');
      if (active.current) {
        const attachment = body.attachment;
        setLibrary(current => current && ({ ...current, canIntake: current.attachments.some(item => item.attachmentId === attachment.attachmentId) ? current.canIntake : current.attachments.length + 1 < current.policy.maxFiles,
          attachments: [...current.attachments.filter(item => item.attachmentId !== attachment.attachmentId), attachment] }));
        clearInput(); setNotice(attachment.state === 'quarantined' ? 'File received and quarantined. Scan it before downloading.' : `The original request was already confirmed. Current state: ${attachment.state}.`);
      }
    } catch (error) {
      if (active.current) { setUncertainUpload(true); setNotice(error instanceof Error && error.message === 'conflict' ? 'Reload the list before continuing. This request was not confirmed.' : 'Upload not confirmed. Retry the same file and request, or reload the list to check its status.'); }
    } finally { if (active.current) setBusy(false); }
  }
  async function command(attachment: Attachment, action: 'process' | 'download' | 'delete') {
    if (busy || !active.current || !library) return; clearInput(); setBusy(true); setNotice(action === 'process' ? 'Scanning the quarantined file…' : 'Confirming private access…');
    try {
      const response = await request(`${base}/${attachment.attachmentId}/${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...csrf() },
        body: JSON.stringify(action === 'process' ? { sourceVersion: library.sourceVersion, attempt: attachment.attempt } : {}) });
      if (action === 'download') {
        const blob = await response.blob();
        if (!active.current || blob.size !== attachment.file.sizeBytes || blob.size > 5242880) throw new Error('unknown');
        const url = URL.createObjectURL(blob); const link = document.createElement('a');
        try { link.href = url; link.download = attachment.file.name; link.rel = 'noreferrer'; link.click(); } finally { URL.revokeObjectURL(url); }
        setNotice('The original file download was prepared.');
      } else {
        const body: unknown = await response.json();
        if (!active.current) return;
        if (!record(body) || !exact(body, ['attachment']) || !record(body.attachment)) throw new Error('unknown');
        const { cleanup, ...candidate } = body.attachment;
        if (!validSupportAttachment(candidate) || candidate.attachmentId !== attachment.attachmentId || (action === 'delete' && !['complete', 'pending'].includes(String(cleanup)))) throw new Error('unknown');
        setLibrary(current => current && ({ ...current, attachments: current.attachments.map(item => item.attachmentId === candidate.attachmentId ? candidate : item) }));
        setNotice(action === 'delete' ? cleanup === 'pending' ? 'Access is closed. File cleanup is pending; explicitly retry deletion later.' : 'Stored file removed. Its record is retained.'
          : candidate.state === 'ready' ? 'Scan completed. The original is ready to download.' : 'Scan failed or was unavailable. The file remains quarantined.');
      }
    } catch { if (active.current) { setLibrary(null); setNotice('This action could not be confirmed. Reload the list before an explicit retry.'); } }
    finally { if (active.current) setBusy(false); }
  }
  return <section aria-label="Private attachments">
    <h3>Private attachments</h3>
    {notice && <p role="status">{notice}</p>}
    {!denied && <button type="button" disabled={busy} onClick={load}>{library ? 'Refresh private attachments' : 'Open private attachments'}</button>}
    {library && <>
      {library.policy.provenance === 'synthetic_technical_policy' && <p>Local rehearsal limits: 5 MiB per file, 10 files per source, access expires after 24 hours. These are technical fixture limits.</p>}
      <p>Originals are private. Downloads require a clean scan and current access. Deleting a file retains its record.</p>
      <ul>{library.attachments.map(attachment => <li key={attachment.attachmentId}>
        <strong>{attachment.file.name}</strong> — {attachment.state}. {attachment.receivedBytes} of {attachment.totalBytes} bytes confirmed. Access until {attachment.expiresAt}.
        {attachment.canRetry && <button type="button" disabled={busy} onClick={() => command(attachment, 'process')}>{attachment.attempt === 0 ? 'Scan' : 'Retry scan'} {attachment.file.name}</button>}
        {attachment.canDownload && <button type="button" disabled={busy} onClick={() => command(attachment, 'download')}>Download {attachment.file.name}</button>}
        <button type="button" disabled={busy} onClick={() => command(attachment, 'delete')}>{['deleted', 'expired'].includes(attachment.state) ? 'Retry cleanup' : 'Delete'} {attachment.file.name}</button>
      </li>)}</ul>
      {library.canIntake && <form onSubmit={upload}>
        <label>Choose private file <input ref={fileInput} type="file" accept=".txt,.pdf,.png,.jpg,.jpeg" disabled={busy} onChange={event => {
          draft.current = null; setUncertainUpload(false); const selected = event.target.files?.[0] ?? null;
          if (selected && (selected.size < 1 || selected.size > library.policy.maxBytes || !/\.(txt|pdf|png|jpe?g)$/i.test(selected.name))) { event.target.value = ''; setFile(null); setNotice('Choose an allowed file within the displayed size limit.'); }
          else setFile(selected);
        }} /></label>
        <button type="submit" disabled={busy || !file}>{uncertainUpload ? 'Retry same upload' : 'Send private file'}</button>
      </form>}
    </>}
  </section>;
}
