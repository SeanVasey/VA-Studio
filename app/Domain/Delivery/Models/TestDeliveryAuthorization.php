<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PendingEntitlement;
use App\Domain\Contracts\Models\GrantContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** One retained test stream authorization. Only its random secret's digest is stored. */
final class TestDeliveryAuthorization extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['owner_key', 'token_hash', 'idempotency_key_hash', 'request_hash', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'test_fulfillment_activation_id' => 'integer', 'test_delivery_control_id' => 'integer',
            'control_version' => 'integer', 'license_grant_id' => 'integer', 'grant_contract_id' => 'integer',
            'pending_entitlement_id' => 'integer', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'order_id'); }
    public function activation(): BelongsTo { return $this->belongsTo(TestFulfillmentActivation::class, 'test_fulfillment_activation_id'); }
    public function control(): BelongsTo { return $this->belongsTo(TestDeliveryControl::class, 'test_delivery_control_id'); }
    public function grant(): BelongsTo { return $this->belongsTo(LicenseGrant::class, 'license_grant_id'); }
    public function contract(): BelongsTo { return $this->belongsTo(GrantContract::class, 'grant_contract_id'); }
    public function entitlement(): BelongsTo { return $this->belongsTo(PendingEntitlement::class, 'pending_entitlement_id'); }
    public function redemption(): HasOne { return $this->hasOne(TestDeliveryRedemption::class, 'test_delivery_authorization_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Test delivery authorization evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Test delivery authorization evidence must be retained.'));
    }
}
