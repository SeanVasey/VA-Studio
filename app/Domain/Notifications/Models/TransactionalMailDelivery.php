<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/** One email attempt per order-ready intent; forward-only states, never deleted (database guarded). */
final class TransactionalMailDelivery extends Model
{
    protected $table = 'transactional_mail_deliveries';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['notice_id' => 'integer', 'version' => 'integer', 'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
