import { useId, useRef, useState, type FormEvent } from 'react';
import { soundKitPost, SoundKitRequestError, stripeTestCheckoutUrl, type SoundKitOrderState } from '../lib/sound-kits';

interface Props { slug: string; title: string; priceLabel: string; navigate?: (url: string) => void }

function idempotencyKey(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return 'kit-' + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Stripe TEST purchase of one kit (BL-13). The browser sends only the licensee's name and an idempotency key; the price, archive
 * revision and license come from the server. A kit is bought on its own, never in the track cart.
 */
export function SoundKitBuy({ slug, title, priceLabel, navigate = url => window.location.assign(url) }: Props) {
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // One key per licensee name: a retry of the same name replays the same order, a changed name is a new request.
  const key = useRef<string | null>(null);
  const inputId = useId();
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    const licenseeName = name.trim();
    if (licenseeName === '') { setError('Enter the licensee\'s legal name.'); return; }
    setBusy(true); setError(null);
    key.current ??= idempotencyKey();
    try {
      const order = await soundKitPost<{ orderId: string; orderUrl: string }>(`/sound-kits/${encodeURIComponent(slug)}/orders`, { licenseeName, idempotencyKey: key.current });
      const state = await soundKitPost<SoundKitOrderState>(`/sound-kit-orders/${order.orderId}/checkout`);
      navigate(stripeTestCheckoutUrl(state.checkoutUrl) ? state.checkoutUrl : order.orderUrl);
    } catch (caught) {
      setError(caught instanceof SoundKitRequestError ? caught.message : 'Test checkout is unavailable right now. No payment has been taken.');
      setBusy(false);
    }
  }
  return <section className="sound-kit-buy" aria-labelledby="sound-kit-buy-title">
    <h2 id="sound-kit-buy-title">Buy this kit (test mode)</h2>
    <p className="fine-print">Stripe test mode only: no real payment is taken. You pay {priceLabel} for {title} under the sound-kit license, issued in the licensee's name.</p>
    <form onSubmit={submit} noValidate>
      <label htmlFor={inputId}>Licensee legal name</label>
      <input id={inputId} name="licenseeName" autoComplete="name" maxLength={255} value={name} onChange={event => { setName(event.target.value); key.current = null; }} aria-describedby={error ? inputId + '-error' : undefined} />
      <button type="submit" className="button" disabled={busy}>{busy ? 'Starting test checkout…' : 'Buy with Stripe test checkout'}</button>
    </form>
    {error && <p id={inputId + '-error'} className="sound-kit-notice" role="alert">{error}</p>}
  </section>;
}
