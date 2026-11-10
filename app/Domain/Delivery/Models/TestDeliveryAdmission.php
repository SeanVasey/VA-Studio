<?php

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One admitted v2 response, written under the authorization lock before its first byte. The per-authorization response
 * limit and the incomplete-transfer cap count admissions, so a response whose worker dies mid-stream still counts.
 */
final class TestDeliveryAdmission extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['test_delivery_authorization_id' => 'integer', 'test_delivery_redemption_id' => 'integer', 'sequence' => 'integer',
            'first_byte' => 'integer', 'last_byte' => 'integer', 'admitted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Test delivery admission evidence is append-only.'));
        self::deleting(fn () => throw new LogicException('Test delivery admission evidence must be retained.'));
    }
}
