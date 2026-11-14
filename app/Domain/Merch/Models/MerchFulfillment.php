<?php

namespace App\Domain\Merch\Models;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\VerifiedPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** The hand-off of one paid store order to Printful. Never deleted; the recipient stays encrypted. */
final class MerchFulfillment extends Model
{
    public const RECORDED = 'recorded';

    public const SUBMITTING = 'submitting';

    public const SUBMITTED = 'submitted';

    /** A confirmation request is in flight (it charges the Printful account); only the caller holding the claim may send it. */
    public const CONFIRMING = 'confirming';

    public const CONFIRMED = 'confirmed';

    public const SHIPPED = 'shipped';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

    protected $guarded = ['id'];

    protected $hidden = ['request_ciphertext', 'request_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'verified_payment_id' => 'integer', 'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'shipped_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function verifiedPayment(): BelongsTo
    {
        return $this->belongsTo(VerifiedPayment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MerchFulfillmentEvent::class);
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new LogicException('Fulfillment evidence must be retained.'));
    }
}
