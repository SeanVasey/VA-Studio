import { TestCheckout } from '../components/TestCheckout';

export default function CheckoutReturn({ orderId }: { orderId: string }) {
  return <main className="section-pad">
    <p className="eyebrow">VASEY.AUDIO / TEST CHECKOUT</p>
    <h1>CHECKOUT STATUS</h1>
    <p>Returning from Stripe does not verify a payment. The status below comes from the saved test order. Refresh it to check for updates.</p>
    <TestCheckout orderId={orderId} />
    <a className="text-link" href="/">Return to the catalog</a>
  </main>;
}
