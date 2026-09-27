export const deliveryRoles = {
  contract: { label: 'Original contract', extension: 'pdf', mime: 'application/pdf' },
  master_wav: { label: 'Master WAV', extension: 'wav', mime: 'audio/wav' },
  download_mp3: { label: 'MP3', extension: 'mp3', mime: 'audio/mpeg' },
  stems_zip: { label: 'Stems ZIP', extension: 'zip', mime: 'application/zip' },
} as const;
export type DeliveryKind = keyof typeof deliveryRoles;
export interface DeliveryItem { grantId: string; kind: DeliveryKind; filename: string; mimeType: string; sizeBytes: number }
export interface DeliveryHistory {
  authorizationId: string; grantId: string; kind: DeliveryKind; issuedAt: string; expiresAt: string;
  status: 'unused' | 'attempted' | 'expired'; attemptedAt: string | null;
}
export interface DeliveryListing {
  deliverySchema: 1; orderId: string; testOnly: true; status: 'available' | 'unavailable';
  items: DeliveryItem[]; history: DeliveryHistory[]; historyLimit: 20; historyHasMore: boolean;
}
interface Authorization { authorizationId: string; token: string; expiresAt: string; filename: string; mimeType: string }
const uuid = (value: unknown): value is string => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const record = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const keys = (value: Record<string, unknown>, expected: string[]) => Object.keys(value).length === expected.length && expected.every(key => Object.hasOwn(value, key));
const kind = (value: unknown): value is DeliveryKind => typeof value === 'string' && Object.hasOwn(deliveryRoles, value);
const utc = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)
  && Number.isFinite(Date.parse(value)) && new Date(value).toISOString() === value.replace('Z', '.000Z');
const descriptor = (value: Record<string, unknown>, grantId: string, role: DeliveryKind) => value.filename === `${grantId}-${role}.${deliveryRoles[role].extension}` && value.mimeType === deliveryRoles[role].mime;

export function validDelivery(value: unknown, orderId: string): value is DeliveryListing {
  if (!record(value) || !keys(value, ['deliverySchema', 'orderId', 'testOnly', 'status', 'items', 'history', 'historyLimit', 'historyHasMore'])
    || value.deliverySchema !== 1 || value.orderId !== orderId || !uuid(value.orderId) || value.testOnly !== true
    || !['available', 'unavailable'].includes(value.status as string) || value.historyLimit !== 20 || typeof value.historyHasMore !== 'boolean'
    || !Array.isArray(value.items) || value.items.length > 400 || !Array.isArray(value.history) || value.history.length > 20
    || (value.historyHasMore && value.history.length !== 20)) return false;
  const selectors = new Set<string>();
  for (const item of value.items) {
    if (!record(item) || !keys(item, ['grantId', 'kind', 'filename', 'mimeType', 'sizeBytes']) || !uuid(item.grantId) || !kind(item.kind)
      || !descriptor(item, item.grantId, item.kind) || !Number.isSafeInteger(item.sizeBytes) || (item.sizeBytes as number) <= 0
      || (item.sizeBytes as number) > (item.kind === 'contract' ? 16 * 1024 * 1024 : 1024 * 1024 * 1024)) return false;
    const selector = `${item.grantId}:${item.kind}`;
    if (selectors.has(selector)) return false;
    selectors.add(selector);
  }
  const authorizations = new Set<string>();
  for (const entry of value.history) {
    if (!record(entry) || !keys(entry, ['authorizationId', 'grantId', 'kind', 'issuedAt', 'expiresAt', 'status', 'attemptedAt'])
      || !uuid(entry.authorizationId) || authorizations.has(entry.authorizationId) || !uuid(entry.grantId) || !kind(entry.kind)
      || !utc(entry.issuedAt) || !utc(entry.expiresAt) || Date.parse(entry.expiresAt) - Date.parse(entry.issuedAt) !== 60_000
      || !['unused', 'attempted', 'expired'].includes(entry.status as string)) return false;
    if (entry.status === 'attempted' ? !utc(entry.attemptedAt) || Date.parse(entry.attemptedAt) < Date.parse(entry.issuedAt) || Date.parse(entry.attemptedAt) >= Date.parse(entry.expiresAt) : entry.attemptedAt !== null) return false;
    authorizations.add(entry.authorizationId);
  }
  return true;
}

export function validAuthorization(value: unknown, item: DeliveryItem, now = Date.now()): value is Authorization {
  return record(value) && keys(value, ['authorizationId', 'token', 'expiresAt', 'filename', 'mimeType'])
    && uuid(value.authorizationId) && typeof value.token === 'string' && /^[A-Za-z0-9_-]{43}$/.test(value.token)
    && utc(value.expiresAt) && Date.parse(value.expiresAt) > now && Date.parse(value.expiresAt) <= now + 65_000
    && descriptor(value, item.grantId, item.kind);
}

/** Bound both the bytes read and the parsed shape; never display a response body. */
export async function deliveryJson(response: Response): Promise<unknown> {
  const reader = response.body?.getReader();
  if (!reader) throw new Error('Delivery response unavailable');
  const chunks: Uint8Array[] = []; let size = 0;
  try {
    while (true) {
      const result = await reader.read();
      if (result.done) break;
      size += result.value.byteLength;
      if (size > 128 * 1024) throw new Error('Delivery response too large');
      chunks.push(result.value);
    }
    const bytes = new Uint8Array(size); let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    return JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } finally { await reader.cancel().catch(() => {}); reader.releaseLock(); }
}

const failures = {
  DELIVERY_NOT_FOUND: [404, 'Test downloads are unavailable for this session.'],
  INVALID_DELIVERY_REQUEST: [422, 'This download request could not be accepted. Refresh the available items.'],
  DELIVERY_ALREADY_ISSUED: [409, 'That authorization was already issued. Its secret cannot be recovered. Check recent attempts before deliberately requesting a new authorization.'],
  DELIVERY_CONFLICT: [409, 'This download request conflicts with a saved request. Refresh the available items before requesting a new authorization.'],
  DELIVERY_EXPIRED: [410, 'The download authorization expired. Check recent attempts before requesting a new authorization.'],
  DELIVERY_ATTEMPTED: [409, 'This authorization already has a stream attempt. An interrupted attempt can be consumed even if no file was received.'],
  DELIVERY_RATE_LIMITED: [429, 'The temporary test download limit has been reached. Wait before requesting another authorization.'],
  DELIVERY_UNAVAILABLE: [503, 'Test downloads are temporarily unavailable. Refresh their status before trying again.'],
  SESSION_EXPIRED: [419, 'Your session expired. Reload this page before requesting another download.'],
} as const;
export function deliveryFailure(value: unknown, status?: number): string | null {
  if (!record(value) || typeof value.code !== 'string' || !Object.hasOwn(failures, value.code)) return null;
  const [expected, message] = failures[value.code as keyof typeof failures];
  return status === undefined || status === expected ? message : null;
}
