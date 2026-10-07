<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionPolicy\PreparationContextV1;
use App\Domain\Commerce\ProductionPreparation\AmountInputConsistencyV1;
use App\Domain\Commerce\ProductionPreparation\AmountRequirementsV1;
use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use App\Support\CanonicalJson;
use App\Support\Money\MinorUnits;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\TestCase;

class ProductionAmountInputConsistencyTest extends TestCase
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

    private function requirements(string $strategy = 'provider_calculated', string $behavior = 'exclusive', int $count = 1, int $price = 10000): array
    {
        $packet = $this->packet($strategy, $behavior);
        $lines = [];
        $items = [];
        $revisions = [];
        for ($i = 1; $i <= $count; $i++) {
            $snapshot = ['schema_version' => 1, 'product' => ['id' => $i], 'commercial' => ['price_minor' => $price, 'currency' => 'USD', 'type' => 'non-exclusive']];
            $items[] = ['trackId' => $i, 'offerId' => $i + 10, 'licenseVersionId' => $i + 20, 'offerRevisionId' => $i + 30];
            $lines[] = ['position' => $i, 'track_id' => $i, 'offer_id' => $i + 10, 'license_version_id' => $i + 20, 'offer_revision_id' => $i + 30,
                'price_minor' => $price, 'currency' => 'USD', 'offer_snapshot' => $snapshot, 'offer_snapshot_hash' => CanonicalJson::hash($snapshot)];
            $revisions[] = ['id' => $i + 30, 'price_minor' => $price, 'snapshot_hash' => CanonicalJson::hash($snapshot)];
        }
        $packet['selection']['lines'] = $lines;
        $packet['selection']['advertised_subtotal_minor'] = $price * $count;
        $packet['request']['items'] = $items;
        $packet['request_hash'] = CanonicalJson::hash($packet['request']);
        $packet['catalog_graph']['revisions'] = $revisions;
        $packet['catalog_graph_hash'] = CanonicalJson::hash($packet['catalog_graph']);

        return AmountRequirementsV1::project($packet);
    }

    private function supplied(array $r, ?int $taxPerLine = null): array
    {
        $taxPerLine ??= $r['tax_requirements']['strategy'] === 'declared_exemption' ? 0 : 2000;
        $lines = [];
        foreach ($r['lines'] as $line) {
            $net = $r['tax_requirements']['behavior'] === 'exclusive' ? $line['price_minor'] : $line['price_minor'] - $taxPerLine;
            $lines[] = array_intersect_key($line, array_flip(['position', 'track_id', 'offer_id', 'offer_revision_id', 'license_version_id', 'offer_snapshot_hash']))
                + ['quantity' => 1, 'advertised_price_minor' => $line['price_minor'], 'supplied_net_minor' => $net,
                    'supplied_tax_minor' => $taxPerLine, 'supplied_gross_minor' => $net + $taxPerLine];
        }

        return ['schema_version' => 1, 'purpose' => AmountInputConsistencyV1::INPUT_PURPOSE, 'packet_public_id' => $r['packet_public_id'],
            'retained_body_hash' => $r['retained_body_hash'], 'requirements_hash' => CanonicalJson::hash($r), 'context' => $r['context'],
            'currency' => $r['currency'], 'minor_unit_exponent' => $r['minor_unit_exponent'], 'tax_requirements' => $r['tax_requirements'],
            'advertised_subtotal_minor' => $r['advertised_subtotal_minor'], 'supplied_net_subtotal_minor' => array_sum(array_column($lines, 'supplied_net_minor')),
            'supplied_tax_minor' => array_sum(array_column($lines, 'supplied_tax_minor')), 'supplied_gross_total_minor' => array_sum(array_column($lines, 'supplied_gross_minor')), 'lines' => $lines];
    }

    private function unknownAuthority(array $report): void
    {
        foreach (['amount_minor', 'tax_minor', 'total_minor', 'amount_observation_id'] as $field) {
            $this->assertNull($report[$field]);
        }
        foreach (['payable', 'execution_allowed', 'external_facts_verified', 'provider_authenticated', 'payment_verified',
            'buyer_identity_verified', 'buyer_act_verified', 'purchase_bound', 'rights_granted', 'consent_verified'] as $field) {
            $this->assertFalse($report[$field]);
        }
        $this->assertSame('unobserved', $report['amount_evidence_state']);
        $this->assertTrue($report['requires_current_eligibility_proof']);
        $this->assertArrayNotHasKey('lines', $report);
        $this->assertArrayNotHasKey('supplied', $report);
    }

    public static function choices(): array
    {
        return [['provider_calculated', 'exclusive'], ['provider_calculated', 'inclusive'], ['declared_exemption', 'exclusive'], ['declared_exemption', 'inclusive']];
    }

    #[DataProvider('choices')]
    public function test_consistent_fiction_never_establishes_provider_tax_exemption_or_purchase(string $strategy, string $behavior): void
    {
        $r = $this->requirements($strategy, $behavior, 10);
        $s = $this->supplied($r);
        $before = [$r, $s];
        $report = AmountInputConsistencyV1::compare($r, $s);
        $this->assertSame('internally_consistent', $report['consistency_state']);
        $this->assertNull($report['reason']);
        $this->assertSame(CanonicalJson::hash($s), $report['supplied_input_hash']);
        $this->assertSame($before, [$r, $s]);
        $this->unknownAuthority($report);
    }

    public static function malformed(): array
    {
        return [['schema_version', 2], ['purpose', 'verified_receipt'], ['buyer.email', 'private@example.test'], ['provider_receipt', ['paid' => true]],
            ['provider_authenticated', true], ['payment_verified', true], ['buyer_act_verified', true], ['consent_verified', true],
            ['lines.0.email', 'private@example.test'], ['lines.0.supplied_net_minor', 1.0], ['lines.0.supplied_tax_minor', '2000'],
            ['lines.0.supplied_tax_minor', null], ['lines.0.supplied_gross_minor', true], ['lines.0.quantity', 2], ['lines.0.track_id', 0],
            ['lines.0.offer_snapshot_hash', 'invalid'], ['supplied_tax_minor', -1], ['supplied_gross_total_minor', MinorUnits::MAX + 1],
            ['retained_body_hash', 'invalid'], ['context', false], ['context.email', 'private@example.test'],
            ['context.account_id', 'private@example.test'], ['context.assent_version', str_repeat('p', 16385)],
            ['tax_requirements.maximum_rate_bps', '10000'], ['tax_requirements.rounding', 'nearest'], ['tax_requirements.extra', true],
            ['lines', []], ['lines.0.supplied_net_minor', new \stdClass]];
    }

    #[DataProvider('malformed')]
    public function test_unknown_private_provider_or_coerced_inputs_refuse_before_report(string $path, mixed $value): void
    {
        $r = $this->requirements();
        $s = $this->supplied($r);
        data_set($s, $path, $value);
        try {
            AmountInputConsistencyV1::compare($r, $s);
            $this->fail('Malformed input produced a report.');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString('private@example.test', json_encode($e->errors(), JSON_THROW_ON_ERROR));
            $this->assertArrayHasKey('capability', $e->errors());
        }
    }

    public static function mismatch(): array
    {
        return [['packet_public_id', '00000000-0000-4000-8000-000000000002', 'commitment_mismatch'],
            ['retained_body_hash', str_repeat('b', 64), 'commitment_mismatch'], ['requirements_hash', str_repeat('b', 64), 'commitment_mismatch'],
            ['lines.0.track_id', 99, 'line_identity_mismatch'], ['lines.0.offer_id', 99, 'line_identity_mismatch'],
            ['lines.0.offer_revision_id', 99, 'line_identity_mismatch'], ['lines.0.license_version_id', 99, 'line_identity_mismatch'],
            ['lines.0.offer_snapshot_hash', str_repeat('b', 64), 'line_identity_mismatch'],
            ['lines.0.advertised_price_minor', 9999, 'advertised_price_mismatch'], ['lines.0.supplied_gross_minor', 9999, 'line_arithmetic_mismatch'],
            ['supplied_net_subtotal_minor', 9999, 'aggregate_arithmetic_mismatch'], ['supplied_tax_minor', 9999, 'aggregate_arithmetic_mismatch'],
            ['supplied_gross_total_minor', 9999, 'aggregate_arithmetic_mismatch'], ['advertised_subtotal_minor', 9999, 'aggregate_arithmetic_mismatch'],
            ['currency', 'EUR', 'tax_context_mismatch'], ['tax_requirements.maximum_rate_bps', 9999, 'tax_context_mismatch']];
    }

    #[DataProvider('mismatch')]
    public function test_fixed_inconsistent_verdict_retains_unknown_authority(string $path, mixed $value, string $reason): void
    {
        $r = $this->requirements();
        $s = $this->supplied($r);
        data_set($s, $path, $value);
        $report = AmountInputConsistencyV1::compare($r, $s);
        $this->assertSame('inconsistent', $report['consistency_state']);
        $this->assertSame($reason, $report['reason']);
        $this->unknownAuthority($report);
    }

    public function test_integer_cap_is_only_upper_bound_and_overflow_never_rounds(): void
    {
        $r = $this->requirements(price: 1);
        $r['tax_requirements']['maximum_rate_bps'] = 1;
        $s = $this->supplied($r, 1);
        $this->assertSame('internally_consistent', AmountInputConsistencyV1::compare($r, $s)['consistency_state']);
        $s = $this->supplied($r, 2);
        $this->assertSame('tax_constraint_mismatch', AmountInputConsistencyV1::compare($r, $s)['reason']);
        $s = $this->supplied($r, 0);
        $s['lines'][0]['supplied_net_minor'] = MinorUnits::MAX;
        $s['lines'][0]['supplied_tax_minor'] = 1;
        $s['lines'][0]['supplied_gross_minor'] = MinorUnits::MAX;
        $this->assertSame('line_arithmetic_mismatch', AmountInputConsistencyV1::compare($r, $s)['reason']);
        $r = $this->requirements(count: 10, price: 2147483647);
        $report = AmountInputConsistencyV1::compare($r, $this->supplied($r, 0));
        $this->assertSame('internally_consistent', $report['consistency_state']);
        $this->unknownAuthority($report);
    }

    public function test_duplicate_reordered_missing_and_unbounded_line_sets_never_match(): void
    {
        $r = $this->requirements(count: 2);
        $s = $this->supplied($r);
        $missing = $s;
        array_pop($missing['lines']);
        $this->assertSame('line_identity_mismatch', AmountInputConsistencyV1::compare($r, $missing)['reason']);
        $reordered = $s;
        $reordered['lines'] = array_reverse($reordered['lines']);
        try {
            AmountInputConsistencyV1::compare($r, $reordered);
            $this->fail('Reordered positions accepted.');
        } catch (ValidationException) {
            $this->assertCount(2, $s['lines']);
        }
        $duplicate = $s;
        $duplicate['lines'][1]['track_id'] = $duplicate['lines'][0]['track_id'];
        try {
            AmountInputConsistencyV1::compare($r, $duplicate);
            $this->fail('Duplicate track accepted.');
        } catch (ValidationException) {
            $this->assertCount(2, $s['lines']);
        }
        $s['lines'] = array_fill(0, 11, $s['lines'][0]);
        $this->expectException(ValidationException::class);
        AmountInputConsistencyV1::compare($r, $s);
    }

    public function test_zero_and_maximum_rate_edges_require_explicit_untrusted_operands(): void
    {
        $r = $this->requirements();
        $report = AmountInputConsistencyV1::compare($r, $this->supplied($r, 10000));
        $this->assertSame('internally_consistent', $report['consistency_state']);
        $this->unknownAuthority($report);
        $r = $this->requirements(behavior: 'inclusive');
        $report = AmountInputConsistencyV1::compare($r, $this->supplied($r, 5000));
        $this->assertSame('internally_consistent', $report['consistency_state']);
        $this->unknownAuthority($report);
        $r['tax_requirements']['maximum_rate_bps'] = 0;
        $report = AmountInputConsistencyV1::compare($r, $this->supplied($r, 0));
        $this->assertSame('internally_consistent', $report['consistency_state']);
        $this->unknownAuthority($report);
        $this->assertSame('tax_constraint_mismatch', AmountInputConsistencyV1::compare($r, $this->supplied($r, 1))['reason']);
    }
}
