import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { Head } from '@inertiajs/react';
import { SiteFooter, SiteHeader } from '../components/SiteChrome';
import type { SiteContent } from '../lib/site-content';
import { changeCustomerSession, navigateCustomerSession } from '../lib/customer-session';
import '../../css/customer-account.css';

export default function CustomerSignIn({ siteContent, selfServiceEnabled = false }: { testOnly: true; siteContent: SiteContent; selfServiceEnabled?: boolean }) {
  const id = useId();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [reload, setReload] = useState(false);
  const active = useRef(false), pending = useRef(false);
  const controller = useRef<AbortController | null>(null);
  const alert = useRef<HTMLDivElement>(null);
  useEffect(() => { active.current = true; return () => { active.current = false; controller.current?.abort(); }; }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending.current || reload) return;
    pending.current = true; setBusy(true); setMessage('');
    const abort = new AbortController(); controller.current = abort;
    const timer = window.setTimeout(() => abort.abort(), 20_000);
    const credentials = { email, password };
    setPassword('');
    try {
      const result = await changeCustomerSession('sign-in', credentials, abort.signal);
      if (!active.current) return;
      if (result.kind === 'complete') { setReload(true); navigateCustomerSession(result.next); return; }
      setReload(result.kind === 'reload'); setMessage(result.message);
    } finally {
      window.clearTimeout(timer); controller.current = null; pending.current = false;
      if (active.current) setBusy(false);
    }
  }
  const chrome = { content: siteContent, homeHref: '/', href: (path: string) => path, onNavigate: () => {} };
  return <>
    <Head title="Customer sign-in"><meta name="robots" content="noindex, nofollow" /></Head>
    <a className="skip-link" href="#main">Skip to content</a>
    <SiteHeader {...chrome} customerAccountEnabled />
    <main id="main" className="customer-account section-pad">
      <div className="editorial-heading"><p className="eyebrow">VASEY.AUDIO / TEST ACCOUNT</p><h1>Customer sign-in</h1>
        <p className="editorial-description">Open your test order library.</p></div>
      <p className="customer-account-note">For existing test accounts. Signing in does not link earlier guest orders or create download access.</p>
      <form className="customer-sign-in" onSubmit={event => void submit(event)} aria-busy={busy}>
        <div className="customer-account-field"><label htmlFor={`${id}-email`}>Email address</label>
          <input id={`${id}-email`} name="email" type="email" autoComplete="username" required maxLength={254} value={email} disabled={busy || reload} onChange={event => setEmail(event.target.value)} /></div>
        <div className="customer-account-field"><label htmlFor={`${id}-password`}>Password</label>
          <input id={`${id}-password`} name="password" type="password" autoComplete="current-password" required maxLength={1024} value={password} disabled={busy || reload} onChange={event => setPassword(event.target.value)} /></div>
        {message && <div className="customer-account-message" role="alert" tabIndex={-1} ref={alert}>{message}
          {reload && <a href="/account/sign-in">Open a fresh sign-in page</a>}</div>}
        <button type="submit" className="button full-width" disabled={busy || reload}>{busy ? 'Signing in…' : 'Sign in'}</button>
      </form>
      {selfServiceEnabled && <div className="customer-account-note"><p><a className="text-link" href="/account/create">Create a test account</a></p><p><a className="text-link" href="/account/recover">Recover a test account</a></p></div>}
      <a className="text-link customer-account-back" href="/">Back to the catalog</a>
    </main>
    <SiteFooter {...chrome} />
  </>;
}
