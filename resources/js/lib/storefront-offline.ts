// Private/admin routes and embedded players do not initiate this registration.
export function isPublicStorefrontPath(path: string): boolean {
  return /^(?:\/|\/tracks\/[a-z0-9]+(?:-[a-z0-9]+)*|\/(?:about|contact)|\/(?:blog|videos)(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)?)$/.test(path);
}

export async function registerStorefrontOfflineRecovery(): Promise<ServiceWorkerRegistration | undefined> {
  if (!window.isSecureContext || window.self !== window.top
    || !isPublicStorefrontPath(window.location.pathname) || !('serviceWorker' in navigator)) return;
  try {
    return await navigator.serviceWorker.register('/storefront-worker.js', { scope: '/', updateViaCache: 'none' });
  } catch {
    // Storage/worker restrictions must not prevent the online store from loading.
    return;
  }
}
