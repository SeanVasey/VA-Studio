<?php

namespace App\Domain\Customers\DataRights\Models;

use Illuminate\Database\Eloquent\Model;

/** One customer export or deletion request; it moves once from open to terminal (database guarded). */
final class CustomerDataRequest extends Model
{
    protected $table = 'customer_data_requests';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_account_id' => 'integer', 'open_slot' => 'integer', 'version' => 'integer', 'last_event_sequence' => 'integer', 'resolved_by' => 'integer',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
