<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One requested future activation of a track. Only App\Domain\Catalog\TrackPublicationSchedules writes it; the table's
 * triggers admit exactly one resolution (published, cancelled or rescheduled) and refuse later edits and deletion.
 */
class TrackPublicationSchedule extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'track_id' => 'integer', 'created_by' => 'integer', 'resolved_by' => 'integer', 'pending_slot' => 'integer',
            'attempts' => 'integer', 'publication_version' => 'integer', 'publish_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime', 'last_failure_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $schedule): void {
            if ($schedule->getOriginal('state') !== 'pending') {
                throw new LogicException('A resolved track publication schedule is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Track publication schedules must be retained.'));
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
