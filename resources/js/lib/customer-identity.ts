export type IdentityPurpose = 'enroll' | 'recover';
export type IdentityProof = { purpose: IdentityPurpose; id: string; proof: string };
let captured: IdentityProof | null = null;

/** Called before createInertiaApp: fragments never enter request URLs, page props or saved Inertia history. */
export function captureCustomerIdentityProof() {
  captured = null;
  if (window.location.pathname !== '/account/access') return;
  const fragment = window.location.hash;
  window.history.replaceState(null, '', '/account/access');
  const match = /^#(enroll|recover)\.([a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\.([a-f0-9]{64})$/.exec(fragment);
  if (match) captured = { purpose: match[1] as IdentityPurpose, id: match[2], proof: match[3] };
}
export function currentCustomerIdentityProof() { return captured; }
export function clearCustomerIdentityProof() { captured = null; }

export type IdentityResult = 'saved' | 'invalid' | 'uncertain' | 'expired';
export async function identityRequest(action: 'request' | 'complete', body: string, signal: AbortSignal): Promise<IdentityResult> {
  try {
    const cookie = document.cookie.split(';').map(value => value.trim()).find(value => value.startsWith('XSRF-TOKEN='));
    const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
    if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie.slice(11));
    else {
      const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
      if (csrf) headers['X-CSRF-TOKEN'] = csrf;
    }
    const response = await fetch('/account/identity/' + action, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers, body, signal });
    if (response.redirected) return 'uncertain';
    if (response.status === 419) return 'expired';
    if (response.status === 422) return 'invalid';
    if (response.status !== (action === 'request' ? 202 : 200)) return 'uncertain';
    const text = await response.text();
    if (text.length > 4096) return 'uncertain';
    const value = JSON.parse(text);
    // JSON.parse erases duplicate object keys; count string tokens as well as the parsed envelope.
    const stringTokens = text.match(/"(?:[^"\\]|\\.)*"/g)?.length;
    return value && !Array.isArray(value) && stringTokens === (action === 'request' ? 1 : 3) && (action === 'request'
      ? Object.keys(value).length === 1 && value.accepted === true
      : Object.keys(value).length === 2 && value.completed === true && value.next === '/account/sign-in') ? 'saved' : 'uncertain';
  } catch { return 'uncertain'; }
}
