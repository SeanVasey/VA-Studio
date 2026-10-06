<?php

namespace App\Domain\Commerce\Policy;

use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;

/** Authored declarations only. A valid draft never verifies a merchant fact or enables commerce. */
final class ProductionTrackPolicyDraft
{
    public const CATEGORIES = ['seller_identity', 'provider_account', 'currency', 'tax_calculation', 'assent', 'license_terms',
        'buyer_identity', 'recovery', 'reservation_and_exclusives', 'refunds_and_disputes', 'original_documents', 'storage', 'delivery', 'privacy'];

    public static function validateAuthored(array $authored): array
    {
        if (! self::keys($authored, ['schema_version', 'purpose', 'version', 'declarations'])
            || $authored['schema_version'] !== 1 || $authored['purpose'] !== 'production_track_policy_draft'
            || ! is_string($authored['version']) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,79}\z/D', $authored['version']) !== 1
            || ! is_array($authored['declarations']) || ! self::keys($authored['declarations'], self::CATEGORIES)) {
            self::reject();
        }
        foreach ($authored['declarations'] as $declaration) {
            if (! is_array($declaration) || ! self::keys($declaration, ['state', 'choice', 'source_reference', 'source_sha256', 'note'])
                || ! in_array($declaration['state'], ['declared', 'unresolved'], true) || ! self::text($declaration['note'], 1024)) {
                self::reject();
            }
            if ($declaration['state'] === 'unresolved') {
                if ($declaration['choice'] !== null || $declaration['source_reference'] !== null || $declaration['source_sha256'] !== null) {
                    self::reject();
                }
            } elseif (! self::text($declaration['choice'], 512) || ! self::text($declaration['source_reference'], 512)
                || ! is_string($declaration['source_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $declaration['source_sha256']) !== 1) {
                self::reject();
            }
        }
        $canonical = CanonicalJson::encode($authored);
        if (strlen($canonical) > 32768 || preg_match('/(?:sk|rk)_(?:test|live)_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $canonical)) {
            self::reject();
        }

        return $authored;
    }

    public static function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }

    public static function text(mixed $value, int $bytes): bool
    {
        return is_string($value) && strlen($value) <= $bytes && trim($value) !== '' && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\x00-\x1f\x7f]/u', $value) === 0;
    }

    public static function reject(): never
    {
        throw ValidationException::withMessages(['policy' => 'Review the current production policy draft and supply every required declaration.']);
    }
}
