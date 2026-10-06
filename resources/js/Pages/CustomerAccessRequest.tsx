import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Head } from '@inertiajs/react';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import type { SiteContent } from '../lib/site-content';
import { identityRequest, type IdentityPurpose } from '../lib/customer-identity';
import '../../css/customer-account.css';

export default function CustomerAccessRequest({ siteContent, purpose }: { testOnly: true; siteContent: SiteContent; purpose: IdentityPurpose }) {
  const id = useId(), [email, setEmail] = useState(''), [busy, setBusy] = useState(false), [saved, setSaved] = useState(false), [message, setMessage] = useState('');
  const attempt = useRef<{ body: string; uncertain: boolean } | null>(null), active = useRef(true), pending = useRef(false), controller = useRef<AbortController | null>(null), summary = useRef<HTMLDivElement>(null);
  useEffect(() => { active.current = true; return () => { active.current = false; controller.current?.abort(); }; }, []);
  useEffect(() => { if (message) summary.current?.focus(); }, [message]);
  async function submit(event: FormEvent) {
    event.preventDefault(); if (pending.current || saved) return;
    if (!attempt.current) {
      try { attempt.current = { body: JSON.stringify({ purpose, email, requestKey: crypto.randomUUID() }), uncertain: false }; }
      catch { setMessage('This browser could not prepare the request. Open a fresh page to try again.'); return; }
    }
    pending.current = true; setBusy(true); setMessage('');
    const original = attempt.current, abort = new AbortController(); controller.current = abort;
    const timeout = window.setTimeout(() => abort.abort(), 20_000);
    const result = await identityRequest('request', original.body, abort.signal);
    window.clearTimeout(timeout); pending.current = false; controller.current = null;
    if (!active.current) return;
    setBusy(false);
    if (result === 'saved') { attempt.current = null; setEmail(''); setSaved(true); setMessage('Request accepted. If the address is eligible, a message is available in the private test capture. No email is sent.'); }
    else if (result === 'invalid' && !original.uncertain) { attempt.current = null; setMessage('Check the email address and try again.'); }
    else { original.uncertain = true; setMessage(result === 'expired' ? 'Your session expired. Keep this page open, renew sign-in in another tab, then retry the same request.' : 'The request could not be confirmed. Retry the same request; it will not create another account.'); }
  }
  const chrome = { content: siteContent, homeHref: '/', href: (path: string) => path, onNavigate: () => {} };
  return <><Head title={purpose === 'enroll' ? 'Create a test account' : 'Recover a test account'}><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a><SiteHeader {...chrome} customerAccountEnabled />
    <main id="main" className="customer-account section-pad"><div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / TEST ACCOUNT</p>
      <h1>{purpose === 'enroll' ? 'Create a test account' : 'Recover a test account'}</h1></div>
      <p className="customer-account-note">Local test mode. Messages go only to a private test capture, never email. Links expire after ten minutes. {purpose === 'enroll' ? 'Choose your password after proving access to the requested address.' : 'Only an existing active customer account can be recovered.'} This does not claim guest orders or change staff access.</p>
      {message && <div className="customer-account-message" role={saved ? 'status' : 'alert'} tabIndex={-1} ref={summary}>{message}</div>}
      {!saved && <form className="customer-sign-in" onSubmit={event => void submit(event)} aria-busy={busy}>
        <div className="customer-account-field"><label htmlFor={`${id}-email`}>Email address</label><input id={`${id}-email`} type="email" autoComplete="email" required maxLength={254} value={email} readOnly={attempt.current !== null} disabled={busy} onChange={event => setEmail(event.target.value)} /></div>
        <button className="button full-width" disabled={busy}>{busy ? 'Preparing request…' : attempt.current ? 'Retry same request' : 'Request test message'}</button>
      </form>}
      {attempt.current && <a className="text-link customer-account-back" href="/account/sign-in" target="_blank" rel="noopener noreferrer">Renew sign-in in a new tab</a>}
      <a className="text-link customer-account-back" href="/account/sign-in">Back to sign-in</a>
    </main><SiteFooter {...chrome} /></>;
}
