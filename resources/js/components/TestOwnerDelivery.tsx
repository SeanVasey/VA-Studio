import { useEffect, useRef, useState } from 'react';
import { deliveryFailure, deliveryJson, deliveryRoles, validAuthorization, validDelivery, type DeliveryItem, type DeliveryListing } from '../lib/test-delivery';

interface Props { orderId: string }
interface Operation { key: string; item: DeliveryItem }
const unknownIssue = 'The authorization result is unconfirmed. Retry the same request to resolve it, or check recent attempts before deliberately starting a new request.';
const submitted = 'A download attempt was submitted to your browser. Check its downloads list; this page cannot confirm file receipt. An interrupted attempt may be consumed. Refresh recent attempts for the saved status.';

export function TestOwnerDelivery(props: Props) { return <Delivery key={props.orderId} {...props} />; }
function Delivery({ orderId }: Props) {
  const [listing, setListing] = useState<DeliveryListing | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [notice, setNotice] = useState('');
  const [uncertain, setUncertain] = useState(false);
  const [lastItem, setLastItem] = useState<DeliveryItem | null>(null);
  const active = useRef(false), inFlight = useRef(false);
  const operation = useRef<Operation | null>(null);
  const requestController = useRef<AbortController | null>(null);
  const requestGeneration = useRef(0), deadline = useRef<number | null>(null);
  const frames = useRef<HTMLIFrameElement[]>([]);
  const responseGeneration = useRef(0);
  const base = `/orders/${encodeURIComponent(orderId)}/delivery`;

  function stopDeadline() { if (deadline.current !== null) window.clearTimeout(deadline.current); deadline.current = null; }
  function cancelRequest() {
    stopDeadline(); ++requestGeneration.current; requestController.current?.abort(); requestController.current = null; inFlight.current = false;
  }
  function beginRequest(onTimeout: () => void) {
    const current = ++requestGeneration.current, controller = new AbortController(); requestController.current = controller;
    inFlight.current = true; setBusy(true);
    const ownsRequest = () => active.current && requestGeneration.current === current && requestController.current === controller;
    const timer = window.setTimeout(() => {
      if (!ownsRequest()) return;
      cancelRequest(); setBusy(false); onTimeout();
    }, 20_000);
    deadline.current = timer;
    const finish = () => {
      if (!ownsRequest()) return;
      if (deadline.current === timer) stopDeadline();
      requestController.current = null; inFlight.current = false; setBusy(false);
    };
    return { controller, ownsRequest, finish };
  }

  async function refresh() {
    if (inFlight.current) return;
    setMessage('');
    const failed = () => { setListing(null); setNotice(''); setMessage('The test download status could not be loaded. Refresh to try again.'); };
    const { controller, ownsRequest, finish } = beginRequest(failed);
    try {
      const response = await fetch(base, { method: 'GET', credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { Accept: 'application/json' } });
      const body = await deliveryJson(response, controller.signal);
      if (!ownsRequest() || controller.signal.aborted) return;
      if (!response.ok) { setListing(null); setMessage(deliveryFailure(body, response.status) ?? 'The test download status could not be loaded. Refresh to try again.'); return; }
      if (!body || typeof body !== 'object' || !('delivery' in body) || !validDelivery(body.delivery, orderId)) throw new Error('Invalid delivery status');
      setListing(body.delivery);
    } catch {
      if (ownsRequest() && !controller.signal.aborted) failed();
    } finally { finish(); }
  }

  function submitAttachment(authorizationId: string, token: string, csrf: string) {
    // A native POST streams directly to the browser. No token-bearing URL, storage, blob or fetch of file bytes.
    // Replacing an older frame is an explicit new request and may interrupt its earlier stream.
    if (frames.current.length === 3) frames.current.shift()?.remove();
    const frame = document.createElement('iframe');
    frame.name = `test-delivery-${crypto.randomUUID()}`; frame.title = 'Test download response'; frame.hidden = true;
    frame.setAttribute('referrerpolicy', 'no-referrer');
    const generation = responseGeneration.current;
    frame.addEventListener('load', () => {
      if (!active.current || generation !== responseGeneration.current) return;
      try {
        const doc = frame.contentDocument;
        if (!doc || doc.location.href === 'about:blank') return;
        const raw = doc.body?.textContent ?? '';
        if (raw.length > 4096) { setMessage('The download response could not be confirmed. Check your browser downloads and refresh recent attempts.'); setNotice(''); return; }
        const failure = deliveryFailure(JSON.parse(raw));
        setMessage(failure ?? 'The download response could not be confirmed. Check your browser downloads and refresh recent attempts.');
        setNotice('');
      } catch { setMessage('The download response could not be confirmed. Check your browser downloads and refresh recent attempts.'); setNotice(''); }
    });
    document.body.append(frame); frames.current.push(frame);
    const form = document.createElement('form');
    form.method = 'POST'; form.action = `${base}/download`; form.target = frame.name; form.enctype = 'application/x-www-form-urlencoded'; form.hidden = true;
    for (const [name, value] of Object.entries({ authorizationId, token, _token: csrf })) {
      const field = document.createElement('input'); field.type = 'hidden'; field.name = name; field.value = value; form.append(field);
    }
    document.body.append(form);
    try { HTMLFormElement.prototype.submit.call(form); }
    finally { for (const field of form.querySelectorAll('input')) field.value = ''; form.remove(); }
  }

  async function issue(item: DeliveryItem, retry = false) {
    if (inFlight.current || (!retry && uncertain)) return;
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (!csrf) { setMessage('Your session expired. Reload this page before requesting another download.'); return; }
    if (!retry) operation.current = { key: crypto.randomUUID(), item };
    const current = operation.current;
    if (!current) return;
    responseGeneration.current += 1; setMessage(''); setNotice('');
    const unconfirmed = () => { setUncertain(true); setMessage(unknownIssue); };
    const { controller, ownsRequest, finish } = beginRequest(unconfirmed);
    try {
      const response = await fetch(`${base}/authorizations`, { method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Idempotency-Key': current.key },
        body: JSON.stringify({ grantId: current.item.grantId, kind: current.item.kind }),
      });
      const body = await deliveryJson(response, controller.signal);
      if (!ownsRequest() || controller.signal.aborted) return;
      if (response.status !== 201) {
        const failure = deliveryFailure(body, response.status);
        if (!failure || response.status === 503) throw new Error('Unconfirmed authorization');
        operation.current = null; setUncertain(false); setLastItem(current.item); setMessage(failure); return;
      }
      if (!body || typeof body !== 'object' || !('authorization' in body) || !validAuthorization(body.authorization, current.item)) throw new Error('Invalid authorization');
      // Secret lifetime is confined to this call and the immediately removed POST fields.
      const authorization = body.authorization;
      submitAttachment(authorization.authorizationId, authorization.token, csrf);
      operation.current = null; setUncertain(false); setLastItem(current.item); setNotice(submitted);
    } catch {
      if (ownsRequest() && !controller.signal.aborted) unconfirmed();
    } finally { finish(); }
  }

  useEffect(() => {
    const dispose = () => {
      cancelRequest(); ++responseGeneration.current; operation.current = null;
      for (const frame of frames.current) frame.remove(); frames.current = [];
    };
    const depart = () => {
      dispose(); setListing(null); setBusy(false); setMessage(''); setNotice(''); setUncertain(false); setLastItem(null);
    };
    window.addEventListener('pagehide', depart);
    active.current = true; void refresh();
    return () => {
      active.current = false; dispose(); window.removeEventListener('pagehide', depart);
    };
  }, []);

  return <section className="quote-review" aria-label="Test order downloads" aria-busy={busy}>
    <h3>TEST ORDER DOWNLOADS</h3>
    <p className="checkout-advisory">Test mode only. Availability is checked separately from payment and contract status. Each authorization allows one short-lived stream attempt, not confirmed receipt.</p>
    {message && <p role="alert">{message}</p>}
    {notice && <p role="status">{notice}</p>}
    {!listing && busy && <p role="status">Loading test downloads…</p>}
    {listing && <>
      {listing.status === 'unavailable' ? <p>Downloads are not available for this test order currently.</p> : listing.items.length === 0 ? <p>No test download items are available.</p> : <ul className="quote-review-result">
        {listing.items.map(item => <li className="quote-review-item" key={`${item.grantId}:${item.kind}`}>
          <p id={`delivery-${item.grantId}-${item.kind}`}><strong>{deliveryRoles[item.kind].label}</strong> · Grant {item.grantId}</p>
          <p className="fine-print">{item.sizeBytes.toLocaleString()} bytes</p>
          <button type="button" className="button button-outline full-width" disabled={busy || uncertain} aria-describedby={`delivery-${item.grantId}-${item.kind}`} onClick={() => void issue(item)}>
            {lastItem?.grantId === item.grantId && lastItem.kind === item.kind ? 'Request another' : 'Download'} {deliveryRoles[item.kind].label}
          </button>
        </li>)}
      </ul>}
      <h4>RECENT DOWNLOAD ATTEMPTS</h4>
      <p className="fine-print">These records show authorization and stream attempts, not successful receipt. A new request may interrupt an earlier stream; wait for your browser download to finish first.</p>
      {listing.history.length === 0 ? <p>No recent download attempts.</p> : <ul className="quote-review-result">
        {listing.history.map(entry => <li className="quote-review-item" key={entry.authorizationId}>
          <p><strong>{deliveryRoles[entry.kind].label}</strong> · {entry.status === 'attempted' ? 'Stream attempted' : entry.status === 'expired' ? 'Authorization expired' : 'No recorded stream attempt'}</p>
          <p className="fine-print">Grant {entry.grantId}. Issued <time dateTime={entry.issuedAt}>{new Date(entry.issuedAt).toLocaleString()}</time>.</p>
        </li>)}
      </ul>}
      {listing.historyHasMore && <p className="fine-print">Showing the 20 most recent authorizations.</p>}
    </>}
    {uncertain && <>
      <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => operation.current && void issue(operation.current.item, true)}>Retry the same authorization request</button>
      <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => { operation.current = null; setUncertain(false); setMessage('Select an item to deliberately request a new authorization. The earlier request may still count toward the temporary limit.'); }}>Start a new authorization request</button>
    </>}
    <button type="button" className="button button-outline full-width" disabled={busy} onClick={() => void refresh()}>Refresh downloads and recent attempts</button>
  </section>;
}
