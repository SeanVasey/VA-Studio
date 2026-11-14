import type { Response } from '@playwright/test';

type LivewirePayload = {
  components?: {
    snapshot?: string;
    calls?: { method?: string; params?: unknown[] }[];
  }[];
};

/**
 * True for the Livewire request that carries a Filament notification step. Kept free of Playwright's runtime
 * so Vitest can pin the wire format (tests/frontend/browser-notification-match.test.ts).
 */
export function isNotificationResponse(response: Pick<Response, 'url' | 'request'>, event: 'notificationsSent' | 'notificationClosed', id?: string) {
  if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
  const payload = response.request().postDataJSON() as LivewirePayload;
  return payload.components?.some(component => {
    const snapshot = JSON.parse(component.snapshot ?? '{}') as { memo?: { name?: string } };
    if (snapshot.memo?.name !== 'Filament\\Livewire\\Notifications') return false;
    return component.calls?.some(call => {
      // Filament 5.11 delivers a sent notification by calling the component's pullNotifications(batches)
      // method directly (resources/js/components/notifications.js); it no longer dispatches notificationsSent.
      if (event === 'notificationsSent') return call.method === 'pullNotifications' && Array.isArray(call.params?.[0]);
      if (call.method !== '__dispatch' || call.params?.[0] !== event) return false;
      if (id === undefined) return true;
      const detail = call.params[1] as { id?: unknown } | undefined;
      return detail?.id === id;
    }) ?? false;
  }) ?? false;
}
