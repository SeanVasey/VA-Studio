<?php

namespace App\Domain\Contracts\Models;

use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\LicenseGrant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Immutable request for one original test document with retained input and renderer identity. */
final class ContractRenderRequest extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['input_hash', 'profile', 'profile_hash'];

    protected function casts(): array
    {
        return ['license_grant_id' => 'integer', 'fulfillment_outbox_id' => 'integer', 'profile' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function grant(): BelongsTo { return $this->belongsTo(LicenseGrant::class, 'license_grant_id'); }
    public function outbox(): BelongsTo { return $this->belongsTo(FulfillmentOutbox::class, 'fulfillment_outbox_id'); }
    public function work(): HasOne { return $this->hasOne(ContractRenderWork::class); }
    public function contract(): HasOne { return $this->hasOne(GrantContract::class); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Contract render request evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Contract render request evidence must be retained.'));
    }
}
