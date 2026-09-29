<?php

namespace App\Domain\SiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One requested future activation. Only the site content service resolves it, exactly once. */
class SitePublicationSchedule extends Model
{
    public const STATES = ['pending', 'published', 'cancelled', 'superseded', 'failed', 'expired'];

    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'release_id' => 'integer', 'expected_revision' => 'integer', 'created_by' => 'integer', 'pending_slot' => 'integer',
            'resolved_by' => 'integer', 'publication_revision' => 'integer', 'publish_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $schedule): void {
            if ($schedule->getOriginal('state') !== 'pending') {
                throw new LogicException('A resolved site publication schedule is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Site publication schedules must be retained.'));
    }
}
