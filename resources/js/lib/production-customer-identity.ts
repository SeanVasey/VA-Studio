export type IdentityProof = { id: string; proof: string };
let captured: IdentityProof | null = null;

/** Root calls before createInertiaApp/history handling. A bearer proof never enters query URLs or page props. */
export function captureProductionIdentityProof() {
  captured = null;
  if (window.location.pathname !== '/customer/access') return;
  const fragment = window.location.hash;
  window.history.replaceState(null, '', '/customer/access');
  const match = /^#(?:enroll|recover)\.([a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\.([a-f0-9]{64})$/.exec(fragment);
  if (match) captured = { id: match[1], proof: match[2] };
}
export function takeProductionIdentityProof() { const value = captured; captured = null; return value; }
export function identityRequestKey() { return Array.from(crypto.getRandomValues(new Uint8Array(32)), value => value.toString(16).padStart(2, '0')).join(''); }
export type IdentityAction = 'request' | 'complete' | 'sign-in' | 'sign-out';
export type IdentityResult = 'saved' | 'invalid' | 'uncertain' | 'expired';
export async function productionIdentityRequest(action: IdentityAction, body: string, signal: AbortSignal): Promise<IdentityResult> {
  try {
    const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('XSRF-TOKEN='));
    const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
    if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice(11));
    else { const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content; if (csrf) headers['X-CSRF-TOKEN'] = csrf; }
    const endpoint = action === 'request' || action === 'complete' ? '/customer/identity/' + action : '/customer/' + action;
    const pending = fetch(endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers, body, signal });
    body = ''; // The helper does not retain a second private body while awaiting the network.
    const response = await pending;
    if (response.redirected) return 'uncertain';
    if (response.status === 419) return 'expired';
    if (response.status === 422) return 'invalid';
    if (response.status !== (action === 'request' ? 202 : 200)) return 'uncertain';
    const text = await response.text(); if (text.length > 4096) return 'uncertain';
    const value = JSON.parse(text), keys = Object.keys(value ?? {}).sort().join(',');
    const strings = text.match(/"(?:[^"\\]|\\.)*"/g)?.length;
    if (action === 'request') return keys === 'accepted' && strings === 1 && value.accepted === true ? 'saved' : 'uncertain';
    if (action === 'complete') return keys === 'completed,next' && strings === 3 && value.completed === true && value.next === '/customer/sign-in' ? 'saved' : 'uncertain';
    return keys === 'authenticated,next' && strings === 3 && value.authenticated === (action === 'sign-in') && value.next === (action === 'sign-in' ? '/customer' : '/customer/sign-in') ? 'saved' : 'uncertain';
  } catch { return 'uncertain'; }
}
