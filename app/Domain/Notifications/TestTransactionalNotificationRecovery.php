<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Internal bounded test recovery; no HTTP, queue, scheduler or live mail admission. */
final class TestTransactionalNotificationRecovery
{
    public const MAX_BATCH = 25;

    /** The internal row cursor contains no recipient or payload and is never customer-facing. */
    public function scan(int $after = 0, int $limit = 10): array
    {
        TransactionalNotificationPolicy::outsideTransactions();
        app(TransactionalNotificationPolicy::class)->requireEnabled();
        if ($after < 0 || $limit < 1 || $limit > self::MAX_BATCH) {
            throw new NotificationException;
        }
        $rows = DB::table('transactional_notices')->where('id', '>', $after)->orderBy('id')
            ->limit($limit + 1)->get(['id', 'public_id']);
        $hasMore = $rows->count() > $limit;
        $results = [];
        $service = app(TestTransactionalNotifications::class);
        foreach ($rows->take($limit) as $row) {
            // Each retained service operation proves current authority and exact original
            // evidence. No stale scan snapshot is authority to claim or confirm a capture.
            try {
                $result = $service->status($row->public_id);
                $result = match ($result['state']) {
                    'pending' => $service->dispatch($row->public_id),
                    'failed' => $service->dispatch($row->public_id, retryKnownFailure: true),
                    'uncertain' => $service->reconcile($row->public_id),
                    default => $result,
                };
                $results[] = $result;
            } catch (Throwable) {
                // A refusal or infrastructure error is not proof of delivery/non-delivery.
                // Continue the bounded page without leaking recipient or exception details.
                $results[] = ['notificationSchema' => 1, 'notificationId' => $row->public_id,
                    'testOnly' => true, 'state' => 'unavailable'];
            }
            $after = (int) $row->id;
        }

        return ['recoverySchema' => 1, 'testOnly' => true, 'results' => $results,
            'nextCursor' => $after, 'hasMore' => $hasMore];
    }
}
