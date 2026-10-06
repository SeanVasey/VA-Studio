export interface InquirySummary { receipt: string; subject: string; state: 'new' | 'read' | 'archived'; createdAt: string }
export interface InquiryHistoryPage { inquiryHistorySchema: 1; inquiries: InquirySummary[]; limit: 20; nextCursor: string | null }
type Result = { kind: 'loaded'; history: InquiryHistoryPage } | { kind: 'unavailable' | 'reload' };
// 20 subjects × 160 Unicode code points × 12 JSON-escaped bytes, plus the bounded envelope.
export const INQUIRY_HISTORY_MAX_BYTES = 64 * 1024;
const uuid = (value: unknown): value is string => typeof value === 'string' && value.length === 36 && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, names: string[]) => Object.keys(value).length === names.length && names.every(name => Object.hasOwn(value, name));
const date = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(value)
  && Number.isFinite(Date.parse(value)) && new Date(value).toISOString() === value.replace('Z', '.000Z');
const subject = (value: unknown): value is string => typeof value === 'string' && value.trim() !== '' && value.length <= 320
  && [...value].length <= 160 && !/[\x00-\x1f\x7f]/.test(value)
  && [...value].every(character => { const point = character.codePointAt(0)!; return point < 0xd800 || point > 0xdfff; });

export function validInquiryHistory(value: unknown): value is InquiryHistoryPage {
  if (!record(value) || !keys(value, ['inquiryHistorySchema', 'inquiries', 'limit', 'nextCursor']) || value.inquiryHistorySchema !== 1
    || value.limit !== 20 || !Array.isArray(value.inquiries) || value.inquiries.length > 20 || (value.nextCursor !== null && !uuid(value.nextCursor))) return false;
  const receipts = new Set<string>();
  for (const row of value.inquiries) {
    if (!record(row) || !keys(row, ['receipt', 'subject', 'state', 'createdAt']) || !uuid(row.receipt) || receipts.has(row.receipt)
      || !subject(row.subject) || !['new', 'read', 'archived'].includes(row.state as string) || !date(row.createdAt)) return false;
    receipts.add(row.receipt);
  }
  return value.nextCursor === null || (value.inquiries.length === 20 && value.nextCursor === value.inquiries[19].receipt);
}

async function boundedJson(response: Response, signal: AbortSignal): Promise<unknown> {
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
      if (size > INQUIRY_HISTORY_MAX_BYTES) throw new Error('History too large');
      chunks.push(result.value);
    }
    if (signal.aborted) throw new Error('History cancelled');
    const bytes = new Uint8Array(size); let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
}

export async function readInquiryHistory(before: string | null, signal: AbortSignal): Promise<Result> {
  if (before !== null && !uuid(before)) return { kind: 'unavailable' };
  try {
    const response = await fetch(`/contact/inquiries/history${before === null ? '' : `/before/${before}`}`, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' }, signal,
    });
    if (signal.aborted || response.redirected) return { kind: 'unavailable' };
    if ([401, 403, 419].includes(response.status)) return { kind: 'reload' };
    if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return { kind: 'unavailable' };
    const body = await boundedJson(response, signal);
    if (!record(body) || !keys(body, ['history']) || !validInquiryHistory(body.history)) return { kind: 'unavailable' };
    return { kind: 'loaded', history: body.history };
  } catch { return { kind: 'unavailable' }; }
}
