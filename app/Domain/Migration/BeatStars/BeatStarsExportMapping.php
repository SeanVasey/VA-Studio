<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Operator-authored column mapping plus acquisition attestation for one BeatStars export.
 *
 * Every semantic decision (which column is the source id, how a status value maps to a
 * visibility, which license names exist, how tags are separated) is declared here. The
 * normalizer never derives any of it from the sheet, so an undeclared column is a finding
 * rather than a guess. There is deliberately no consent, customer or e-mail field.
 */
final class BeatStarsExportMapping
{
    public const PURPOSE = 'vasey-beatstars-export-mapping-v1';

    public const MAX_BYTES = 1048576;

    public const FIELDS = ['source_id', 'title', 'slug', 'artist', 'bpm', 'musical_key', 'genre', 'mood', 'tags',
        'description', 'visibility', 'rights_reference', 'currency'];

    public const CONSTANTS = ['artist', 'visibility'];

    public const VISIBILITIES = ['draft', 'private', 'unlisted', 'public', 'sold', 'unknown'];

    public const ACQUISITION_METHODS = ['official_export', 'authorized_read_only_audit', 'seller_owned_original',
        'independently_verified_manual_entry', 'synthetic_fixture'];

    /** Only currencies whose minor-unit exponent this normalizer knows; nothing else is converted. */
    public const CURRENCIES = ['USD'];

    public const SLUG_POLICIES = ['column', 'derive_from_title'];

    public const MAX_OFFERS = 10;

    /**
     * Decode and validate a mapping file. Throws InvalidArgumentException whose message is a
     * stable reason code (`mapping_*`) that carries no file contents.
     *
     * @return array{snapshot_id: string, source_system: string, operator_reference: string, acquisition_method: string,
     *     acquired_at: string, source_as_of: string, currency: string, slug_policy: string, columns: array<string, string>,
     *     constants: array<string, string>, visibility_values: array<string, string>, tag_separator: ?string,
     *     licenses: array<string, string>, offers: list<array{license: ?string, license_column: ?string, price_column: string}>,
     *     ignored_columns: list<string>}
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
            'acquisition_method', 'acquired_at', 'source_as_of', 'currency', 'slug_policy', 'columns', 'licenses', 'offers'],
            ['constants', 'visibility_values', 'tag_separator', 'ignored_columns'], 'mapping_keys');
        $this->require($mapping['schema_version'] === 1 && $mapping['purpose'] === self::PURPOSE, 'mapping_purpose');
        foreach (['snapshot_id', 'source_system', 'operator_reference'] as $field) {
            $this->require(CellText::identity($mapping[$field]), 'mapping_identity');
        }
        $this->require(in_array($mapping['acquisition_method'], self::ACQUISITION_METHODS, true), 'mapping_acquisition_method');
        $this->require($this->timestamp($mapping['acquired_at']) && $this->timestamp($mapping['source_as_of'])
            && $mapping['source_as_of'] <= $mapping['acquired_at'], 'mapping_timestamp');
        $this->require(in_array($mapping['currency'], self::CURRENCIES, true), 'mapping_currency');
        $this->require(in_array($mapping['slug_policy'], self::SLUG_POLICIES, true), 'mapping_slug_policy');

        $columns = $this->object($mapping['columns'], [], self::FIELDS, 'mapping_columns');
        foreach ($columns as $header) {
            $this->require($this->header($header), 'mapping_columns');
        }
        $constants = isset($mapping['constants']) ? $this->object($mapping['constants'], [], self::CONSTANTS, 'mapping_constants') : [];
        foreach ($constants as $field => $value) {
            $this->require(! isset($columns[$field]), 'mapping_constants');
            $this->require($field === 'artist' ? CellText::line($value, 255) : in_array($value, self::VISIBILITIES, true), 'mapping_constants');
        }
        foreach (['source_id', 'title', 'rights_reference'] as $field) {
            $this->require(isset($columns[$field]), 'mapping_required_field');
        }
        foreach (self::CONSTANTS as $field) {
            $this->require(isset($columns[$field]) || isset($constants[$field]), 'mapping_required_field');
        }
        $this->require(($mapping['slug_policy'] === 'column') === isset($columns['slug']), 'mapping_slug_policy');

        $visibilityValues = [];
        if (isset($columns['visibility'])) {
            $this->require(isset($mapping['visibility_values']) && $mapping['visibility_values'] instanceof stdClass, 'mapping_visibility_values');
            foreach (get_object_vars($mapping['visibility_values']) as $source => $target) {
                $this->require(CellText::line((string) $source, 80) && in_array($target, self::VISIBILITIES, true), 'mapping_visibility_values');
                $visibilityValues[(string) $source] = $target;
            }
            $this->require($visibilityValues !== [], 'mapping_visibility_values');
        } else {
            $this->require(! isset($mapping['visibility_values']), 'mapping_visibility_values');
        }

        $separator = null;
        if (isset($columns['tags'])) {
            $separator = $mapping['tag_separator'] ?? null;
            $this->require(is_string($separator) && strlen($separator) === 1 && preg_match('/\A[^\s"\x00-\x1f\x7f]\z/', $separator) === 1, 'mapping_tag_separator');
        } else {
            $this->require(! isset($mapping['tag_separator']), 'mapping_tag_separator');
        }

        $this->require($mapping['licenses'] instanceof stdClass, 'mapping_licenses');
        $licenses = [];
        foreach (get_object_vars($mapping['licenses']) as $name => $label) {
            $this->require(CellText::line((string) $name, 120) && is_string($label)
                && preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/D', $label) === 1, 'mapping_licenses');
            $licenses[(string) $name] = $label;
        }

        $this->require(is_array($mapping['offers']) && array_is_list($mapping['offers'])
            && $mapping['offers'] !== [] && count($mapping['offers']) <= self::MAX_OFFERS, 'mapping_offers');
        $offers = [];
        foreach ($mapping['offers'] as $item) {
            $offer = $this->object($item, ['price_column'], ['license', 'license_column'], 'mapping_offers');
            $this->require(isset($offer['license']) xor isset($offer['license_column']), 'mapping_offers');
            $this->require($this->header($offer['price_column']), 'mapping_offers');
            if (isset($offer['license'])) {
                $this->require(is_string($offer['license']) && isset($licenses[$offer['license']]), 'mapping_offers');
            } else {
                $this->require($this->header($offer['license_column']), 'mapping_offers');
            }
            $offers[] = ['license' => $offer['license'] ?? null, 'license_column' => $offer['license_column'] ?? null,
                'price_column' => $offer['price_column']];
        }

        $ignored = $mapping['ignored_columns'] ?? [];
        $this->require(is_array($ignored) && array_is_list($ignored) && count($ignored) <= 200, 'mapping_ignored_columns');
        foreach ($ignored as $header) {
            $this->require($this->header($header), 'mapping_ignored_columns');
        }

        // One header plays exactly one role: a column cannot be both ignored and mapped, or mapped twice.
        $roles = [...array_values($columns), ...$ignored];
        foreach ($offers as $offer) {
            $roles[] = $offer['price_column'];
            if ($offer['license_column'] !== null) {
                $roles[] = $offer['license_column'];
            }
        }
        $this->require(count(array_unique($roles, SORT_STRING)) === count($roles), 'mapping_column_reuse');

        return ['snapshot_id' => $mapping['snapshot_id'], 'source_system' => $mapping['source_system'],
            'operator_reference' => $mapping['operator_reference'], 'acquisition_method' => $mapping['acquisition_method'],
            'acquired_at' => $mapping['acquired_at'], 'source_as_of' => $mapping['source_as_of'],
            'currency' => $mapping['currency'], 'slug_policy' => $mapping['slug_policy'], 'columns' => $columns,
            'constants' => $constants, 'visibility_values' => $visibilityValues, 'tag_separator' => $separator,
            'licenses' => $licenses, 'offers' => $offers, 'ignored_columns' => array_values($ignored)];
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

    private function header(mixed $value): bool
    {
        return CellText::line($value, 255);
    }

    private function timestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
