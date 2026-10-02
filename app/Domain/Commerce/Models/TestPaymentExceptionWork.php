<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

final class TestPaymentExceptionWork extends Model
{
    protected $table = 'test_payment_exception_work';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id', 'claim_token'];

    protected function casts(): array
    {
        return ['order_finalization_id' => 'integer', 'sequence' => 'integer', 'lease_expires_at' => 'immutable_datetime'];
    }
}
