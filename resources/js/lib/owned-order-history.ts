import type { OrderSummary } from '../components/OrderPreparation';
import { validPaymentProgress, type PaymentProgress } from '../components/TestCheckout';

export interface OrderPreview {
  orderId: string; itemCount: number;
  firstItem: { title: string; licenseName: string; licenseVersion: number };
}
export interface OwnedOrderHistory {
  orderHistorySchema: 2; testOnly: true; orders: OrderSummary[]; previews: OrderPreview[]; limit: 20; nextCursor: string | null;
}
type HistoryResult = { kind: 'loaded'; history: OwnedOrderHistory } | { kind: 'unavailable' | 'reload' };
// Twenty pairs of 255-codepoint names can exceed 128 KiB when JSON escapes supplementary Unicode.
export const HISTORY_MAX_BYTES = 192 * 1024;
const uuid = (value: unknown): value is string => typeof value === 'string' && value.length === 36 && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, expected: string[]) => Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));
const text = (value: unknown): value is string => typeof value === 'string' && value.length > 0 && value.length <= 510
  && [...value].length <= 255 && [...value].every(character => { const point = character.codePointAt(0)!; return point < 0xd800 || point > 0xdfff; });

/** Shared status/lookup contract stays unchanged; history-only metadata is separate. */
export function validOrderSummary(order: unknown): order is OrderSummary {
  return record(order) && keys(order, ['id', 'createdAt', 'testOnly', 'payable', 'currency', 'totalMinor', 'status',
    'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus'])
    && uuid(order.id) && typeof order.createdAt === 'string' && Number.isFinite(Date.parse(order.createdAt))
    && order.testOnly === true && order.payable === false && order.currency === 'USD'
    && Number.isSafeInteger(order.totalMinor) && Number(order.totalMinor) >= 0 && validPaymentProgress(order as unknown as PaymentProgress)
    && order.status === (order.finalizationStatus === 'paid' || order.finalizationStatus === 'paid_exception' ? order.finalizationStatus : 'prepared');
}

export function validOrderHistory(value: unknown): value is OwnedOrderHistory {
  if (!record(value) || !keys(value, ['orderHistorySchema', 'testOnly', 'orders', 'previews', 'limit', 'nextCursor'])
    || value.orderHistorySchema !== 2 || value.testOnly !== true || value.limit !== 20
    || !Array.isArray(value.orders) || value.orders.length > 20 || !Array.isArray(value.previews) || value.previews.length !== value.orders.length
    || (value.nextCursor !== null && !uuid(value.nextCursor))) return false;
  const ids = new Set<string>();
  for (const [index, order] of value.orders.entries()) {
    const preview: unknown = value.previews[index];
    if (!validOrderSummary(order) || ids.has(order.id) || !record(preview) || !keys(preview, ['orderId', 'itemCount', 'firstItem'])
      || preview.orderId !== order.id || !Number.isSafeInteger(preview.itemCount) || Number(preview.itemCount) < 1 || Number(preview.itemCount) > 10
      || !record(preview.firstItem) || !keys(preview.firstItem, ['title', 'licenseName', 'licenseVersion'])
      || !text(preview.firstItem.title) || !text(preview.firstItem.licenseName)
      || !Number.isSafeInteger(preview.firstItem.licenseVersion) || Number(preview.firstItem.licenseVersion) < 1) return false;
    ids.add(order.id);
  }
  return value.nextCursor === null || (value.orders.length === 20 && value.nextCursor === value.orders[19].id);
}

/** Bound streamed private bytes before parsing, including responses without Content-Length. */
async function historyJson(response: Response, signal: AbortSignal): Promise<unknown> {
  const reader = response.body?.getReader();
  if (!reader) throw new Error('History unavailable');
  const cancel = () => { void reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  const chunks: Uint8Array[] = []; let size = 0;
  try {
    while (true) {
      if (signal.aborted) throw new Error('History cancelled');
      const result = await reader.read();
      if (result.done) break;
      size += result.value.byteLength;
      if (size > HISTORY_MAX_BYTES) throw new Error('History too large');
      chunks.push(result.value);
    }
    if (signal.aborted) throw new Error('History cancelled');
    const bytes = new Uint8Array(size); let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}

export async function readOwnedOrderHistory(before: string | null, signal: AbortSignal): Promise<HistoryResult> {
  if (before !== null && !uuid(before)) return { kind: 'unavailable' };
  try {
    const response = await fetch(`/orders/history${before === null ? '' : `?before=${before}`}`, {
      method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', redirect: 'error', signal,
    });
    if (signal.aborted || response.redirected) return { kind: 'unavailable' };
    if ([401, 403, 419].includes(response.status)) return { kind: 'reload' };
    if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return { kind: 'unavailable' };
    const body = await historyJson(response, signal);
    if (!record(body) || !keys(body, ['history']) || !validOrderHistory(body.history)) return { kind: 'unavailable' };
    return { kind: 'loaded', history: body.history };
  } catch { return { kind: 'unavailable' }; }
}
