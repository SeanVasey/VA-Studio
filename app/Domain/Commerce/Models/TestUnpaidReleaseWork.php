<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

final class TestUnpaidReleaseWork extends Model
{
    protected $table = 'test_unpaid_release_work';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id', 'claim_token'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'sequence' => 'integer', 'lease_expires_at' => 'immutable_datetime'];
    }
}
