import { Head } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { identityRequestKey, productionIdentityRequest, takeProductionIdentityProof, type IdentityAction, type IdentityProof } from '../lib/production-customer-identity';
import '../../css/customer-account.css';

type Mode = 'enroll' | 'recover' | 'complete' | 'sign-in' | 'account';
export default function ProductionCustomerIdentity({ mode, rehearsal }: { mode: Mode; rehearsal: boolean }) {
  const id = useId(), [email, setEmail] = useState(''), [password, setPassword] = useState(''), [name, setName] = useState('');
  const [message, setMessage] = useState(''), [busy, setBusy] = useState(false), [saved, setSaved] = useState(false), [closed, setClosed] = useState(false);
  const proof = useRef<IdentityProof | null>(null), captured = useRef(false), pending = useRef(false), active = useRef(true);
  const departed = useRef(false), generation = useRef(0), timeout = useRef<number | null>(null), form = useRef<HTMLFormElement>(null);
  const attempt = useRef<{ body: string; uncertain: boolean } | null>(null), controller = useRef<AbortController | null>(null), summary = useRef<HTMLDivElement>(null);
  useEffect(() => {
    active.current = !departed.current;
    const erase = (render: boolean) => {
      departed.current = true; active.current = false; generation.current++;
      // Clear the actual controls before the browser can take its BFCache snapshot.
      form.current?.querySelectorAll('input').forEach(input => { input.value = ''; });
      if (attempt.current) { attempt.current.body = ''; attempt.current.uncertain = false; }
      attempt.current = null; proof.current = null; captured.current = true; takeProductionIdentityProof(); pending.current = false;
      if (timeout.current !== null) window.clearTimeout(timeout.current); timeout.current = null;
      const abort = controller.current; controller.current = null;
      if (render) { setPassword(''); setName(''); setEmail(''); setBusy(false); setSaved(false); setClosed(true); setMessage('This page was closed. Open a fresh account page to continue.'); }
      abort?.abort();
    };
    const leave = () => erase(true), restore = (event: PageTransitionEvent) => { if (event.persisted || departed.current) erase(true); };
    window.addEventListener('pagehide', leave); window.addEventListener('pageshow', restore);
    return () => {
      window.removeEventListener('pagehide', leave); window.removeEventListener('pageshow', restore); active.current = false;
      const removed = ++generation.current;
      if (timeout.current !== null) window.clearTimeout(timeout.current); timeout.current = null; controller.current?.abort();
      // React's development effect rehearsal immediately reinstalls this same mount.
      // A real removal is still fenced and aborted synchronously, then purged this turn.
      queueMicrotask(() => { if (!active.current && generation.current === removed) erase(false); });
    };
  }, []);
  useEffect(() => { if (!departed.current && mode === 'complete' && !captured.current) { proof.current = takeProductionIdentityProof(); captured.current = true; if (!proof.current) setMessage('This link is unavailable. Request a new message to continue.'); } }, [mode]);
  useEffect(() => { if (message) summary.current?.focus(); }, [message]);
  const title = ({ enroll: 'Create your account', recover: 'Recover your account', complete: 'Complete your account access', 'sign-in': 'Sign in', account: 'Your account' })[mode];
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!active.current || departed.current || pending.current || saved || (mode === 'complete' && !proof.current)) return;
    const action: IdentityAction = mode === 'account' ? 'sign-out' : mode === 'sign-in' ? 'sign-in' : mode === 'complete' ? 'complete' : 'request';
    if (!attempt.current) {
      try { attempt.current = { body: JSON.stringify(action === 'request' ? { purpose: mode, email, requestKey: identityRequestKey() } : action === 'complete' ? { ...proof.current, password, name, requestKey: identityRequestKey() } : action === 'sign-in' ? { email, password } : {}), uncertain: false }; }
      catch { setMessage('This browser could not prepare the request. Open a fresh page to try again.'); return; }
    }
    pending.current = true; setBusy(true); setMessage(''); const original = attempt.current, abort = new AbortController(), current = ++generation.current; controller.current = abort;
    const timer = window.setTimeout(() => abort.abort(), 20_000); timeout.current = timer;
    const result = await productionIdentityRequest(action, original.body, abort.signal);
    window.clearTimeout(timer); if (!active.current || departed.current || generation.current !== current) { original.body = ''; return; }
    timeout.current = null; pending.current = false; controller.current = null; setBusy(false);
    if (result === 'saved') {
      attempt.current = null; proof.current = null; setPassword(''); setEmail(''); setName(''); setSaved(true);
      if (action === 'sign-in' || action === 'sign-out') { window.location.assign(action === 'sign-in' ? '/customer' : '/customer/sign-in'); return; }
      setMessage(action === 'request' ? 'Request accepted. If this address is eligible, you will receive a private link that expires in ten minutes.' : 'Account access completed. Sign in with your current password.');
    } else if (result === 'invalid' && !original.uncertain) {
      attempt.current = null; setMessage(action === 'complete' ? 'This request could not be completed. Check your password or request a new link.' : 'Check your details and try again.');
    } else { original.uncertain = true; setMessage(result === 'expired' ? 'Your session expired. Keep this page open, renew sign-in in another tab, then retry the same request.' : 'The request could not be confirmed. Retry the same request.'); }
  }
  const locked = attempt.current !== null;
  return <><Head title={title}><meta name="robots" content="noindex, nofollow" /><meta name="referrer" content="no-referrer" /></Head>
    <a className="skip-link" href="#main">Skip to content</a><header className="section-pad"><a href="/" aria-label="VaseyAudio home">VASEY.AUDIO</a></header>
    <main id="main" className="customer-account section-pad"><div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / ACCOUNT</p><h1>{title}</h1></div>
      {rehearsal && <p className="customer-account-note">Local rehearsal. Messages remain in the local test mailbox.</p>}
      {(mode === 'enroll' || mode === 'recover') && <p>Use your own email address. {mode === 'recover' ? 'Recovery keeps your original account and purchases.' : 'Choose a password after opening your private link.'}</p>}
      {mode === 'complete' && <p>Choose a password with at least twelve characters, including letters and numbers. The name you enter is your declaration.</p>}
      {mode === 'account' && <p>Your account is signed in.</p>}
      {message && <div className="customer-account-message" role={saved ? 'status' : 'alert'} tabIndex={-1} ref={summary}>{message}</div>}
      {!saved && <form ref={form} className="customer-sign-in" onSubmit={event => void submit(event)} aria-busy={busy}>
        {(mode === 'enroll' || mode === 'recover' || mode === 'sign-in') && <div className="customer-account-field"><label htmlFor={`${id}-email`}>Email address</label><input id={`${id}-email`} type="email" autoComplete="email" required maxLength={254} value={email} readOnly={locked} disabled={busy || closed} onChange={event => setEmail(event.target.value)} /></div>}
        {mode === 'complete' && <div className="customer-account-field"><label htmlFor={`${id}-name`}>Your name</label><input id={`${id}-name`} autoComplete="name" required maxLength={120} value={name} readOnly={locked} disabled={busy || closed} onChange={event => setName(event.target.value)} /></div>}
        {(mode === 'complete' || mode === 'sign-in') && <div className="customer-account-field"><label htmlFor={`${id}-password`}>Password</label><input id={`${id}-password`} type="password" autoComplete={mode === 'complete' ? 'new-password' : 'current-password'} required minLength={mode === 'complete' ? 12 : 1} maxLength={72} value={password} readOnly={locked} disabled={busy || closed} onChange={event => setPassword(event.target.value)} /></div>}
        <button className="button full-width" disabled={closed || busy || (mode === 'complete' && captured.current && !proof.current)}>{closed ? 'Open a fresh account page' : busy ? 'Working…' : locked ? 'Retry same request' : mode === 'account' ? 'Sign out' : mode === 'sign-in' ? 'Sign in' : mode === 'complete' ? 'Complete account access' : 'Request private link'}</button>
      </form>}
      {attempt.current && <a className="text-link customer-account-back" href="/customer/sign-in" target="_blank" rel="noopener noreferrer">Renew sign-in in a new tab</a>}
      <nav aria-label="Account access"><a className="text-link customer-account-back" href="/customer/sign-in">Sign in</a><a className="text-link customer-account-back" href="/customer/create">Create account</a><a className="text-link customer-account-back" href="/customer/recover">Recover account</a></nav>
    </main></>;
}
