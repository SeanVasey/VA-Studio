<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionPreparation\AmountInputConsistencyV1;
use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ComparatorPrimitiveCanaryTest extends TestCase
{
    private function inputs(): array
    {
        $fixture = new ProductionAmountInputConsistencyTest('test_integer_cap_is_only_upper_bound_and_overflow_never_rounds');
        $requirements = (new \ReflectionMethod($fixture, 'requirements'))->invoke($fixture);
        $supplied = (new \ReflectionMethod($fixture, 'supplied'))->invoke($fixture, $requirements);

        return [$requirements, $supplied];
    }

    public function test_unknown_or_context_objects_are_refused_without_serialization(): void
    {
        [$r, $s] = $this->inputs();
        $calls = 0;
        $object = new class($calls) implements \JsonSerializable {
            private mixed $calls;
            public function __construct(mixed &$calls) { $this->calls =& $calls; }
            public function jsonSerialize(): mixed { $this->calls++; throw new \LogicException('Private serializer invoked'); }
        };
        foreach (['provider_receipt', 'context'] as $field) {
            $candidate = $s;
            $candidate[$field] = $object;
            try {
                AmountInputConsistencyV1::compare($r, $candidate);
                $this->fail('Untrusted serializer yielded a report');
            } catch (ValidationException $e) {
                $this->assertSame(0, $calls);
                $this->assertStringNotContainsString('Private serializer', CanonicalJson::encode($e->errors()));
            }
        }
    }

    public function test_valid_commitment_cannot_hide_changed_line_identity_or_grant_authority(): void
    {
        [$r, $s] = $this->inputs();
        $s['lines'][0]['offer_revision_id']++;
        $s['requirements_hash'] = CanonicalJson::hash($r);
        $report = AmountInputConsistencyV1::compare($r, $s);
        $this->assertSame('inconsistent', $report['consistency_state']);
        $this->assertSame('line_identity_mismatch', $report['reason']);
        foreach (['amount_minor', 'tax_minor', 'total_minor', 'amount_observation_id'] as $field) {
            $this->assertNull($report[$field]);
        }
        foreach (['payable', 'execution_allowed', 'external_facts_verified', 'provider_authenticated', 'payment_verified', 'buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'rights_granted', 'consent_verified'] as $field) {
            $this->assertFalse($report[$field]);
        }
        $this->assertSame('unobserved', $report['amount_evidence_state']);
        $this->assertTrue($report['requires_current_eligibility_proof']);
        $this->assertArrayNotHasKey('lines', $report);
        $this->assertArrayNotHasKey('supplied', $report);
    }
}
