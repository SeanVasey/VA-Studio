import { useEffect, useRef, useState } from 'react';
import { readMembershipBuckets, readMembershipHistory, type MembershipHistory } from '../lib/membership-history';

/** Read-only synthetic history; every detail request rechecks current ownership on the server. */
export function OwnedMembershipHistory() {
  const [ids, setIds] = useState<number[] | null>(null), [history, setHistory] = useState<MembershipHistory | null>(null);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [reload, setReload] = useState(false), [shown, setShown] = useState(50);
  const active = useRef(false), generation = useRef(0), pending = useRef<AbortController | null>(null), deadline = useRef<number | null>(null);
  function clear() {
    ++generation.current; pending.current?.abort(); pending.current = null;
    if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null;
    setIds(null); setHistory(null); setBusy(false); setMessage(''); setReload(false); setShown(50);
  }
  useEffect(() => {
    active.current = true; window.addEventListener('pagehide', clear);
    return () => { active.current = false; ++generation.current; pending.current?.abort(); if (deadline.current !== null) window.clearTimeout(deadline.current); window.removeEventListener('pagehide', clear); };
  }, []);
  async function load(id?: number) {
    if (pending.current || reload || (id !== undefined && !ids?.includes(id))) return;
    const current = ++generation.current, abort = new AbortController(); pending.current = abort;
    setHistory(null); setShown(50); setMessage(''); setBusy(true); if (id === undefined) setIds(null);
    const owns = () => active.current && current === generation.current && pending.current === abort;
    const fail = (signIn: boolean) => { setIds(null); setHistory(null); setMessage('Test membership history is unavailable. Refresh the list or sign in again.'); setReload(signIn); };
    const timer = window.setTimeout(() => {
      if (!owns()) return; abort.abort(); ++generation.current; pending.current = null; deadline.current = null; setBusy(false); fail(false);
    }, 20_000); deadline.current = timer;
    try {
      if (id === undefined) {
        const result = await readMembershipBuckets(abort.signal);
        if (!owns() || abort.signal.aborted) return;
        if (result.kind === 'loaded') setIds(result.history.bucket_ids); else fail(result.kind === 'reload');
      } else {
        const result = await readMembershipHistory(id, abort.signal);
        if (!owns() || abort.signal.aborted) return;
        if (result.kind === 'loaded') setHistory(result.history); else fail(result.kind === 'reload');
      }
    } finally { if (owns()) { window.clearTimeout(timer); deadline.current = null; pending.current = null; setBusy(false); } }
  }
  return <section aria-label="Your test membership history" aria-busy={busy}>
    <h2>Test membership credits</h2>
    <p className="customer-account-note">Synthetic test history only. These credits do not establish a subscription, payment, purchased rights or downloads.</p>
    <button type="button" className="button button-outline" disabled={busy || reload} onClick={() => void load()}>{busy ? 'Loading test credits…' : 'Browse test credit buckets'}</button>
    {message && <p role="alert">{message}{reload && <a href="/account/sign-in">Open a fresh sign-in page</a>}</p>}
    {ids !== null && <>
      {ids.length === 0 ? <p role="status">No test credit buckets belong to this account.</p> : <div aria-label="Owned test credit buckets">{ids.map((id, index) => <button key={id} type="button" className="button button-outline" disabled={busy} aria-pressed={history?.bucket_id === id} onClick={() => void load(id)}>View test credit bucket {index + 1}</button>)}</div>}
    </>}
    {history && <div className="quote-review-item">
      <h3>{history.plan.title} · Version {history.plan.number}</h3>
      <p>Credit unit: {history.unit}</p>
      <p>Server-reported spendable test credits at this read: {history.spendable_credits}. Current availability must be checked again before any use.</p>
      <p>Retained balance: {history.balance.available} available · {history.balance.reserved} reserved · {history.balance.consumed} consumed · {history.balance.expired} expired.</p>
      <p>{history.expires_at === null ? 'No expiry is recorded for this test bucket.' : `Recorded expiry: ${history.expires_at} UTC.`} Reading history does not renew credits or record expiry.</p>
      <h4>Retained test events</h4>
      <p className="fine-print">Newest events first. Showing {Math.min(shown, history.events.length)} of {history.events.length} retained events.</p>
      <ol>{history.events.slice(-shown).reverse().map(event => <li key={event.id}>{event.kind} · {event.amount} {history.unit} · {event.created_at} UTC. Balance: {event.balance.available} available, {event.balance.reserved} reserved, {event.balance.consumed} consumed, {event.balance.expired} expired.</li>)}</ol>
      {shown < history.events.length && <button type="button" className="button button-outline" onClick={() => setShown(value => value + 50)}>Show older test events</button>}
    </div>}
  </section>;
}
