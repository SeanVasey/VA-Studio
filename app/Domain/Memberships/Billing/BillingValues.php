<?php

namespace App\Domain\Memberships\Billing;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/** Value helpers only. A hash, seal or ciphertext here never confers paid-invoice authority. */
final class BillingValues
{
    public const PATTERNS = [
        'account' => '/\Aacct_[A-Za-z0-9]{1,64}\z/D', 'invoice' => '/\Ain_[A-Za-z0-9]{1,120}\z/D',
        'invoice_payment' => '/\Ainpay_[A-Za-z0-9]{1,120}\z/D', 'payment_intent' => '/\Api_[A-Za-z0-9]{1,120}\z/D',
        'charge' => '/\Ach_[A-Za-z0-9]{1,120}\z/D', 'balance_transaction' => '/\Atxn_[A-Za-z0-9]{1,120}\z/D',
        'subscription' => '/\Asub_[A-Za-z0-9]{1,120}\z/D', 'customer' => '/\Acus_[A-Za-z0-9]{1,120}\z/D',
        'price' => '/\Aprice_[A-Za-z0-9]{1,120}\z/D', 'event' => '/\Aevt_[A-Za-z0-9]{1,120}\z/D',
        'line_item' => '/\Ail_[A-Za-z0-9]{1,120}\z/D', 'subscription_item' => '/\Asi_[A-Za-z0-9]{1,120}\z/D',
    ];

    public static function is(string $kind, mixed $value): bool
    {
        return is_string($value) && isset(self::PATTERNS[$kind]) && preg_match(self::PATTERNS[$kind], $value) === 1;
    }

    /** Provider ids appear as strings or expanded objects; anything else is not a reference. */
    public static function ref(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_array($value) && is_string($value['id'] ?? null) ? $value['id'] : null;
    }

    /** Domain-separated identity of one provider reference under one own account and mode. */
    public static function hash(string $purpose, string $account, string $mode, string $ref): string
    {
        BillingException::require(preg_match('/\A[a-z_-]{1,40}\z/D', $purpose) === 1 && self::is('account', $account)
            && in_array($mode, ['test', 'live'], true) && $ref !== '' && strlen($ref) <= 160, 'invalid_value');

        return hash('sha256', 'production-membership-billing-v1'."\0".$purpose."\0".$account."\0".$mode."\0".$ref);
    }

    public static function utc(int $timestamp): string
    {
        BillingException::require($timestamp > 0 && $timestamp < 253402300800, 'invalid_value');

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * UTC wall time at microsecond resolution, 26 ASCII bytes. It orders the starts of overlapping retrievals; whole seconds
     * could not tell apart two retrievals that began in the same second (review R-6).
     */
    public static function utcMicro(CarbonImmutable $moment): string
    {
        $utc = $moment->setTimezone('UTC');
        BillingException::require($utc->getTimestamp() > 0 && $utc->getTimestamp() < 253402300800, 'invalid_value');

        return $utc->format('Y-m-d H:i:s.u');
    }

    public static function id(): string
    {
        return (string) Str::uuid();
    }

    public static function seal(array $row): string
    {
        unset($row['seal']);
        ksort($row);

        return CanonicalJson::hash($row);
    }

    public static function encrypt(#[SensitiveParameter] array $payload): string
    {
        return Crypt::encryptString(CanonicalJson::encode($payload));
    }

    public static function decrypt(#[SensitiveParameter] string $ciphertext): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($ciphertext), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new BillingException('ciphertext');
        }
        BillingException::require(is_array($payload), 'ciphertext');

        return $payload;
    }
}
