<?php

namespace App\Domain\Grants\Member;

use App\Domain\Contracts\ContractIssuanceException;
use App\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Bounded child data only. A valid value proves neither source admission nor readiness. */
final readonly class MemberOriginalRenderInput
{
    public const VERSION = 'member-original-render-input-v1';

    public const FAMILY = 'production-member-origin-v1';

    public const PURPOSE = 'production-member-license-grant-v1';

    private function __construct(private array $document) {}

    public static function from(array $document): self
    {
        try {
            self::keys($document, ['schema_version', 'family', 'purpose', 'provenance', 'origin_id',
                'render_source_hash', 'buyer_account_id', 'reservation_created_at', 'selection', 'original_terms']);
            self::require($document['schema_version'] === self::VERSION && $document['family'] === self::FAMILY
                && $document['purpose'] === self::PURPOSE
                && in_array($document['provenance'], ['synthetic_rehearsal', 'verified_production'], true));
            self::require(is_string($document['origin_id'])
                && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $document['origin_id']) === 1);
            self::hashValue($document['render_source_hash']);
            self::identity($document['buyer_account_id']);
            self::require(is_string($document['reservation_created_at']));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $document['reservation_created_at'], new DateTimeZone('UTC'));
            self::require($date !== false && $date->format('Y-m-d H:i:s') === $document['reservation_created_at']);
            self::require(is_array($document['selection']) && is_array($document['original_terms']));
            self::keys($document['selection'], ['track_id', 'offer_id', 'offer_revision_id', 'license_version_id',
                'selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash']);
            foreach (['track_id', 'offer_id', 'offer_revision_id', 'license_version_id'] as $field) {
                self::identity($document['selection'][$field]);
            }
            foreach (['selection_hash', 'license_manifest_hash', 'asset_manifest_hash', 'original_terms_hash'] as $field) {
                self::hashValue($document['selection'][$field]);
            }
            $terms = $document['original_terms'];
            self::keys($terms, ['purpose', 'authored_source', 'structured_terms', 'renderer_version', 'render_fixture_hash']);
            self::require($terms['purpose'] === 'membership-license-original-terms-v1'
                && is_string($terms['authored_source']) && $terms['authored_source'] !== '' && strlen($terms['authored_source']) <= 65536
                && is_array($terms['structured_terms']) && $terms['structured_terms'] !== []
                && is_string($terms['renderer_version']) && $terms['renderer_version'] !== '' && strlen($terms['renderer_version']) <= 160);
            self::hashValue($terms['render_fixture_hash']);
            $remaining = 20000;
            self::bounded($document, 0, $remaining);
            self::require(strlen(CanonicalJson::encode($document)) <= 1048576
                && CanonicalJson::hash($terms) === $document['selection']['original_terms_hash']);

            return new self($document);
        } catch (ContractIssuanceException $error) {
            throw $error;
        } catch (Throwable) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }

    public function value(): array
    {
        return $this->document;
    }

    public function hash(): string
    {
        return CanonicalJson::hash($this->document);
    }

    private static function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        self::require($keys === $expected);
    }

    private static function identity(mixed $value): void
    {
        self::require(is_int($value) && $value > 0 && $value <= 9007199254740991);
    }

    private static function hashValue(mixed $value): void
    {
        self::require(is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1);
    }

    private static function bounded(mixed $value, int $depth, int &$remaining): void
    {
        self::require($depth <= 24 && --$remaining >= 0);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::require(is_int($key) || (is_string($key) && strlen($key) <= 256));
                self::bounded($item, $depth + 1, $remaining);
            }
        } else {
            self::require(is_null($value) || is_bool($value) || is_int($value)
                || (is_string($value) && strlen($value) <= 65536 && mb_check_encoding($value, 'UTF-8')));
        }
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new ContractIssuanceException('unsupported_input');
        }
    }
}
