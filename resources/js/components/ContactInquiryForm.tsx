import { useEffect, useId, useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import '../../css/contact-inquiry.css';

type Fields = { name: string; email: string; subject: string; message: string; website: string };
type VisibleField = Exclude<keyof Fields, 'website'>;
type Errors = Partial<Record<VisibleField, string>>;
type Status = { kind: 'editing' | 'sending' | 'error'; message?: string; sessionExpired?: boolean } | { kind: 'saved'; receipt: string };
type Attempt = { body: string; privacyNotice: string; uncertain: boolean };

const empty: Fields = { name: '', email: '', subject: '', message: '', website: '' };
const limits = { name: 120, email: 254, subject: 160, message: 8000 };
const labels = { name: 'Name', email: 'Email', subject: 'Subject', message: 'Message' };
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const isUuid = (value: string) => value.length === 36 && uuid.test(value);
const isNoticeToken = (value: string) => value.length === 64 && /^[0-9a-f]{64}$/.test(value);
const uncertainMessage = 'We could not confirm whether your inquiry was saved. Your original message is kept here. Retry the same inquiry to avoid sending it twice.';

function csrfHeaders(): Record<string, string> {
  // The cookie may be renewed in another tab without losing this tab's pending payload.
  const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) {
    try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }; } catch { /* Fall back to this page's token. */ }
  }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}

/** Public contact only; the parent must gate this surface on the server's enabled projection. */
export function ContactInquiryForm({ privacyNotice, noticeToken }: { privacyNotice: string; noticeToken: string }) {
  const prefix = useId();
  const [fields, setFields] = useState<Fields>({ ...empty });
  const [errors, setErrors] = useState<Errors>({});
  const [status, setStatus] = useState<Status>({ kind: 'editing' });
  const [rejectedNoticeToken, setRejectedNoticeToken] = useState<string | null>(null);
  const attempt = useRef<Attempt | null>(null);
  const inFlight = useRef(false);
  const active = useRef(true);
  const controller = useRef<AbortController | null>(null);
  const summary = useRef<HTMLDivElement>(null);
  const nameInput = useRef<HTMLInputElement>(null);
  const emailInput = useRef<HTMLInputElement>(null);
  const sending = status.kind === 'sending';
  const locked = attempt.current !== null;
  const noticeRefreshRequired = rejectedNoticeToken !== null && rejectedNoticeToken === noticeToken;
  const displayedNotice = attempt.current?.privacyNotice ?? privacyNotice;

  useEffect(() => {
    active.current = true;
    return () => { active.current = false; controller.current?.abort(); };
  }, []);
  useEffect(() => {
    if (status.kind === 'error' || status.kind === 'saved') summary.current?.focus();
  }, [status]);
  useEffect(() => {
    if (rejectedNoticeToken !== null && isNoticeToken(noticeToken) && noticeToken !== rejectedNoticeToken) {
      setRejectedNoticeToken(null);
      if (!attempt.current && !inFlight.current) { setErrors({}); setStatus({ kind: 'editing' }); }
    }
  }, [noticeToken, rejectedNoticeToken]);

  function change(field: keyof Fields, value: string) {
    if (attempt.current || inFlight.current) return;
    setFields(current => ({ ...current, [field]: value }));
  }

  function validate(): Errors {
    const invalid: Errors = {};
    for (const field of Object.keys(limits) as VisibleField[]) {
      if (!fields[field].trim()) invalid[field] = `Enter your ${field === 'name' ? 'name' : field === 'email' ? 'email address' : field}.`;
      else if ([...fields[field]].length > limits[field]) invalid[field] = `${labels[field]} must be ${limits[field].toLocaleString('en-US')} characters or fewer.`;
    }
    if (!invalid.email && emailInput.current?.validity.typeMismatch) invalid.email = 'Enter a valid email address.';
    return invalid;
  }

  function uncertain(message = uncertainMessage, sessionExpired = false) {
    if (attempt.current) attempt.current.uncertain = true;
    setErrors({});
    setStatus({ kind: 'error', message, sessionExpired });
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (inFlight.current) return;
    if (!attempt.current) {
      if (noticeRefreshRequired || !isNoticeToken(noticeToken)) return;
      const invalid = validate();
      if (Object.keys(invalid).length) {
        setErrors(invalid); setStatus({ kind: 'error', message: 'Check the highlighted fields before sending.' }); return;
      }
      let requestKey: string;
      try { requestKey = crypto.randomUUID(); } catch {
        setStatus({ kind: 'error', message: 'This browser could not prepare a private inquiry. Use the email contact option below.' }); return;
      }
      if (!isUuid(requestKey)) {
        setStatus({ kind: 'error', message: 'This browser could not prepare a private inquiry. Use the email contact option below.' }); return;
      }
      const body = JSON.stringify({ ...fields, noticeToken, requestKey });
      if (new TextEncoder().encode(body).byteLength > 16384) {
        setErrors({ message: 'This inquiry is too large to send. Shorten the message and try again.' });
        setStatus({ kind: 'error', message: 'Shorten your inquiry before sending.' }); return;
      }
      attempt.current = { body, privacyNotice, uncertain: false };
    }

    const current = attempt.current;
    inFlight.current = true;
    setErrors({}); setStatus({ kind: 'sending' });
    const abort = new AbortController(); controller.current = abort;
    const timeout = window.setTimeout(() => abort.abort(), 20_000);
    try {
      const response = await fetch('/contact/inquiries', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() }, body: current.body,
      });
      if (!active.current) return;
      let result: unknown;
      try { result = await response.json(); } catch { result = null; }
      if (!active.current) return;
      const data = result && typeof result === 'object' ? result as Record<string, unknown> : {};
      if ((response.status === 200 || response.status === 201) && data.state === 'saved' && typeof data.receipt === 'string' && isUuid(data.receipt)) {
        attempt.current = null; setFields({ ...empty }); setStatus({ kind: 'saved', receipt: data.receipt }); return;
      }
      // Only a definitive first validation/body rejection releases the editable draft.
      // An uncertain earlier send must keep its original payload/key even if a retry is rejected.
      if (!current.uncertain && response.status === 422 && data.code === 'INQUIRY_VALIDATION_FAILED' && data.errors && typeof data.errors === 'object' && !Array.isArray(data.errors)) {
        const invalid: Errors = {};
        const rejected = data.errors;
        for (const field of Object.keys(limits) as VisibleField[]) {
          if (Object.hasOwn(rejected, field)) invalid[field] = field === 'email' ? 'Enter a valid email address of 254 characters or fewer.' : `Check your ${field}; use ${limits[field].toLocaleString('en-US')} characters or fewer.`;
        }
        attempt.current = null; setFields(value => ({ ...value, website: '' })); setErrors(invalid);
        if (Object.hasOwn(rejected, 'noticeToken')) {
          setRejectedNoticeToken(noticeToken);
          setStatus({ kind: 'error', message: 'Your inquiry was not saved. Copy your draft before refreshing contact to review the current privacy notice.' }); return;
        }
        setStatus({ kind: 'error', message: Object.keys(invalid).length ? 'Check the highlighted fields before sending.' : 'Check your inquiry before sending again.' }); return;
      }
      if (!current.uncertain && response.status === 413) {
        attempt.current = null; setErrors({ message: 'This inquiry is too large to send. Shorten the message and try again.' });
        setStatus({ kind: 'error', message: 'Shorten your inquiry before sending.' }); return;
      }
      if (response.status === 419) {
        uncertain('Your session expired. Keep this page open. Open contact in a new tab to renew your session, then retry the same inquiry here.', true); return;
      }
      if (response.status === 429) { uncertain('Please wait a moment before retrying the same inquiry. Your original message is kept here.'); return; }
      if (response.status === 404) { uncertain('This contact form is currently unavailable. Your inquiry has not been confirmed. Your original message is kept here; the email contact option remains available.'); return; }
      if (response.status === 409) { uncertain('This inquiry could not be matched to its request. It has not been confirmed. Keep a copy of your message and use the email contact option if you need help.'); return; }
      uncertain();
    } catch {
      if (active.current) uncertain();
    } finally {
      window.clearTimeout(timeout);
      if (controller.current === abort) controller.current = null;
      inFlight.current = false;
    }
  }

  if (!attempt.current && (!privacyNotice.trim() || !isNoticeToken(noticeToken))) return null;

  if (status.kind === 'saved') return <section className="contact-inquiry" aria-label="Contact inquiry">
    <div className="contact-inquiry-summary" role="status" tabIndex={-1} ref={summary}>
      <h2>Inquiry saved</h2><p>Your inquiry was saved privately for VASEY.AUDIO.</p>
      <p className="contact-inquiry-receipt">Receipt <code>{status.receipt}</code></p>
    </div>
    <button type="button" className="button button-outline" onClick={() => { setStatus({ kind: 'editing' }); window.requestAnimationFrame(() => nameInput.current?.focus()); }}>Write another inquiry</button>
  </section>;

  return <section className="contact-inquiry" aria-labelledby={`${prefix}-title`}>
    <div className="contact-inquiry-heading"><h2 id={`${prefix}-title`}>Send an inquiry</h2><p>Your inquiry will be saved privately for VASEY.AUDIO.</p></div>
    <p className="contact-inquiry-privacy" id={`${prefix}-privacy`}>{displayedNotice}</p>
    <form noValidate onSubmit={submit} aria-describedby={`${prefix}-privacy`}>
      {status.kind === 'error' && <div className="contact-inquiry-summary" role="alert" tabIndex={-1} ref={summary}>
        <p>{status.message}</p>
        {Object.keys(errors).length > 0 && <ul>{(Object.keys(errors) as VisibleField[]).map(field => <li key={field}><a href={`#${prefix}-${field}`} onClick={event => { event.preventDefault(); document.getElementById(`${prefix}-${field}`)?.focus(); }}>{errors[field]}</a></li>)}</ul>}
        {status.sessionExpired && <a href="/contact" target="_blank" rel="noopener noreferrer">Open contact in a new tab</a>}
        {noticeRefreshRequired && <a href="/contact">Refresh contact to review the current privacy notice</a>}
      </div>}
      <div className="contact-inquiry-fields">
        {(Object.keys(limits) as VisibleField[]).map(field => {
          const props = { id: `${prefix}-${field}`, name: field, value: fields[field], required: true, readOnly: locked,
            maxLength: limits[field] * 2, 'aria-invalid': errors[field] ? true as const : undefined,
            'aria-describedby': [errors[field] ? `${prefix}-${field}-error` : '', field === 'message' ? `${prefix}-limit` : ''].filter(Boolean).join(' ') || undefined,
            onChange: (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => change(field, event.target.value) };
          return <div className={`contact-inquiry-field contact-inquiry-${field}`} key={field}>
            <label htmlFor={props.id}>{labels[field]} <span>(required)</span></label>
            {field === 'message' ? <textarea {...props} rows={7} /> : <input {...props} type={field === 'email' ? 'email' : 'text'} autoComplete={field === 'name' ? 'name' : field === 'email' ? 'email' : 'off'} ref={field === 'name' ? nameInput : field === 'email' ? emailInput : undefined} />}
            {errors[field] && <p className="contact-inquiry-field-error" id={`${prefix}-${field}-error`}>{errors[field]}</p>}
          </div>;
        })}
        <div className="contact-inquiry-trap" aria-hidden="true"><label htmlFor={`${prefix}-website`}>Website</label><input id={`${prefix}-website`} name="website" type="text" tabIndex={-1} autoComplete="off" value={fields.website} maxLength={200} onChange={event => change('website', event.target.value)} /></div>
      </div>
      <p className="contact-inquiry-note" id={`${prefix}-limit`}>{[...fields.message].length.toLocaleString('en-US')} / 8,000 message characters. Your draft stays only on this page.</p>
      <div className="contact-inquiry-actions"><button className="button" type="submit" disabled={sending || noticeRefreshRequired}>{sending ? 'Saving inquiry…' : locked ? 'Retry same inquiry' : 'Send inquiry'}</button>
        {sending && <p role="status">Saving your inquiry. Please keep this page open.</p>}
        {locked && !sending && <p>The original fields are read-only while this inquiry is unconfirmed.</p>}
      </div>
    </form>
  </section>;
}
