<?php

namespace App\Domain\Contracts\Models;

use App\Domain\Commerce\Models\LicenseGrant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable original-document manifest. This is not an active entitlement or download authorization. */
final class GrantContract extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['input_hash', 'profile_hash', 'disk', 'storage_path', 'claim_token', 'pdf_hash'];

    protected function casts(): array
    {
        return ['license_grant_id' => 'integer', 'contract_render_request_id' => 'integer',
            'size_bytes' => 'integer', 'page_count' => 'integer', 'issued_at' => 'immutable_datetime'];
    }

    public function grant(): BelongsTo { return $this->belongsTo(LicenseGrant::class, 'license_grant_id'); }
    public function request(): BelongsTo { return $this->belongsTo(ContractRenderRequest::class, 'contract_render_request_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Original contract evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Original contract evidence must be retained.'));
    }
}
