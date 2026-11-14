<?php

namespace App\Domain\Customers\DataRights\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only request history; detail carries identifiers, counts and policy references, never export contents. */
final class CustomerDataRequestEvent extends Model
{
    protected $table = 'customer_data_request_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'sequence' => 'integer', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
