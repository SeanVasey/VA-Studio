/** Auth transitions always finish with a new document and its fresh CSRF state. */
export type CustomerDestination = '/account' | '/account/sign-in';
type Result = { kind: 'complete'; next: CustomerDestination } | { kind: 'retry' | 'reload'; message: string };

function csrfHeaders(): Record<string, string> {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) {
    try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) }; } catch { /* Use the document token. */ }
  }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}

export async function changeCustomerSession(action: 'sign-in' | 'sign-out', credentials: { email: string; password: string } | null, signal: AbortSignal): Promise<Result> {
  const uncertain = { kind: 'reload' as const, message: action === 'sign-in'
    ? 'Sign-in could not be confirmed. Open a fresh sign-in page before continuing.'
    : 'Sign-out could not be confirmed. Open a fresh sign-in page to check your session before continuing.' };
  try {
    const response = await fetch(`/account/${action}`, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
      body: JSON.stringify(action === 'sign-in' ? credentials : {}),
    });
    if (action === 'sign-in' && response.status === 422) return { kind: 'retry', message: 'Sign-in could not be completed. Check your details and try again.' };
    if (action === 'sign-in' && response.status === 429) return { kind: 'retry', message: 'Too many sign-in attempts. Wait before trying again.' };
    if (response.status !== 200 || response.redirected) return uncertain;
    const bytes = await response.text();
    if (bytes.length > 4096) return uncertain;
    const body: unknown = JSON.parse(bytes);
    const next = action === 'sign-in' ? '/account' : '/account/sign-in';
    if (!body || typeof body !== 'object' || Array.isArray(body)
      || Object.keys(body).length !== 2 || !('authenticated' in body) || !('next' in body)
      || body.authenticated !== (action === 'sign-in') || body.next !== next) return uncertain;
    return { kind: 'complete', next };
  } catch { return uncertain; }
}

export function navigateCustomerSession(destination: CustomerDestination): void {
  window.location.replace(destination);
}
