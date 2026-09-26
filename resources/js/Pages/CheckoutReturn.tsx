import { TestCheckout } from '../components/TestCheckout';

export default function CheckoutReturn({ orderId }: { orderId: string }) {
  return <main className="section-pad">
    <p className="eyebrow">VASEY.AUDIO / TEST CHECKOUT</p>
    <h1>CHECKOUT STATUS</h1>
    <p>Returning from Stripe does not verify a payment. Use the status check below to reconcile this test checkout.</p>
    <TestCheckout orderId={orderId} />
    <a className="text-link" href="/">Return to the catalog</a>
  </main>;
}
