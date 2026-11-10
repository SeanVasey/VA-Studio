<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only staff resolution of a paid production tax order whose finalization is refused (fully refunded or dispute lost).
 * It records the decision and its encrypted evidence; it never creates or changes a grant, license, contract or authorization.
 */
final class RefusedOrderResolution extends Model
{
    public $timestamps = false;

    protected $table = 'production_tax_refused_order_resolutions';

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['gross_minor' => 'integer', 'actor_id' => 'integer', 'observed_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('A refused-order resolution is immutable.'));
        self::deleting(fn () => throw new LogicException('A refused-order resolution must be retained.'));
    }
}
