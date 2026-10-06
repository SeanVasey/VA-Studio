import { readOrderInquirySetup, type OrderInquirySetup } from '../lib/order-inquiry';
import { usePrivateInquiryRead } from '../lib/usePrivateInquiryRead';
import { ContactInquiryForm } from './ContactInquiryForm';

export function OrderInquiry({ orderId }: { orderId: string }) { return <Inquiry key={orderId} orderId={orderId} />; }

function Inquiry({ orderId }: { orderId: string }) {
  const view = usePrivateInquiryRead<OrderInquirySetup>(signal => readOrderInquirySetup(orderId, signal));
  return <section className="contact-inquiry" aria-label="Test order inquiry">
    <h3>Questions about this test order</h3>
    <p>Check whether a private inquiry is available for this order. Replies stay in the browser session that sends it; no email is sent.</p>
    <button ref={view.trigger} type="button" className="button button-outline" disabled={view.state === 'loading'} onClick={() => void view.load()} hidden={view.value !== null}>
      {view.state === 'loading' ? 'Checking order inquiry…' : 'Ask about this test order'}
    </button>
    {view.state === 'loading' && <p role="status">Checking private inquiry availability…</p>}
    {view.state === 'error' && <div ref={view.summary} role="alert" tabIndex={-1}>An inquiry for this order is unavailable. Keep using your original session and try again, or visit <a href="/contact">contact</a>.</div>}
    {view.value && <>
      <div ref={view.summary} tabIndex={-1}><p>Linked test order <code>{view.value.orderId}</code></p><p>This inquiry does not change payment, downloads or usage rights.</p></div>
      <ContactInquiryForm privacyNotice={view.value.privacyNotice} noticeToken={view.value.noticeToken} orderId={view.value.orderId} />
      <p className="contact-inquiry-note">Copy any draft or unconfirmed message you need before closing. It stays only in this view.</p>
      <button type="button" className="button button-outline" onClick={view.hide}>Close order inquiry</button>
    </>}
  </section>;
}

