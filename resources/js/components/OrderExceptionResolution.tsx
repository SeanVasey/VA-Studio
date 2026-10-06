import { useEffect, useId, useRef, useState } from 'react';
import { readExceptionResolution, RESOLUTION_UNAVAILABLE, type RetainedExceptionResolution } from '../lib/customer-exception-resolution';

export function OrderExceptionResolution({ orderId }: { orderId: string }) {
  return <Resolution key={orderId} orderId={orderId} />;
}

function Resolution({ orderId }: { orderId: string }) {
  const [resolution, setResolution] = useState<RetainedExceptionResolution | null>(null);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [signIn, setSignIn] = useState(false);
  const active = useRef(false), generation = useRef(0), restoreFocus = useRef(false);
  const pending = useRef<AbortController | null>(null), deadline = useRef<number | null>(null);
  const trigger = useRef<HTMLButtonElement>(null), heading = useRef<HTMLHeadingElement>(null), alert = useRef<HTMLDivElement>(null);
  const contentId = useId();
  function stopDeadline() { if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null; }
  function clear(focus = false) {
    stopDeadline(); ++generation.current; pending.current?.abort(); pending.current = null; restoreFocus.current = focus;
    setResolution(null); setBusy(false); setMessage(''); setSignIn(false);
  }
  useEffect(() => {
    active.current = true; const depart = () => clear(); window.addEventListener('pagehide', depart);
    return () => { active.current = false; stopDeadline(); ++generation.current; pending.current?.abort(); pending.current = null; window.removeEventListener('pagehide', depart); };
  }, []);
  useEffect(() => { if (resolution) heading.current?.focus(); else if (message) alert.current?.focus(); }, [resolution, message]);
  useEffect(() => {
    if (restoreFocus.current && !busy && !signIn) { restoreFocus.current = false; trigger.current?.focus(); }
  }, [resolution, message, busy, signIn]);

  async function load() {
    if (pending.current || signIn) return;
    clear(); const current = generation.current, abort = new AbortController(); pending.current = abort; setBusy(true);
    const ownsRequest = () => active.current && generation.current === current && pending.current === abort;
    const timer = window.setTimeout(() => {
      if (!ownsRequest()) return;
      deadline.current = null; abort.abort(); ++generation.current; pending.current = null;
      setResolution(null); setMessage(RESOLUTION_UNAVAILABLE); setSignIn(false); setBusy(false);
    }, 20_000);
    deadline.current = timer;
    try {
      const result = await readExceptionResolution(orderId, abort.signal);
      if (!ownsRequest() || abort.signal.aborted) return;
      if (result.kind === 'loaded') setResolution(result.resolution);
      else { setMessage(result.message); setSignIn(result.kind === 'reload'); }
    } finally {
      if (deadline.current === timer && pending.current === abort) stopDeadline();
      if (ownsRequest()) { pending.current = null; setBusy(false); }
    }
  }
  const expanded = !!(resolution || message || busy);
  return <section className="quote-review" aria-label="Recorded test-order resolution" aria-busy={busy}>
    <button ref={trigger} type="button" className="button button-outline full-width" disabled={busy || signIn}
      aria-expanded={expanded} aria-controls={contentId} onClick={() => void load()}>
      {busy ? 'Loading recorded test-order resolution…' : resolution || message ? 'Refresh recorded test-order resolution' : 'View recorded test-order resolution'}
    </button>
    {expanded && <button type="button" className="button button-outline full-width" onClick={() => clear(true)}>Hide recorded test-order resolution</button>}
    <div id={contentId}>
      {message && <div role="alert" tabIndex={-1} ref={alert}>{message}{signIn && <a href="/account/sign-in">Open a fresh sign-in page</a>}</div>}
      {resolution && <>
        <h4 tabIndex={-1} ref={heading}>Recorded test-order resolution</h4>
        {resolution.record ? <>
          <p>A full test refund was verified and this order’s reservations were released.</p>
          <p>Retained refund observation: <time dateTime={resolution.record.observedAt}>{resolution.record.observedAt}</time></p>
          <p>Reservations released: <time dateTime={resolution.record.releasedAt}>{resolution.record.releasedAt}</time></p>
          <p className="fine-print">This retained history does not confirm refund arrival or the current payment-provider state. These dates record observation and reservation release, not when a refund was sent.</p>
        </> : <p>No retained full-refund resolution was found for this test order. This does not establish whether a refund was made.</p>}
        <p className="fine-print">Test mode only. Contracts and downloads remain blocked.</p>
      </>}
    </div>
  </section>;
}
