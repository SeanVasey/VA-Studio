<?php

namespace App\Domain\Commerce\Models;

use App\Domain\Catalog\Models\OfferRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QuoteLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'offer_revision_id' => 'integer'];
    }

    public function offerRevision(): BelongsTo
    {
        return $this->belongsTo(OfferRevision::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quote line evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Quote line evidence must be retained.'));
    }
}
