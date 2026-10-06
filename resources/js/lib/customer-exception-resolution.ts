export interface RetainedExceptionResolution {
  exceptionResolutionSchema: 1; orderId: string; testOnly: true;
  record: null | { kind: 'full_refund_verified_resources_released'; observedAt: string; releasedAt: string };
}
type Result = { kind: 'loaded'; resolution: RetainedExceptionResolution } | { kind: 'unavailable' | 'reload'; message: string };
export const RESOLUTION_MAX_BYTES = 4096;
export const RESOLUTION_UNAVAILABLE = 'Recorded test-order resolution is unavailable. Reload the order status or try again.';
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, expected: string[]) => Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));
const uuid = (value: string) => value.length === 36 && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const timestamp = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)
  && Number.isFinite(Date.parse(value)) && new Date(value).toISOString() === value.replace('Z', '.000Z');

export function validExceptionResolution(value: unknown, orderId: string): value is RetainedExceptionResolution {
  if (!uuid(orderId) || !record(value) || !keys(value, ['exceptionResolutionSchema', 'orderId', 'testOnly', 'record'])
    || value.exceptionResolutionSchema !== 1 || value.orderId !== orderId || value.testOnly !== true) return false;
  return value.record === null || (record(value.record) && keys(value.record, ['kind', 'observedAt', 'releasedAt'])
    && value.record.kind === 'full_refund_verified_resources_released' && timestamp(value.record.observedAt)
    && timestamp(value.record.releasedAt) && value.record.observedAt <= value.record.releasedAt);
}

/** Fixed schema needs well under 1 KiB; bound bytes before decoding even without Content-Length. */
async function resolutionJson(response: Response, signal: AbortSignal): Promise<unknown> {
  const reader = response.body?.getReader();
  if (!reader) throw new Error('Resolution unavailable');
  const cancel = () => { void reader.cancel().catch(() => {}); };
  signal.addEventListener('abort', cancel, { once: true });
  const chunks: Uint8Array[] = []; let size = 0;
  try {
    while (true) {
      if (signal.aborted) throw new Error('Resolution cancelled');
      const result = await reader.read();
      if (result.done) break;
      size += result.value.byteLength;
      if (size > RESOLUTION_MAX_BYTES) throw new Error('Resolution too large');
      chunks.push(result.value);
    }
    if (signal.aborted) throw new Error('Resolution cancelled');
    const bytes = new Uint8Array(size); let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}

export async function readExceptionResolution(orderId: string, signal: AbortSignal): Promise<Result> {
  const unavailable = { kind: 'unavailable' as const, message: RESOLUTION_UNAVAILABLE };
  if (!uuid(orderId) || signal.aborted) return unavailable;
  try {
    const response = await fetch(`/orders/${orderId}/exception-resolution`, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal, headers: { Accept: 'application/json' },
    });
    if (response.redirected || signal.aborted) return unavailable;
    if ([401, 403, 419].includes(response.status)) return { kind: 'reload', message: 'Your access could not be confirmed. Open a fresh sign-in page before viewing the recorded resolution.' };
    if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return unavailable;
    const body = await resolutionJson(response, signal);
    if (signal.aborted || !record(body) || !keys(body, ['resolution']) || !validExceptionResolution(body.resolution, orderId)) return unavailable;
    return { kind: 'loaded', resolution: body.resolution };
  } catch { return unavailable; }
}
