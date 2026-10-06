export interface OrderInquirySetup { orderInquirySchema: 1; orderId: string; testOnly: true; privacyNotice: string; noticeToken: string }
export interface OrderInquiryContext { orderInquiryContextSchema: 1; order: null | { id: string; testOnly: true } }
type Result<T> = { kind: 'loaded'; value: T } | { kind: 'unavailable' };
// Laravel's JSON encoding can use 12 ASCII bytes per astral code point:
// 3000 notice code points need 36000 bytes, plus the fixed versioned envelope.
export const ORDER_INQUIRY_MAX_BYTES = 40 * 1024;
export const inquiryLocator = (value: string) => value.length === 36 && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, names: string[]) => Object.keys(value).length === names.length && names.every(name => Object.hasOwn(value, name));

export function validOrderInquirySetup(value: unknown, orderId: string): value is OrderInquirySetup {
  return inquiryLocator(orderId) && object(value) && keys(value, ['orderInquirySchema', 'orderId', 'testOnly', 'privacyNotice', 'noticeToken'])
    && value.orderInquirySchema === 1 && value.orderId === orderId && value.testOnly === true
    && typeof value.privacyNotice === 'string' && value.privacyNotice.trim().length > 0 && [...value.privacyNotice].length <= 3000
    && !/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F\uD800-\uDFFF]/u.test(value.privacyNotice)
    && typeof value.noticeToken === 'string' && value.noticeToken.length === 64 && /^[a-f0-9]{64}$/.test(value.noticeToken);
}

export function validOrderInquiryContext(value: unknown): value is OrderInquiryContext {
  return object(value) && keys(value, ['orderInquiryContextSchema', 'order']) && value.orderInquiryContextSchema === 1
    && (value.order === null || (object(value.order) && keys(value.order, ['id', 'testOnly'])
      && typeof value.order.id === 'string' && inquiryLocator(value.order.id) && value.order.testOnly === true));
}

export async function privateInquiryJson(response: Response, signal: AbortSignal): Promise<unknown> {
  const reader = response.body?.getReader();
  if (!reader) throw new Error('Unavailable');
  const cancel = () => { void reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  const chunks: Uint8Array[] = []; let size = 0;
  try {
    while (true) {
      if (signal.aborted) throw new Error('Cancelled');
      const result = await reader.read();
      if (result.done) break;
      size += result.value.byteLength;
      if (size > ORDER_INQUIRY_MAX_BYTES) throw new Error('Too large');
      chunks.push(result.value);
    }
    if (signal.aborted) throw new Error('Cancelled');
    const bytes = new Uint8Array(size); let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}

async function read<T>(path: string, key: string, validate: (value: unknown) => value is T, signal: AbortSignal): Promise<Result<T>> {
  const unavailable = { kind: 'unavailable' as const };
  if (signal.aborted) return unavailable;
  try {
    const response = await fetch(path, { method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal, headers: { Accept: 'application/json' } });
    if (signal.aborted || response.redirected || response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return unavailable;
    const body = await privateInquiryJson(response, signal);
    if (signal.aborted || !object(body) || !keys(body, [key]) || !validate(body[key])) return unavailable;
    return { kind: 'loaded', value: body[key] };
  } catch { return unavailable; }
}

export async function readOrderInquirySetup(orderId: string, signal: AbortSignal): Promise<Result<OrderInquirySetup>> {
  if (!inquiryLocator(orderId)) return { kind: 'unavailable' };
  return read(`/contact/inquiries/for-order/${orderId}`, 'orderInquiry', (value): value is OrderInquirySetup => validOrderInquirySetup(value, orderId), signal);
}

export async function readOrderInquiryContext(receipt: string, signal: AbortSignal): Promise<Result<OrderInquiryContext>> {
  if (!inquiryLocator(receipt)) return { kind: 'unavailable' };
  return read(`/contact/inquiries/${receipt}/order-context`, 'context', validOrderInquiryContext, signal);
}
