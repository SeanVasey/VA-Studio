<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Domain\Migration\BeatStars\CellText;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Operator-authored mapping plus acquisition attestation for one BeatStars history export: customers, order
 * lines and original contract files.
 *
 * Every semantic decision (which column holds an id, how a status value maps, which license names exist and
 * whether each is exclusive, which file extensions are which media type) is declared here and never derived from
 * the sheets, so an undeclared column is a finding rather than a guess. There is deliberately no consent field:
 * a consent column can only be ignored, and every customer record keeps marketing consent `unknown`.
 */
final class HistoryMapping
{
    public const PURPOSE = 'vasey-beatstars-history-mapping-v1';

    public const MAX_BYTES = 262144;

    public const ACQUISITION_METHODS = ['official_export', 'authorized_read_only_audit', 'seller_owned_original',
        'independently_verified_manual_entry', 'synthetic_fixture'];

    /** Only currencies whose minor-unit exponent is known here; anything else is refused, never converted. */
    public const CURRENCIES = ['USD'];

    /** Normalized order statuses. `unknown` must be declared explicitly; nothing defaults to it. */
    public const STATUSES = ['paid', 'refunded', 'partially_refunded', 'unpaid', 'unknown'];

    public const SHEETS = [
        'customers' => ['required' => ['source_customer_id', 'email'], 'optional' => ['display_name']],
        'orders' => ['required' => ['source_order_id', 'source_line_id', 'source_customer_id', 'ordered_at', 'status',
            'track_source_id', 'license', 'amount', 'refunded_amount'], 'optional' => ['payment_reference', 'currency']],
        'contracts' => ['required' => ['source_contract_id', 'source_order_id', 'source_line_id', 'file_name'],
            'optional' => ['declared_sha256']],
    ];

    public const MEDIA_TYPES = ['application/pdf', 'text/plain'];

    /**
     * Decode and validate a mapping. Throws InvalidArgumentException whose message is a stable `mapping_*` reason
     * code that carries no file contents.
     *
     * @return array{snapshot_id: string, source_system: string, operator_reference: string, acquisition_method: string,
     *     acquired_at: string, source_as_of: string, currency: string,
     *     customers: array{columns: array<string, string>, ignored_columns: list<string>},
     *     orders: array{columns: array<string, string>, ignored_columns: list<string>, status_values: array<string, string>},
     *     contracts: array{columns: array<string, string>, ignored_columns: list<string>, media_types: array<string, string>},
     *     licenses: array<string, array{label: string, exclusive: bool}>}
     */
    public function decode(string $bytes): array
    {
        $this->require(strlen($bytes) <= self::MAX_BYTES, 'mapping_too_large');
        try {
            $decoded = json_decode($bytes, false, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('mapping_json');
        }
        $mapping = $this->object($decoded, ['schema_version', 'purpose', 'snapshot_id', 'source_system', 'operator_reference',
            'acquisition_method', 'acquired_at', 'source_as_of', 'currency', 'customers', 'orders', 'contracts', 'licenses'], [], 'mapping_keys');
        $this->require($mapping['schema_version'] === 1 && $mapping['purpose'] === self::PURPOSE, 'mapping_purpose');
        foreach (['snapshot_id', 'source_system', 'operator_reference'] as $field) {
            $this->require(CellText::identity($mapping[$field]), 'mapping_identity');
        }
        $this->require(in_array($mapping['acquisition_method'], self::ACQUISITION_METHODS, true), 'mapping_acquisition_method');
        $this->require(self::timestamp($mapping['acquired_at']) && self::timestamp($mapping['source_as_of'])
            && $mapping['source_as_of'] <= $mapping['acquired_at'], 'mapping_timestamp');
        $this->require(in_array($mapping['currency'], self::CURRENCIES, true), 'mapping_currency');

        $result = ['snapshot_id' => $mapping['snapshot_id'], 'source_system' => $mapping['source_system'],
            'operator_reference' => $mapping['operator_reference'], 'acquisition_method' => $mapping['acquisition_method'],
            'acquired_at' => $mapping['acquired_at'], 'source_as_of' => $mapping['source_as_of'], 'currency' => $mapping['currency']];
        foreach (self::SHEETS as $sheet => $fields) {
            $required = ['columns', ...match ($sheet) {
                'orders' => ['status_values'], 'contracts' => ['media_types'], default => [],
            }];
            $optional = ['ignored_columns'];
            $definition = $this->object($mapping[$sheet], $required, $optional, 'mapping_'.$sheet);
            $columns = $this->object($definition['columns'], $fields['required'], $fields['optional'], 'mapping_columns');
            foreach ($columns as $header) {
                $this->require(CellText::line($header, 255), 'mapping_columns');
            }
            $ignored = $definition['ignored_columns'] ?? [];
            $this->require(is_array($ignored) && array_is_list($ignored) && count($ignored) <= 200, 'mapping_ignored_columns');
            foreach ($ignored as $header) {
                $this->require(CellText::line($header, 255), 'mapping_ignored_columns');
            }
            $roles = [...array_values($columns), ...$ignored];
            $this->require(count(array_unique($roles, SORT_STRING)) === count($roles), 'mapping_column_reuse');
            $result[$sheet] = ['columns' => $columns, 'ignored_columns' => array_values($ignored)];
        }

        $this->require($mapping['orders']->status_values instanceof stdClass, 'mapping_status_values');
        $statuses = [];
        foreach (get_object_vars($mapping['orders']->status_values) as $source => $target) {
            $this->require(CellText::line((string) $source, 80) && in_array($target, self::STATUSES, true), 'mapping_status_values');
            $statuses[(string) $source] = $target;
        }
        $this->require($statuses !== [], 'mapping_status_values');
        $result['orders']['status_values'] = $statuses;

        $this->require($mapping['contracts']->media_types instanceof stdClass, 'mapping_media_types');
        $types = [];
        foreach (get_object_vars($mapping['contracts']->media_types) as $extension => $type) {
            $this->require(preg_match('/\A[a-z0-9]{1,8}\z/D', (string) $extension) === 1 && in_array($type, self::MEDIA_TYPES, true), 'mapping_media_types');
            $types[(string) $extension] = $type;
        }
        $this->require($types !== [], 'mapping_media_types');
        $result['contracts']['media_types'] = $types;

        $this->require($mapping['licenses'] instanceof stdClass, 'mapping_licenses');
        $licenses = [];
        foreach (get_object_vars($mapping['licenses']) as $name => $definition) {
            $license = $this->object($definition, ['label', 'exclusive'], [], 'mapping_licenses');
            $this->require(CellText::line((string) $name, 120) && is_string($license['label'])
                && preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $license['label']) === 1 && is_bool($license['exclusive']), 'mapping_licenses');
            $licenses[(string) $name] = ['label' => $license['label'], 'exclusive' => $license['exclusive']];
        }
        $this->require($licenses !== [] && count(array_unique(array_column($licenses, 'label'))) === count($licenses), 'mapping_licenses');
        $result['licenses'] = $licenses;

        return $result;
    }

    /** Explicit UTC second precision, as `YYYY-MM-DDTHH:MM:SSZ`. */
    public static function timestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function object(mixed $value, array $required, array $optional, string $code): array
    {
        $this->require($value instanceof stdClass, $code);
        $fields = get_object_vars($value);
        foreach ($required as $key) {
            $this->require(array_key_exists($key, $fields), $code);
        }
        foreach (array_keys($fields) as $key) {
            $this->require(in_array($key, $required, true) || in_array($key, $optional, true), $code);
        }

        return $fields;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
