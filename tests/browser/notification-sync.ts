import { expect, type Page, type Response } from '@playwright/test';

type LivewirePayload = {
  components?: {
    snapshot?: string;
    calls?: { method?: string; params?: unknown[] }[];
  }[];
};

function isNotificationResponse(response: Response, event: 'notificationsSent' | 'notificationClosed', id?: string) {
  if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
  const payload = response.request().postDataJSON() as LivewirePayload;
  return payload.components?.some(component => {
    const snapshot = JSON.parse(component.snapshot ?? '{}') as { memo?: { name?: string } };
    if (snapshot.memo?.name !== 'Filament\\Livewire\\Notifications') return false;
    return component.calls?.some(call => {
      if (call.method !== '__dispatch' || call.params?.[0] !== event) return false;
      if (id === undefined) return true;
      const detail = call.params[1] as { id?: unknown } | undefined;
      return detail?.id === id;
    }) ?? false;
  }) ?? false;
}

/** Complete the notification's own server requests before a later document navigation. */
export async function syncSuccessNotification(
  page: Page,
  title: string,
  action: () => Promise<void>,
  assertBeforeClose?: () => Promise<void>,
) {
  // A successful action response dispatches notificationsSent; delivery is a separate Livewire request.
  const [delivered] = await Promise.all([
    page.waitForResponse(response => isNotificationResponse(response, 'notificationsSent')),
    action(),
  ]);
  expect(delivered.status()).toBe(200);
  expect(await delivered.finished()).toBeNull();

  const notification = page.locator('.fi-no-notification').filter({
    has: page.getByRole('heading', { name: title, exact: true }),
  });
  await expect(notification).toHaveCount(1);
  await expect(notification.getByRole('heading', { name: title, exact: true })).toBeVisible();
  await assertBeforeClose?.();

  // Filament 5 renders the same ID on both sides of ".notifications." in this wire:key.
  const key = await notification.getAttribute('wire:key');
  const match = /^(.+)\.notifications\.\1$/.exec(key ?? '');
  expect(match).not.toBeNull();
  const id = match![1];
  const [removed] = await Promise.all([
    page.waitForResponse(response => isNotificationResponse(response, 'notificationClosed', id)),
    notification.getByRole('button', { name: 'Close notification', exact: true }).click(),
  ]);
  expect(removed.status()).toBe(200);
  expect(await removed.finished()).toBeNull();
  // The local fade precedes the server removal, so hidden alone does not acknowledge dismissal.
  await expect(notification).toHaveCount(0);
}
