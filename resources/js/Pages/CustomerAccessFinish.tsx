import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Head } from '@inertiajs/react';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import type { SiteContent } from '../lib/site-content';
import { clearCustomerIdentityProof, currentCustomerIdentityProof, identityRequest } from '../lib/customer-identity';
import '../../css/customer-account.css';

export default function CustomerAccessFinish({ siteContent }: { testOnly: true; siteContent: SiteContent }) {
  const id = useId(), [proof, setProof] = useState(currentCustomerIdentityProof), [name, setName] = useState(''), [password, setPassword] = useState('');
  const purpose = useRef(proof?.purpose).current;
  const [busy, setBusy] = useState(false), [saved, setSaved] = useState(false), [message, setMessage] = useState('');
  const active = useRef(true), pending = useRef(false), controller = useRef<AbortController | null>(null), summary = useRef<HTMLDivElement>(null);
  const attempt = useRef<{ body: string; uncertain: boolean } | null>(null);
  useEffect(() => {
    clearCustomerIdentityProof(); active.current = true;
    const forget = () => {
      active.current = false; controller.current?.abort(); attempt.current = null;
      setProof(null); setPassword(''); setName(''); setMessage(''); setBusy(false);
    };
    window.addEventListener('pagehide', forget);
    return () => { active.current = false; controller.current?.abort(); attempt.current = null; window.removeEventListener('pagehide', forget); };
  }, []);
  useEffect(() => { if (message) summary.current?.focus(); }, [message]);
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!proof || pending.current || saved) return;
    if (!attempt.current) {
      if ([...password].length < 12 || new TextEncoder().encode(password).length > 72 || password.includes('\0') || !/\p{L}/u.test(password) || !/\p{N}/u.test(password)) {
        setMessage('Use at least 12 characters with letters and numbers, and no more than 72 UTF-8 bytes.'); return;
      }
      try { attempt.current = { body: JSON.stringify({ ...proof, name: proof.purpose === 'enroll' ? name : '', password, requestKey: crypto.randomUUID() }), uncertain: false }; }
      catch { setMessage('This browser could not prepare the request. Request a new test message.'); return; }
    }
    pending.current = true; setBusy(true); setMessage('');
    const original = attempt.current, abort = new AbortController(); controller.current = abort;
    const timeout = window.setTimeout(() => abort.abort(), 20_000);
    const result = await identityRequest('complete', original.body, abort.signal);
    window.clearTimeout(timeout); pending.current = false; controller.current = null;
    if (!active.current) return;
    setBusy(false);
    if (result === 'saved') { attempt.current = null; setProof(null); setPassword(''); setName(''); setSaved(true); setMessage('Your account request is complete. Sign in with your new password.'); }
    else if (result === 'invalid' && !original.uncertain) { attempt.current = null; setPassword(''); setMessage('This request could not be completed. Check your details or request a new test message.'); }
    else { original.uncertain = true; setMessage(result === 'expired' ? 'Your session expired. Keep this page open, renew sign-in in a new tab, then retry these exact details.' : 'Completion could not be confirmed. Retry the same details to confirm the result without changing the password again.'); }
  }
  const chrome = { content: siteContent, homeHref: '/', href: (path: string) => path, onNavigate: () => {} };
  return <><Head title="Complete your test account request"><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a><SiteHeader {...chrome} customerAccountEnabled />
    <main id="main" className="customer-account section-pad"><div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / TEST ACCOUNT</p>
      <h1>{purpose === 'enroll' ? 'Create your test account' : 'Set a new test password'}</h1></div>
      <p className="customer-account-note">This test request changes only customer access. Existing purchases keep their original owner, contracts and files. You will sign in separately when finished.</p>
      {message && <div className="customer-account-message" role={saved ? 'status' : 'alert'} tabIndex={-1} ref={summary}>{message}</div>}
      {!proof && !saved && <p role="alert">Open the complete link from the private test message. A refreshed or incomplete page cannot recover its proof.</p>}
      {proof && !saved && <form className="customer-sign-in" onSubmit={event => void submit(event)} aria-busy={busy}>
          {proof.purpose === 'enroll' && <div className="customer-account-field"><label htmlFor={`${id}-name`}>Name</label><input id={`${id}-name`} autoComplete="name" required maxLength={120} value={name} disabled={busy} readOnly={attempt.current !== null} onChange={event => setName(event.target.value)} /></div>}
          <div className="customer-account-field"><label htmlFor={`${id}-password`}>New password</label><input id={`${id}-password`} type="password" autoComplete="new-password" required minLength={12} maxLength={72} value={password} disabled={busy} readOnly={attempt.current !== null} aria-describedby={`${id}-password-help`} onChange={event => setPassword(event.target.value)} /></div>
          <p className="customer-account-note" id={`${id}-password-help`}>At least 12 characters with letters and numbers. Maximum 72 UTF-8 bytes. Details stay only in this page while the result is unconfirmed.</p>
          <button className="button full-width" disabled={busy}>{busy ? 'Completing request…' : attempt.current ? 'Retry same details' : 'Complete account request'}</button>
      </form>}
      {attempt.current && <a className="text-link customer-account-back" href="/account/sign-in" target="_blank" rel="noopener noreferrer">Renew sign-in in a new tab</a>}
      {!saved && !attempt.current && <a className="text-link customer-account-back" href={purpose === 'enroll' ? '/account/create' : '/account/recover'}>Request a new test message</a>}
      <a className="text-link customer-account-back" href="/account/sign-in">Go to sign-in</a>
    </main><SiteFooter {...chrome} /></>;
}
