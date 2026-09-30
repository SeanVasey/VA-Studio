<?php

namespace App\Application\SiteBuilder;

use App\Domain\SiteBuilder\Models\SiteImage;
use App\Jobs\ProcessSiteImage;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Queues another processing attempt for an image waiting in quarantine or held by an expired claim. */
final class RetrySiteImage
{
    public static function retryable(SiteImage $image): bool
    {
        return $image->status === 'quarantined'
            || ($image->status === 'processing' && ($image->claimed_until === null || ! $image->claimed_until->isFuture()));
    }

    public function handle(SiteImage $image, User $actor): void
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        if (! AdminMultiFactor::satisfiedBy($actor)) {
            throw new AuthorizationException('Multi-factor authentication is required.');
        }
        $image = SiteImage::findOrFail($image->id);
        if (! self::retryable($image)) {
            throw ValidationException::withMessages(['image' => 'This image is not waiting for a retry.']);
        }
        AuditEvent::record('site.image.retry_requested', $image, ['status' => $image->status, 'failure_code' => $image->failure_code], $actor->id);
        ProcessSiteImage::dispatch($image->id)->onQueue(config('media.queue'));
    }
}
