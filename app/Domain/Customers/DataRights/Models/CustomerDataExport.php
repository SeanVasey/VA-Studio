<?php

namespace App\Domain\Customers\DataRights\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted, short-lived export bytes; the ciphertext is purged on download or expiry and never restored. */
final class CustomerDataExport extends Model
{
    protected $table = 'customer_data_exports';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['ciphertext', 'token_hash'];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'size_bytes' => 'integer', 'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
