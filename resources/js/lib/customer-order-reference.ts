import type { OrderSummary } from '../components/OrderPreparation';
import { validOrderSummary } from '../components/OwnedTestOrderHistory';
import { deliveryJson } from './test-delivery';

type LookupResult = { kind: 'found'; order: OrderSummary } | { kind: 'invalid' | 'not_found' | 'reload' | 'unavailable'; message: string };
const uuid = (value: unknown): value is string => typeof value === 'string' && value.length === 36
  && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, expected: string[]) => Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));

export function normalizeOrderReference(value: string): string | null {
  const reference = value.trim().toLowerCase();
  return uuid(reference) ? reference : null;
}

/** Read one owned locator; the server remains the authority for identity and all commerce states. */
export async function lookupCustomerOrder(value: string, signal: AbortSignal): Promise<LookupResult> {
  const reference = normalizeOrderReference(value);
  if (reference === null) return { kind: 'invalid', message: 'Enter a complete order reference from your account order history.' };
  const unavailable = { kind: 'unavailable' as const, message: 'This order could not be loaded. Try again when your connection is available.' };
  try {
    const response = await fetch(`/orders/${reference}/status`, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
      headers: { Accept: 'application/json' },
    });
    if (response.redirected) return unavailable;
    if (response.status === 404) return { kind: 'not_found', message: 'This order is not available to your account. Check the reference or browse your account orders.' };
    if ([401, 403, 419].includes(response.status)) return { kind: 'reload', message: 'Your account access could not be confirmed. Sign in again before looking up an order.' };
    if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return unavailable;
    // Reuse the existing bounded private-response reader; no body text is reflected on failure.
    const body = await deliveryJson(response);
    if (!record(body) || !keys(body, ['order']) || !record(body.order)) return unavailable;
    const { orderSchema, quoteId, pricingId, reviewHash, ...summary } = body.order;
    if (orderSchema !== 1 || !uuid(quoteId) || !uuid(pricingId) || typeof reviewHash !== 'string'
      || reviewHash.length !== 64 || !/^[a-f0-9]{64}$/.test(reviewHash)
      || !validOrderSummary(summary) || summary.id !== reference) return unavailable;
    // Keep only the existing minimal history summary in component memory.
    return { kind: 'found', order: summary };
  } catch { return unavailable; }
}
