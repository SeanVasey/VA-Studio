<?php

namespace App\Domain\Customers\Models;

use Illuminate\Database\Eloquent\Model;

final class CustomerIdentityChallenge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['email', 'proof_hash', 'request_hash', 'address_key', 'credential_stamp', 'result_stamp', 'completion_hash'];

    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'user_id' => 'integer', 'account_id' => 'integer',
            'access_version' => 'integer', 'result_user_id' => 'integer', 'result_account_id' => 'integer', 'result_access_version' => 'integer'];
    }
}
