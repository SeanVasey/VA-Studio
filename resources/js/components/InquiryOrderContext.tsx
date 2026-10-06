import { readOrderInquiryContext, type OrderInquiryContext } from '../lib/order-inquiry';
import { usePrivateInquiryRead } from '../lib/usePrivateInquiryRead';

/** Separate retained projection: neither a receipt nor a linked order grants access to the other. */
export function InquiryOrderContext({ receipt }: { receipt: string }) { return <Context key={receipt} receipt={receipt} />; }

function Context({ receipt }: { receipt: string }) {
  const view = usePrivateInquiryRead<OrderInquiryContext>(signal => readOrderInquiryContext(receipt, signal));
  return <section className="contact-inquiry" aria-label="Inquiry order reference">
    <button ref={view.trigger} className="button button-outline" type="button" disabled={view.state === 'loading'} onClick={() => void view.load()}>
      {view.state === 'loading' ? 'Loading order reference…' : view.value ? 'Refresh inquiry order reference' : 'Read inquiry order reference'}
    </button>
    {view.state === 'loading' && <p role="status">Loading the retained inquiry reference…</p>}
    {view.state === 'error' && <div ref={view.summary} role="alert" tabIndex={-1}>The inquiry order reference is unavailable in this browser session.</div>}
    {view.value && <>
      <div ref={view.summary} tabIndex={-1}>{view.value.order ? <><p>Linked test order <code>{view.value.order.id}</code></p><p>This is the order reference retained with the inquiry. It does not confirm current payment, download access or usage rights.</p></> : <p>This inquiry has no linked test order.</p>}</div>
      <button className="button button-outline" type="button" onClick={view.hide}>Hide inquiry order reference</button>
    </>}
  </section>;
}
