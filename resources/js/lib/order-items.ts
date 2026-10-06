import { deliveryJson } from './test-delivery';

interface Amounts { discountMinor: number; taxBasisMinor: number; taxMinor: number; totalMinor: number }
export interface OrderItem extends Amounts {
  position: number; title: string; licenseName: string; licenseVersion: number; quantity: 1; baseMinor: number;
}
export interface RetainedOrderItems extends Amounts {
  orderItemsSchema: 1; orderId: string; testOnly: true; currency: 'USD'; subtotalMinor: number; lines: OrderItem[];
}
type Result = { kind: 'loaded'; items: RetainedOrderItems } | { kind: 'unavailable' | 'reload'; message: string };
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, expected: string[]) => Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));
const uuid = (value: string) => value.length === 36 && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const money = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) >= 0;
const text = (value: unknown): value is string => typeof value === 'string' && value.trim().length > 0 && value.length <= 512;
const amountKeys = ['discountMinor', 'taxBasisMinor', 'taxMinor', 'totalMinor'];

export function validOrderItems(value: unknown, orderId: string, expectedTotalMinor: number): value is RetainedOrderItems {
  if (!record(value) || !keys(value, ['orderItemsSchema', 'orderId', 'testOnly', 'currency', 'subtotalMinor', ...amountKeys, 'lines'])
    || value.orderItemsSchema !== 1 || value.orderId !== orderId || !uuid(orderId) || value.testOnly !== true || value.currency !== 'USD'
    || !money(expectedTotalMinor) || value.totalMinor !== expectedTotalMinor || !money(value.subtotalMinor)
    || !amountKeys.every(key => money(value[key])) || !Array.isArray(value.lines) || value.lines.length < 1 || value.lines.length > 10) return false;
  for (const [position, line] of value.lines.entries()) {
    if (!record(line) || !keys(line, ['position', 'title', 'licenseName', 'licenseVersion', 'quantity', 'baseMinor', ...amountKeys])
      || line.position !== position || !text(line.title) || !text(line.licenseName) || !Number.isSafeInteger(line.licenseVersion)
      || Number(line.licenseVersion) < 1 || line.quantity !== 1 || !money(line.baseMinor) || !amountKeys.every(key => money(line[key]))) return false;
  }
  return true;
}

/** Render only verified retained amounts; no current catalog lookup or client pricing calculation. */
export async function readOrderItems(orderId: string, expectedTotalMinor: number, signal: AbortSignal): Promise<Result> {
  const unavailable = { kind: 'unavailable' as const, message: 'Original test-order items are unavailable. Reload the order status or try again.' };
  if (!uuid(orderId) || !money(expectedTotalMinor) || signal.aborted) return unavailable;
  try {
    const response = await fetch(`/orders/${orderId}/items`, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal, headers: { Accept: 'application/json' } });
    if (response.redirected || signal.aborted) return unavailable;
    if ([401, 403, 419].includes(response.status)) return { kind: 'reload', message: 'Your access could not be confirmed. Open a fresh sign-in page before viewing order items.' };
    if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return unavailable;
    const body = await deliveryJson(response);
    if (signal.aborted || !record(body) || !keys(body, ['items']) || !validOrderItems(body.items, orderId, expectedTotalMinor)) return unavailable;
    return { kind: 'loaded', items: body.items };
  } catch { return unavailable; }
}
