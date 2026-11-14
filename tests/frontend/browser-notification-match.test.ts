import { describe, expect, it } from 'vitest';
import { isNotificationResponse } from '../browser/notification-match';

const notifications = JSON.stringify({ memo: { name: 'Filament\\Livewire\\Notifications' } });
const other = JSON.stringify({ memo: { name: 'App\\Livewire\\Other' } });

function response(components: unknown, { url = 'http://127.0.0.1/livewire-x/update', method = 'POST' } = {}) {
  return { url: () => url, request: () => ({ method: () => method, postDataJSON: () => ({ components }) }) } as never;
}
const call = (snapshot: string, method: string, params: unknown[]) => [{ snapshot, calls: [{ method, params }] }];

describe('browser notification sync matcher', () => {
  it('recognises Filament 5.11 delivery through pullNotifications(batches)', () => {
    expect(isNotificationResponse(response(call(notifications, 'pullNotifications', [['a'.repeat(40)]])), 'notificationsSent')).toBe(true);
    expect(isNotificationResponse(response(call(notifications, 'pullNotifications', [[]])), 'notificationsSent')).toBe(true);
  });

  it('rejects other components, other methods, non-update URLs and non-POST requests', () => {
    expect(isNotificationResponse(response(call(other, 'pullNotifications', [[]])), 'notificationsSent')).toBe(false);
    expect(isNotificationResponse(response(call(notifications, 'removeNotification', ['x'])), 'notificationsSent')).toBe(false);
    expect(isNotificationResponse(response(call(notifications, 'pullNotifications', ['not-an-array'])), 'notificationsSent')).toBe(false);
    expect(isNotificationResponse(response(call(notifications, 'pullNotifications', [[]]), { url: 'http://x/other' }), 'notificationsSent')).toBe(false);
    expect(isNotificationResponse(response(call(notifications, 'pullNotifications', [[]]), { method: 'GET' }), 'notificationsSent')).toBe(false);
  });

  it('still matches a dismissal by its exact notification id', () => {
    const closed = call(notifications, '__dispatch', ['notificationClosed', { id: 'n1' }]);
    expect(isNotificationResponse(response(closed), 'notificationClosed', 'n1')).toBe(true);
    expect(isNotificationResponse(response(closed), 'notificationClosed', 'n2')).toBe(false);
    expect(isNotificationResponse(response(closed), 'notificationClosed')).toBe(true);
    expect(isNotificationResponse(response(closed), 'notificationsSent')).toBe(false);
  });
});
