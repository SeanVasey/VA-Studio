<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPreparation\AmountRequirementsV1;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\TestCase;

class ProductionAmountRequirementsTest extends TestCase
{
    /** Pure synthetic shape only: no retained/authenticated row or provider proof is claimed. */
    private function packet(string $strategy = 'provider_calculated', string $behavior = 'exclusive'): array
    {
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $machine['choices']['tax_calculation'] = ['strategy' => $strategy, 'behavior' => $behavior,
            'maximum_rate_bps' => $strategy === 'declared_exemption' ? 0 : 10000,
            'rounding' => $strategy === 'declared_exemption' ? 'not_applicable' : 'provider_exact'];
        $hash = str_repeat('a', 64);
        $uuid = '00000000-0000-4000-8000-000000000001';
        $context = PreparationContextV1::forMachine($machine);
        $item = ['trackId' => 1, 'offerId' => 2, 'licenseVersionId' => 3, 'offerRevisionId' => 4];
        $request = ['actor_id' => 1, 'candidate_id' => 1, 'context' => $context, 'items' => [$item], 'key_digest' => $hash];
        $snapshot = ['schema_version' => 1, 'product' => ['id' => 1], 'commercial' => ['price_minor' => 2147483647, 'currency' => 'USD', 'type' => 'non-exclusive']];
        $line = ['position' => 1, 'track_id' => 1, 'offer_id' => 2, 'offer_revision_id' => 4, 'license_version_id' => 3,
            'price_minor' => 2147483647, 'currency' => 'USD', 'offer_snapshot_hash' => CanonicalJson::hash($snapshot), 'offer_snapshot' => $snapshot];
        $graph = array_fill_keys(['tracks', 'offers', 'rights', 'media', 'bindings', 'revisions', 'licenses', 'templates', 'reviews', 'runs', 'outputs', 'audits'], []);
        $graph['revisions'] = [['id' => 4, 'price_minor' => $line['price_minor'], 'snapshot_hash' => $line['offer_snapshot_hash']]];

        return ['schema_version' => 1, 'purpose' => PacketEvidence::PURPOSE, 'public_id' => $uuid, 'created_by' => 1, 'created_at' => '2026-10-07 00:00:00',
            'request' => $request, 'request_hash' => CanonicalJson::hash($request), 'catalog_graph' => $graph, 'catalog_graph_hash' => CanonicalJson::hash($graph),
            'capability' => ['schema_version' => 1, 'purpose' => PreparationContextV1::PURPOSE, 'context' => $context,
                'source' => ['draft_id' => 1, 'draft_public_id' => $uuid, 'revision' => 1, 'version_id' => 1, 'version_cipher_hash' => $hash,
                    'source_review_id' => 1, 'review_cipher_hash' => $hash, 'source_graph_hash' => $hash],
                'candidate_id' => 1, 'candidate_public_id' => $uuid, 'candidate_hash' => $hash, 'generation' => 1, 'approval_id' => 1,
                'approval_hash' => $hash, 'machine_hash' => CanonicalJson::hash($machine), 'machine' => $machine,
                'external_facts_verified' => false, 'execution_allowed' => false, 'signature' => $hash],
            'selection' => ['currency' => 'USD', 'minor_unit_exponent' => 2, 'advertised_subtotal_minor' => 2147483647,
                'tax_minor' => null, 'total_minor' => null, 'tax_state' => 'unresolved', 'assent_state' => 'not_collected', 'buyer_state' => 'not_bound',
                'private_bytes_verified' => false, 'payable' => false, 'execution_allowed' => false, 'external_facts_verified' => false, 'lines' => [$line]]];
    }

    public static function choices(): array
    {
        return [['provider_calculated', 'exclusive'], ['provider_calculated', 'inclusive'], ['declared_exemption', 'exclusive'], ['declared_exemption', 'inclusive']];
    }

    #[DataProvider('choices')]
    public function test_exact_frozen_operands_and_requirements_never_infer_amounts(string $strategy, string $behavior): void
    {
        $packet = $this->packet($strategy, $behavior);
        $result = AmountRequirementsV1::project($packet);
        AmountRequirementsV1::requireMatches($result, $packet);
        $this->assertSame(CanonicalJson::hash($packet), $result['retained_body_hash']);
        $this->assertSame($packet['selection']['lines'], $result['lines']);
        $this->assertSame(2147483647, $result['advertised_subtotal_minor']);
        $this->assertSame($packet['capability']['machine']['choices']['tax_calculation'], $result['tax_requirements']);
        $this->assertSame($strategy === 'provider_calculated' ? 'authenticated_provider_calculation' : 'qualified_exemption_observation', $result['required_amount_evidence']);
        foreach (['tax_minor', 'total_minor', 'amount_observation_id'] as $field) {
            $this->assertNull($result[$field]);
        }
        foreach (['payable', 'execution_allowed', 'external_facts_verified', 'buyer_identity_verified', 'buyer_act_verified', 'purchase_bound'] as $field) {
            $this->assertFalse($result[$field]);
        }
        $this->assertTrue($result['requires_current_eligibility_proof']);
    }

    public static function malformed(): array
    {
        return [['schema_version', 2], ['extra', true], ['selection.extra', true], ['selection.tax_minor', 0], ['selection.total_minor', 2147483647],
            ['selection.payable', true], ['selection.external_facts_verified', 0], ['selection.advertised_subtotal_minor', '2147483647'],
            ['selection.advertised_subtotal_minor', 9007199254740992], ['selection.lines.0.price_minor', 2147483648],
            ['selection.lines.0.price_minor', 1.0], ['selection.lines.0.price_minor', 0], ['selection.lines.0.extra', 1],
            ['selection.lines.0.currency', 'EUR'], ['selection.lines.0.offer_snapshot_hash', str_repeat('b', 64)],
            ['request.items.0.trackId', '1'], ['request.context.extra', true], ['catalog_graph_hash', str_repeat('b', 64)],
            ['capability.machine.schema_version', 2], ['capability.machine.choices.tax_calculation.maximum_rate_bps', 10001],
            ['capability.machine.choices.tax_calculation.maximum_rate_bps', '2500'], ['capability.machine.choices.tax_calculation.extra', true],
            ['capability.source.extra', true], ['capability.source.revision', 257], ['capability.execution_allowed', true], ['capability.extra', true], ['request.items', 'invalid'], ['selection.lines', []], ['capability.machine', false],
            ['capability.context', false], ['capability.source.version_cipher_hash', 'invalid'],
            ['capability.machine.choices.tax_calculation.strategy', 'unknown'], ['capability.machine.choices.tax_calculation.behavior', 'unknown'],
            ['capability.machine.choices.tax_calculation.rounding', 'nearest'], ['capability.machine.choices.tax_calculation.maximum_rate_bps', -1]];
    }

    #[DataProvider('malformed')]
    public function test_closed_projection_rejects_unknown_coerced_or_tampered_packet_operands(string $path, mixed $value): void
    {
        $packet = $this->packet();
        data_set($packet, $path, $value);
        $this->expectException(ValidationException::class);
        AmountRequirementsV1::project($packet);
    }

    public function test_output_is_exact_and_cannot_be_reused_for_another_retained_body(): void
    {
        $packet = $this->packet();
        $result = AmountRequirementsV1::project($packet);
        foreach (['extra' => true, 'buyer_act_verified' => true, 'tax_minor' => 0, 'schema_version' => 2] as $field => $value) {
            try {
                AmountRequirementsV1::requireMatches(array_replace($result, [$field => $value]), $packet);
                $this->fail('Forged requirements accepted.');
            } catch (ValidationException) {
                $this->assertFalse($result['buyer_act_verified']);
            }
        }
        $packet['public_id'] = '00000000-0000-4000-8000-000000000002';
        $this->assertNotSame($result['retained_body_hash'], AmountRequirementsV1::project($packet)['retained_body_hash']);
        $this->expectException(ValidationException::class);
        AmountRequirementsV1::requireMatches($result, $packet);
    }
}
