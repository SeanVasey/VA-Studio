<?php

namespace App\Domain\Commerce\ProductionFinancialInspection;

use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use JsonSerializable;
use LogicException;

/** Original held-source validation identity. This proves neither commit nor provider retrieval. */
final readonly class FinancialExpectation implements JsonSerializable
{
    private function __construct(private array $identity, private array $lineHashes) {}

    public static function fromV1(ProductionPaidOrderSourceV1 $source, CurrentRows $reader): self
    {
        return self::extract($source, $reader, 1, 'production_checkout_v1');
    }

    public static function fromTaxV2(ProductionTaxPaidOrderSourceV2 $source, CurrentRows $reader): self
    {
        return self::extract($source, $reader, 2, 'production_tax_checkout_v2');
    }

    private static function extract(ProductionPaidOrderSourceV1|ProductionTaxPaidOrderSourceV2 $source,
        CurrentRows $reader, int $version, string $kind): self
    {
        $policy = FinancialInspectionPolicy::capture();
        $source->proveRetainedCurrent($reader);
        $policy->prove();
        $count = $source->lineCount();
        $policy->prove();
        FinancialInspectionException::require($count >= 1 && $count <= 10, 'source_binding');
        $identity = null;
        $hashes = [];
        for ($position = 1; $position <= $count; $position++) {
            $line = $source->line($position);
            $policy->prove();
            FinancialInspectionException::require(($line['schema_version'] ?? null) === $version
                && ($line['producer'] ?? null) === $kind, 'source_binding');
            $amounts = $version === 1 ? $line['amounts'] : $line['tax'];
            $tuple = array_intersect_key($line, array_flip(['order_id', 'order_hash', 'provider_account', 'funds_mode',
                'provider_payment_id', 'provenance', 'payment_evidence_origin',
                ...($version === 1 ? ['payment_id', 'payment_hash'] : ['request_id', 'request_hash', 'reviewed_session_id', 'reviewed_session_hash'])]));
            $tuple += ['source_kind' => $kind, 'schema_version' => $version, 'currency' => $amounts['currency'],
                'capture_method' => $line['execution_context']['capture_method'],
                'gross_minor' => $amounts[$version === 1 ? 'total_minor' : 'order_total_minor']];
            foreach ($tuple as $key => $value) {
                FinancialInspectionException::require($key === 'schema_version' || $key === 'gross_minor'
                    ? is_int($value) : is_string($value) && strlen($value) >= 1 && strlen($value) <= 160, 'source_binding');
            }
            FinancialInspectionException::require($tuple['currency'] === 'USD' && $tuple['funds_mode'] === 'test'
                && in_array($tuple['capture_method'], ['automatic', 'automatic_async'], true)
                && $tuple['gross_minor'] >= 1 && $tuple['gross_minor'] <= 99999999
                && FinancialObservation::id($tuple['provider_account'], 'acct')
                && FinancialObservation::id($tuple['provider_payment_id'], 'pi'), 'source_binding');
            FinancialInspectionException::require($identity === null || $identity === $tuple, 'source_binding');
            $id = $line['line_id'] ?? null;
            FinancialInspectionException::require(is_string($id) && strlen($id) <= 160 && ! isset($hashes[$id])
                && is_string($line['source_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $line['source_hash']) === 1, 'source_binding');
            $hashes[$id] = $line['source_hash'];
            $identity = $tuple;
        }
        $source->proveRetainedCurrent($reader);
        $policy->prove();

        return new self($identity, $hashes);
    }

    /** Validation tuple only; no buyer data, source graph, receipt or dispatch capability. */
    public function identity(): array
    {
        FinancialInspectionPolicy::capture()->prove();

        return $this->identity;
    }

    public function lineCount(): int
    {
        FinancialInspectionPolicy::capture()->prove();

        return count($this->lineHashes);
    }

    public function __clone(): never
    {
        throw new LogicException('Financial validation identities cannot be cloned.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Financial validation identities cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Financial validation identities cannot be reconstructed.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Financial validation identities cannot be JSON serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'validation_identity_only', 'providerAuthenticated' => false];
    }
}
