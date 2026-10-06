import { useEffect, useRef, useState } from 'react';
import { readInquiryHistory, type InquiryHistoryPage } from '../lib/inquiry-history';

export function InquiryHistory({ onOpen }: { onOpen: (receipt: string) => void }) {
  const [history, setHistory] = useState<InquiryHistoryPage | null>(null);
  const [anchors, setAnchors] = useState<Array<string | null>>([]);
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [reload, setReload] = useState(false);
  const active = useRef(false), generation = useRef(0), pending = useRef<AbortController | null>(null), deadline = useRef<number | null>(null);
  const heading = useRef<HTMLHeadingElement>(null), alert = useRef<HTMLParagraphElement>(null);
  const unavailable = 'Your inquiries could not be loaded. Refresh the list to try again.';
  function stopDeadline() { if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null; }
  function clear() {
    stopDeadline(); ++generation.current; pending.current?.abort(); pending.current = null;
    setHistory(null); setAnchors([]); setBusy(false); setMessage(''); setReload(false);
  }
  useEffect(() => {
    active.current = true; window.addEventListener('pagehide', clear);
    return () => { active.current = false; stopDeadline(); ++generation.current; pending.current?.abort(); pending.current = null; window.removeEventListener('pagehide', clear); };
  }, []);
  useEffect(() => { if (history) heading.current?.focus(); else if (message) alert.current?.focus(); }, [history, message]);

  async function load(direction: 'newest' | 'older' | 'newer' = 'newest') {
    if (pending.current || reload) return;
    let nextAnchors: Array<string | null> = [null];
    if (direction === 'older') {
      if (!history?.nextCursor || anchors.includes(history.nextCursor)) return;
      nextAnchors = [...anchors, history.nextCursor];
    } else if (direction === 'newer') {
      if (anchors.length < 2) return;
      nextAnchors = anchors.slice(0, -1);
    }
    const current = ++generation.current, controller = new AbortController(); pending.current = controller;
    const owns = () => active.current && generation.current === current && pending.current === controller;
    setBusy(true); setHistory(null); setMessage(''); setReload(false);
    const fail = (needsReload: boolean) => {
      setHistory(null); setAnchors([]); setMessage(needsReload ? 'Access to this browser session could not be confirmed. Reload contact before trying again.' : unavailable); setReload(needsReload);
    };
    const timer = window.setTimeout(() => {
      if (!owns()) return;
      deadline.current = null; controller.abort(); ++generation.current; pending.current = null; setBusy(false); fail(false);
    }, 20_000);
    deadline.current = timer;
    try {
      const result = await readInquiryHistory(nextAnchors[nextAnchors.length - 1], controller.signal);
      if (!owns() || controller.signal.aborted) return;
      if (result.kind === 'loaded' && (result.history.nextCursor === null || !nextAnchors.includes(result.history.nextCursor))) {
        setHistory(result.history); setAnchors(nextAnchors);
      } else fail(result.kind === 'reload');
    } finally {
      if (deadline.current === timer && pending.current === controller) stopDeadline();
      if (owns()) { pending.current = null; setBusy(false); }
    }
  }

  return <section className="contact-inquiry" aria-label="Inquiries from this browser session" aria-busy={busy}>
    <h2>Find your inquiries</h2>
    <p className="contact-inquiry-privacy">Browse inquiries sent from this browser session. Signing in, signing out or losing the session can remove access. Replies stay here; no email is sent.</p>
    <div className="contact-inquiry-actions"><button type="button" className="button button-outline" disabled={busy || reload} onClick={() => void load()}>
      {busy ? 'Loading inquiries…' : history || message ? 'Refresh inquiries' : 'Show inquiries from this browser session'}
    </button></div>
    {message && <p role="alert" tabIndex={-1} ref={alert}>{message}{reload && <> <a href="/contact">Reload contact</a></>}</p>}
    {history && <>
      <h3 tabIndex={-1} ref={heading}>Saved inquiries</h3>
      {history.inquiries.length === 0 ? <p role="status">No inquiries are available in this browser session.</p> : <>
        <p className="contact-inquiry-note">Newest saved inquiries first. Open a conversation to read its current replies.</p>
        <ol aria-label="Saved inquiries" style={{ paddingLeft: '1.5rem', overflowWrap: 'anywhere' }}>
          {history.inquiries.map(row => <li key={row.receipt}>
            <h4>{row.subject}</h4><p>Saved <time dateTime={row.createdAt}>{new Date(row.createdAt).toLocaleString()}</time> · {row.state === 'new' ? 'Saved' : row.state === 'read' ? 'Read by staff' : 'Archived'}</p>
            <p className="contact-inquiry-receipt">Receipt <code>{row.receipt}</code></p>
            <button type="button" className="button button-outline" onClick={() => onOpen(row.receipt)}>Open inquiry {row.receipt}</button>
          </li>)}
        </ol>
      </>}
      <div className="contact-inquiry-actions">
        {anchors.length > 1 && <><button type="button" className="button button-outline" onClick={() => void load('newer')}>Newer inquiries</button>
          <button type="button" className="button button-outline" onClick={() => void load('newest')}>Newest inquiries</button></>}
        {history.nextCursor !== null && <button type="button" className="button button-outline" onClick={() => void load('older')}>Older inquiries</button>}
      </div>
    </>}
  </section>;
}
