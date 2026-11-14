import type { AlbumCover } from './albums';

/** A listed sound kit. Counts come from the verified manifest; no file names, hashes, storage paths or download target are ever sent. */
export interface SoundKitSummary {
  slug: string;
  path: string;
  title: string;
  memberCount: number;
  totalDurationSeconds: number;
  /** Staff-entered price in integer minor units, with its explicit currency. */
  priceMinor: number;
  currency: string;
  /** True only where the Stripe-test kit purchase lane is open (never production); otherwise the page shows a notice. */
  purchasable: boolean;
  /** The staff-approved cover art, or null (nothing is drawn). It is a public asset of its own, never part of the kit archive. */
  cover: AlbumCover | null;
}

/** The staff-approved demo: a tagged preview of the kit, served by an opaque address. Never the kit archive or its samples. */
export interface SoundKitDemo {
  url: string;
  durationSeconds: number;
  waveform: number[];
}

export interface SoundKitDetail extends SoundKitSummary {
  summary: string | null;
  demo: SoundKitDemo | null;
}

/** The owner's view of a Stripe-test kit order (BL-13). State names only: no licensee name, provider id or file path. */
export interface SoundKitOrderState {
  schema: 'sound-kit-test-order-v1';
  orderId: string;
  testOnly: true;
  kit: { title: string; slug: string };
  priceMinor: number;
  currency: string;
  status: 'not_started' | 'expired' | 'checkout_started' | 'paid' | 'awaiting_contract' | 'awaiting_delivery' | 'downloadable';
  checkoutUrl: string | null;
  deliverables: Array<'archive' | 'contract'>;
}

export class SoundKitRequestError extends Error {
  constructor(public readonly code: string, message: string) { super(message); }
}

function csrfHeaders(): Record<string, string> {
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}

/** POST JSON to a same-origin kit endpoint; refusals carry the server's stable code and plain message. */
export async function soundKitPost<T>(url: string, body: Record<string, unknown> = {}): Promise<T> {
  const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() }, body: JSON.stringify(body) });
  const data = await response.json().catch(() => null) as (T & { code?: string; message?: string }) | null;
  if (!response.ok || data === null) {
    throw new SoundKitRequestError(typeof data?.code === 'string' ? data.code : 'UNAVAILABLE',
      typeof data?.message === 'string' ? data.message : 'This request could not be completed.');
  }
  return data;
}

/** GET JSON from a same-origin kit endpoint. Read-only: the server answers from retained state and sends nothing to a provider. */
export async function soundKitGet<T>(url: string): Promise<T> {
  const response = await fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
  const data = await response.json().catch(() => null) as (T & { code?: string; message?: string }) | null;
  if (!response.ok || data === null) {
    throw new SoundKitRequestError(typeof data?.code === 'string' ? data.code : 'UNAVAILABLE',
      typeof data?.message === 'string' ? data.message : 'This request could not be completed.');
  }
  return data;
}

/** Only a Stripe test Checkout address is ever followed. */
export function stripeTestCheckoutUrl(value: unknown): value is string {
  if (typeof value !== 'string' || value.length > 4096 || /[\u0000-\u0020\u007f\\]/.test(value)) return false;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && url.hostname === 'checkout.stripe.com' && url.port === '' && url.username === ''
      && url.password === '' && url.pathname.startsWith('/c/pay/cs_test_');
  } catch { return false; }
}
