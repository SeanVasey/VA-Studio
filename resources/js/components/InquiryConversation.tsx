import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import '../../css/contact-inquiry.css';

export const isInquiryReceipt = (value: string) => /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value) && value.length === 36;
type Message = { id: number; sender: 'you' | 'staff'; message: string; createdAt: string };
type Conversation = { receipt: string; state: 'new' | 'read' | 'archived'; subject: string; original: { message: string; createdAt: string }; messages: Message[]; canReply: boolean };
type Attempt = { body: string; uncertain: boolean };
class ConversationError extends Error {}
const plain = (value: unknown, max: number): value is string => typeof value === 'string' && value.trim().length > 0 && [...value].length <= max;
const date = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d\d-\d\dT/.test(value) && Number.isFinite(Date.parse(value));
function projection(value: unknown, receipt: string): Conversation {
  const c = value as Conversation;
  if (!c || c.receipt !== receipt || !['new', 'read', 'archived'].includes(c.state) || !plain(c.subject, 160)
    || !c.original || !plain(c.original.message, 8000) || !date(c.original.createdAt)
    || !Array.isArray(c.messages) || c.messages.length > 100 || typeof c.canReply !== 'boolean'
    || ((c.state === 'archived' || c.messages.length === 100) && c.canReply)) throw new ConversationError('This conversation could not be verified. Refresh it before continuing.');
  let previous = 0;
  for (const m of c.messages) {
    if (!m || !Number.isSafeInteger(m.id) || m.id <= previous || !['you', 'staff'].includes(m.sender)
      || !plain(m.message, 4000) || !date(m.createdAt)) throw new ConversationError('This conversation could not be verified. Refresh it before continuing.');
    previous = m.id;
  }
  return c;
}
function csrfHeaders(): Record<string, string> {
  const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }; } catch { /* Use the page token. */ } }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}
const uncertain = 'We could not confirm whether your follow-up was saved. Its original text is kept here. Retry the same follow-up to avoid sending it twice.';
const readable = (at: string) => new Date(at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** Reopening never creates a new inquiry or changes the server's intake availability. */
export function InquiryConversationEntry() {
  const prefix = useId();
  const [value, setValue] = useState('');
  const [opened, setOpened] = useState<string | null>(null);
  const [error, setError] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  if (opened) return <InquiryConversation key={opened} receipt={opened} onClose={() => {
    setOpened(null); window.requestAnimationFrame(() => input.current?.focus());
  }} />;
  return <section className="contact-inquiry" aria-labelledby={`${prefix}-title`}>
    <h2 id={`${prefix}-title`}>Read an existing inquiry</h2>
    <p className="contact-inquiry-privacy" id={`${prefix}-help`}>Enter your saved receipt using the browser session that sent the inquiry. A receipt alone cannot recover access in another session. Replies stay here; no email is sent.</p>
    <form noValidate onSubmit={event => {
      event.preventDefault();
      const receipt = value.trim().toLowerCase();
      if (!isInquiryReceipt(receipt)) { setError(true); input.current?.focus(); return; }
      setError(false); setOpened(receipt);
    }}>
      <div className="contact-inquiry-field"><label htmlFor={`${prefix}-receipt`}>Inquiry receipt</label>
        <input id={`${prefix}-receipt`} ref={input} type="text" value={value} maxLength={36} autoComplete="off" autoCapitalize="none" spellCheck={false}
          aria-invalid={error || undefined} aria-describedby={`${prefix}-help${error ? ` ${prefix}-error` : ''}`} onChange={event => setValue(event.target.value)} />
      </div>
      {error && <p className="contact-inquiry-field-error" id={`${prefix}-error`} role="alert">Enter the complete inquiry receipt.</p>}
      <div className="contact-inquiry-actions"><button className="button button-outline" type="submit">Open conversation</button></div>
    </form>
  </section>;
}

/** A receipt is a locator. The server still requires the original inquiry's current browser session. */
export function InquiryConversation({ receipt, onClose }: { receipt: string; onClose: () => void }) {
  const prefix = useId();
  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState({ error: false, message: 'Loading private conversation…' });
  const [sessionExpired, setSessionExpired] = useState(false);
  const attempt = useRef<Attempt | null>(null);
  const pending = useRef(false);
  const generation = useRef(0);
  const abort = useRef<AbortController | null>(null);
  const summary = useRef<HTMLDivElement>(null);
  const replyInput = useRef<HTMLTextAreaElement>(null);
  const endpoint = '/contact/inquiries/' + receipt + '/conversation';
  const locked = attempt.current !== null;

  async function read(signal: AbortSignal) {
    if (!isInquiryReceipt(receipt)) throw new ConversationError('Enter the complete inquiry receipt from your original browser session.');
    const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' }, signal });
    if (!response.ok || response.redirected) {
      if ([403, 404].includes(response.status)) throw new ConversationError('This conversation is unavailable in this browser session. A receipt alone does not grant access.');
      if (response.status === 429) throw new ConversationError('Please wait before refreshing this conversation again.');
      throw new ConversationError('The conversation could not be loaded. Refresh it to try again.');
    }
    return projection(await response.json(), receipt);
  }

  async function refresh() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setConversation(null);
    const current = ++generation.current; const controller = new AbortController(); abort.current = controller;
    const timeout = window.setTimeout(() => controller.abort(), 20_000);
    setNotice({ error: false, message: 'Loading private conversation…' });
    try {
      const result = await read(controller.signal);
      if (current !== generation.current) return;
      setConversation(result); setSessionExpired(false);
      setNotice({ error: false, message: attempt.current ? 'Conversation refreshed. Your unconfirmed follow-up is kept unchanged for the same-request retry.' : 'Conversation refreshed. Replies appear here; no email is sent.' });
    } catch (error) {
      if (current === generation.current) setNotice({ error: true, message: error instanceof ConversationError ? error.message : 'The conversation could not be loaded. Refresh it to try again.' });
    } finally {
      window.clearTimeout(timeout);
      if (current === generation.current) { pending.current = false; setBusy(false); abort.current = null; }
    }
  }

  useEffect(() => {
    void refresh();
    return () => { generation.current++; pending.current = false; abort.current?.abort(); };
    // The receipt is fixed for this mounted view; the parent keys each opened conversation.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [receipt]);
  useEffect(() => { if (!busy) summary.current?.focus(); }, [busy, notice]);

  async function send(event: FormEvent) {
    event.preventDefault();
    if (pending.current || (!attempt.current && !conversation?.canReply)) return;
    if (!attempt.current) {
      if (!plain(draft, 4000) || /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u.test(draft)) {
        setNotice({ error: true, message: 'Enter a plain-text follow-up of 4,000 characters or fewer.' }); return;
      }
      let requestKey: string;
      try { requestKey = crypto.randomUUID(); } catch { setNotice({ error: true, message: 'This browser could not prepare a private follow-up. Keep a copy of your draft.' }); return; }
      if (!isInquiryReceipt(requestKey)) { setNotice({ error: true, message: 'This browser could not prepare a private follow-up. Keep a copy of your draft.' }); return; }
      const body = JSON.stringify({ message: draft, requestKey });
      if (new TextEncoder().encode(body).byteLength > 16384) { setNotice({ error: true, message: 'This follow-up is too large to send. Shorten it and try again.' }); return; }
      attempt.current = { body, uncertain: false };
    }
    const original = attempt.current;
    pending.current = true; setBusy(true); setSessionExpired(false); setNotice({ error: false, message: 'Saving your follow-up…' });
    const current = ++generation.current; const controller = new AbortController(); abort.current = controller;
    const timeout = window.setTimeout(() => controller.abort(), 20_000);
    let acknowledged = false;
    try {
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() }, body: original.body });
      if (current !== generation.current) return;
      let data: Record<string, unknown> = {};
      try { data = await response.json(); } catch { /* An unreadable acknowledgement remains uncertain. */ }
      if (current !== generation.current) return;
      if (!response.redirected && [200, 201].includes(response.status) && data && data.state === 'saved' && Number.isSafeInteger(data.messageId) && (data.messageId as number) > 0) {
        acknowledged = true; attempt.current = null; setDraft(''); setConversation(null);
        const result = await read(controller.signal);
        if (current !== generation.current) return;
        setConversation(result); setNotice({ error: false, message: 'Your follow-up was saved. Replies appear here; no email is sent.' }); return;
      }
      if (!original.uncertain && !response.redirected && ((response.status === 422 && data?.code === 'INQUIRY_VALIDATION_FAILED') || response.status === 413)) {
        attempt.current = null; setNotice({ error: true, message: 'Your follow-up was not saved. Check its text and use 4,000 characters or fewer.' }); return;
      }
      original.uncertain = true;
      if (response.status === 419) {
        setSessionExpired(true); setNotice({ error: true, message: 'Your session expired. Keep this page open, renew contact in a new tab, then retry the same follow-up here.' }); return;
      }
      if ([403, 404].includes(response.status)) setConversation(null);
      setNotice({ error: true, message: response.status === 409 ? uncertain + ' Refresh the conversation to check whether replies are still available.' : response.status === 429 ? uncertain + ' Please wait before retrying.' : uncertain });
    } catch {
      if (current === generation.current) {
        if (!acknowledged) original.uncertain = true;
        setNotice({ error: true, message: acknowledged ? 'Your follow-up was saved, but the conversation could not be refreshed. Refresh it to read the current state.' : uncertain });
      }
    } finally {
      window.clearTimeout(timeout);
      if (current === generation.current) { pending.current = false; setBusy(false); abort.current = null; }
    }
  }

  return <section className="contact-inquiry" aria-labelledby={`${prefix}-title`}>
    <h2 id={`${prefix}-title`}>Private inquiry conversation</h2>
    <p className="contact-inquiry-privacy">Read replies and follow up here using the browser session that sent the inquiry. Messages are not emailed, and a receipt cannot recover access in a different session.</p>
    <p className="contact-inquiry-receipt">Receipt <code>{receipt}</code></p>
    <div className="contact-inquiry-summary" role={notice.error ? 'alert' : 'status'} tabIndex={-1} ref={summary}><p>{notice.message}</p>
      {sessionExpired && <a href="/contact" target="_blank" rel="noopener noreferrer">Open contact in a new tab</a>}
    </div>
    <div className="contact-inquiry-actions"><button className="button button-outline" type="button" disabled={busy} onClick={() => void refresh()}>Refresh conversation</button></div>
    {conversation && <div>
      <h3>{conversation.subject}</h3>
      <p>Inquiry {conversation.state === 'new' ? 'saved' : conversation.state === 'read' ? 'read by staff' : 'archived'}</p>
      <ol aria-label="Conversation messages" style={{ paddingLeft: '1.5rem', overflowWrap: 'anywhere' }}>
        <li><h4>Your original inquiry</h4><time dateTime={conversation.original.createdAt}>{readable(conversation.original.createdAt)}</time><p style={{ whiteSpace: 'pre-wrap' }}>{conversation.original.message}</p></li>
        {conversation.messages.map(message => <li key={message.id}><h4>{message.sender === 'staff' ? 'VASEY.AUDIO reply' : 'Your follow-up'}</h4>
          <time dateTime={message.createdAt}>{readable(message.createdAt)}</time><p style={{ whiteSpace: 'pre-wrap' }}>{message.message}</p></li>)}
      </ol>
      {!conversation.canReply && <p className="contact-inquiry-note">{conversation.state === 'archived' ? 'This inquiry is archived. You can read its retained conversation, but new follow-ups are closed.' : 'New follow-ups are currently unavailable. You can still read this conversation.'}</p>}
    </div>}
    {(conversation?.canReply || locked) && <form onSubmit={send} noValidate>
      <div className="contact-inquiry-field"><label htmlFor={`${prefix}-reply`}>Follow-up message</label>
        <textarea id={`${prefix}-reply`} ref={replyInput} rows={5} maxLength={8000} value={draft} readOnly={locked} disabled={busy}
          aria-describedby={`${prefix}-draft-note`} onChange={event => { if (!attempt.current && !pending.current) setDraft(event.target.value); }} />
      </div>
      <p className="contact-inquiry-note" id={`${prefix}-draft-note`}>{[...draft].length.toLocaleString('en-US')} / 4,000 characters. This draft stays only on this page.</p>
      {locked && <p className="contact-inquiry-note">The original message is read-only while its result is unconfirmed. Copy any text you need before leaving this view.</p>}
      <div className="contact-inquiry-actions"><button className="button" type="submit" disabled={busy}>{busy ? 'Please wait…' : locked ? 'Retry same follow-up' : 'Send follow-up'}</button></div>
    </form>}
    <button className="button button-outline" type="button" disabled={busy} onClick={onClose}>Back to contact</button>
  </section>;
}
