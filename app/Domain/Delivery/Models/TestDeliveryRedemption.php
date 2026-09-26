<?php

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One committed stream attempt; this is not proof that the recipient received every byte. */
final class TestDeliveryRedemption extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['content_hash', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['test_delivery_authorization_id' => 'integer', 'control_version' => 'integer',
            'size_bytes' => 'integer', 'redeemed_at' => 'immutable_datetime'];
    }

    public function authorization(): BelongsTo { return $this->belongsTo(TestDeliveryAuthorization::class, 'test_delivery_authorization_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Test delivery redemption evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Test delivery redemption evidence must be retained.'));
    }
}
