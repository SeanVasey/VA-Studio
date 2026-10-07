export interface CreditBalance { available: number; reserved: number; consumed: number; expired: number }
export interface MembershipHistory {
  bucket_id: number; plan_version_id: number; unit: string; expires_at: string | null; last_event_id: number;
  balance: CreditBalance; spendable_credits: number; test_only: true;
  plan: { version_id: number; number: number; title: string; policy: { schema_version: 1; unit: string; allowance: number; validity_seconds: number | null; rollover: 'none'; reversal_allowed: boolean } };
  events: Array<{ id: number; sequence: number; kind: 'grant' | 'reserve' | 'consume' | 'release' | 'reverse' | 'expire'; amount: number; created_at: string; balance: CreditBalance }>;
}
type Result<T> = { kind: 'loaded'; history: T } | { kind: 'unavailable' | 'reload' };
const record = (v: unknown): v is Record<string, unknown> => !!v && typeof v === 'object' && !Array.isArray(v);
const keys = (v: Record<string, unknown>, names: string[]) => Object.keys(v).length === names.length && names.every(n => Object.hasOwn(v, n));
const integer = (v: unknown, min = 0, max = Number.MAX_SAFE_INTEGER): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= min && v <= max;
const date = (v: unknown): v is string => typeof v === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(v) && Number.isFinite(Date.parse(v.replace(' ', 'T') + 'Z')) && new Date(v.replace(' ', 'T') + 'Z').toISOString().slice(0, 19).replace('T', ' ') === v;
const unit = (v: unknown): v is string => typeof v === 'string' && /^[a-z][a-z0-9_]{0,31}$/.test(v);
const balance = (v: unknown): v is CreditBalance => record(v) && keys(v, ['available', 'reserved', 'consumed', 'expired']) && Object.values(v).every(n => integer(n, 0, 1_000_000));
export function validMembershipBuckets(v: unknown): v is { test_only: true; bucket_ids: number[] } {
  return record(v) && keys(v, ['test_only', 'bucket_ids']) && v.test_only === true && Array.isArray(v.bucket_ids) && v.bucket_ids.length <= 100
    && v.bucket_ids.every(n => integer(n, 1)) && v.bucket_ids.every((n, i, ids) => i === 0 || n > ids[i - 1]);
}
export function validMembershipHistory(v: unknown): v is MembershipHistory {
  if (!record(v) || !keys(v, ['bucket_id', 'plan_version_id', 'unit', 'expires_at', 'last_event_id', 'balance', 'spendable_credits', 'test_only', 'plan', 'events'])
    || v.test_only !== true || !integer(v.bucket_id, 1) || !integer(v.plan_version_id, 1) || !integer(v.last_event_id, 1) || !unit(v.unit)
    || (v.expires_at !== null && !date(v.expires_at)) || !balance(v.balance) || !integer(v.spendable_credits, 0, v.balance.available)
    || !record(v.plan) || !keys(v.plan, ['version_id', 'number', 'title', 'policy']) || v.plan.version_id !== v.plan_version_id || !integer(v.plan.number, 1)
    || typeof v.plan.title !== 'string' || v.plan.title.trim() !== v.plan.title || [...v.plan.title].length < 1 || [...v.plan.title].length > 180 || /[\x00-\x1f\x7f\ud800-\udfff]/u.test(v.plan.title)
    || !record(v.plan.policy) || !keys(v.plan.policy, ['schema_version', 'unit', 'allowance', 'validity_seconds', 'rollover', 'reversal_allowed'])
    || v.plan.policy.schema_version !== 1 || v.plan.policy.unit !== v.unit || !integer(v.plan.policy.allowance, 1, 1_000_000)
    || (v.plan.policy.validity_seconds !== null && !integer(v.plan.policy.validity_seconds, 1, 31_536_000)) || v.plan.policy.rollover !== 'none' || typeof v.plan.policy.reversal_allowed !== 'boolean'
    || !Array.isArray(v.events) || v.events.length < 1 || v.events.length > 10_000) return false;
  let prior = 0;
  for (const [index, event] of v.events.entries()) {
    if (!record(event) || !keys(event, ['id', 'sequence', 'kind', 'amount', 'created_at', 'balance']) || !integer(event.id, prior + 1) || event.sequence !== index + 1
      || !['grant', 'reserve', 'consume', 'release', 'reverse', 'expire'].includes(String(event.kind)) || !integer(event.amount, 1, 1_000_000) || !date(event.created_at) || !balance(event.balance)) return false;
    prior = event.id;
  }
  const last = v.events[v.events.length - 1];
  const currentBalance = v.balance;
  return last.id === v.last_event_id && ['available', 'reserved', 'consumed', 'expired'].every(k => last.balance[k] === currentBalance[k as keyof CreditBalance]);
}

/** Private responses are bounded before decoding, including chunked responses. */
async function read<T>(path: string, signal: AbortSignal, validate: (v: unknown) => v is T, maxBytes: number): Promise<Result<T>> {
  try {
    const response = await fetch(path, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store', redirect: 'error', signal });
    if (signal.aborted || response.redirected) return { kind: 'unavailable' };
    if ([401, 403, 419].includes(response.status)) return { kind: 'reload' };
    if (response.status !== 200 || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json' || !response.body) return { kind: 'unavailable' };
    const reader = response.body.getReader(), chunks: Uint8Array[] = []; let size = 0;
    const cancel = () => { void reader.cancel().catch(() => {}); };
    signal.addEventListener('abort', cancel, { once: true });
    try {
      while (true) {
        if (signal.aborted) throw new Error('Cancelled');
        const part = await reader.read(); if (part.done) break;
        size += part.value.byteLength; if (size > maxBytes) throw new Error('Too large'); chunks.push(part.value);
      }
      if (signal.aborted) throw new Error('Cancelled');
      const bytes = new Uint8Array(size); let offset = 0;
      for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
      const body: unknown = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
      return record(body) && keys(body, ['history']) && validate(body.history) ? { kind: 'loaded', history: body.history } : { kind: 'unavailable' };
    } finally { signal.removeEventListener('abort', cancel); cancel(); reader.releaseLock(); }
  } catch { return { kind: 'unavailable' }; }
}
export const readMembershipBuckets = (signal: AbortSignal) => read('/account/membership-credits', signal, validMembershipBuckets, 4096);
export async function readMembershipHistory(id: number, signal: AbortSignal): Promise<Result<MembershipHistory>> {
  if (!integer(id, 1)) return { kind: 'unavailable' };
  const result = await read(`/account/membership-credits/${id}`, signal, validMembershipHistory, 8 * 1024 * 1024);
  return result.kind === 'loaded' && result.history.bucket_id !== id ? { kind: 'unavailable' } : result;
}
