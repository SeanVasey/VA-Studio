export const purchaseReference = (value: string) => /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
export interface PendingPurchaseClaim { orderId: string; expiresAt: string; saved: boolean }

/** One deliberate request. No credentials, possession proof or account identity enters the browser payload. */
export async function savePurchase(action: 'stage' | 'complete', orderId: string, signal: AbortSignal): Promise<boolean> {
  if (!purchaseReference(orderId) || signal.aborted) return false;
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  try {
    const response = await fetch(`/account/purchase-claim/${action}`, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { 'X-CSRF-TOKEN': token } : {}) },
      body: JSON.stringify({ orderId }),
    });
    if (response.status !== 200 || response.redirected || response.headers.get('content-type')?.split(';')[0].trim().toLowerCase() !== 'application/json') return false;
    const reader = response.body?.getReader();
    if (!reader) return false;
    const chunks: Uint8Array[] = []; let size = 0;
    try {
      while (true) {
        const chunk = await reader.read();
        if (chunk.done) break;
        size += chunk.value.byteLength;
        if (size > 4096 || signal.aborted) { await reader.cancel(); return false; }
        chunks.push(chunk.value);
      }
    } finally { reader.releaseLock(); }
    const bytes = new Uint8Array(size); let offset = 0;
    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
    const body: unknown = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
    const flag = action === 'stage' ? 'staged' : 'saved';
    return !signal.aborted && !!body && typeof body === 'object' && !Array.isArray(body)
      && Object.keys(body).length === 2 && 'orderId' in body && body.orderId === orderId
      && flag in body && (body as Record<string, unknown>)[flag] === true;
  } catch { return false; }
}
