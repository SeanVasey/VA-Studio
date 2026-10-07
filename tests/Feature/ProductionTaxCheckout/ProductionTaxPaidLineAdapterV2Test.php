<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidLineAdapterV2;
use App\Support\CanonicalJson;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The V2 adapter refuses V1-shaped and tampered input. Every value here is a NONBINDING synthetic line. */
class ProductionTaxPaidLineAdapterV2Test extends TestCase
{
    private static function sealed(array $line): array
    {
        unset($line['source_hash']);

        return [...$line, 'source_hash' => CanonicalJson::hash($line)];
    }

    /** Synthetic V2 line with every hash consistent; the journey test proves the producer emits this shape. */
    private static function line(array $replace = []): array
    {
        $buyer = ['schema_version' => 1, 'origin_id' => '00000000-0000-4000-8000-000000000003', 'provenance' => 'synthetic_rehearsal'];
        $product = ['title' => 'NONBINDING SYNTHETIC RECORDING'];
        $assent = ['version' => 'synthetic-assent-v1', 'accepted' => true];
        $license = ['name' => 'NONBINDING SYNTHETIC LICENSE', 'type' => 'non-exclusive'];
        $assets = [['id' => 1]];
        $inventory = ['kind' => 'unscoped_nonexclusive', 'selection_hash' => str_repeat('c', 64)];
        $order = '00000000-0000-4000-8000-000000000001';
        $lineId = '00000000-0000-4000-8000-000000000004';
        $line = ['schema_version' => 2, 'producer' => 'production_tax_checkout_v2', 'origin_key' => 'production_tax_checkout_v2:'.$order.':'.$lineId,
            'order_id' => $order, 'order_hash' => str_repeat('a', 64), 'line_id' => $lineId, 'line_hash' => str_repeat('b', 64),
            'request_id' => '00000000-0000-4000-8000-000000000005', 'request_hash' => str_repeat('d', 64),
            'reviewed_session_id' => '00000000-0000-4000-8000-000000000006', 'reviewed_session_hash' => str_repeat('e', 64),
            'provider_session_id' => 'cs_test_SYNTHETIC', 'provider_payment_id' => 'pi_SYNTHETIC', 'provider_account' => 'acct_SYNTHETIC',
            'funds_mode' => 'test', 'provenance' => 'synthetic_rehearsal', 'payment_evidence_origin' => 'synthetic_rehearsal', 'observed_at' => '2026-10-07T12:00:00Z',
            'buyer' => $buyer, 'buyer_binding_hash' => CanonicalJson::hash($buyer), 'buyer_declarations' => ['legal_name' => 'Declared synthetic buyer'],
            'product' => $product, 'product_hash' => CanonicalJson::hash($product), 'assent' => $assent, 'assent_hash' => CanonicalJson::hash($assent),
            'license_version_id' => 7, 'license' => $license, 'license_hash' => CanonicalJson::hash($license),
            'asset_revisions' => $assets, 'asset_revisions_hash' => CanonicalJson::hash($assets), 'candidate' => ['candidate_id' => 1],
            'execution_context' => ['funds_mode' => 'test'], 'inventory' => $inventory, 'inventory_hash' => CanonicalJson::hash($inventory),
            'pre_tax' => ['currency' => 'USD', 'line_amount_minor' => 4999, 'order_subtotal_minor' => 4999],
            'tax' => ['authority' => 'provider_calculated_buyer_reviewed', 'calculator' => 'stripe_checkout_automatic_tax',
                'automatic_tax' => ['enabled' => true, 'provider' => 'stripe', 'status' => 'complete'], 'tax_behavior' => 'exclusive', 'currency' => 'USD',
                'line_subtotal_minor' => 4999, 'line_tax_minor' => 437, 'line_total_minor' => 5436,
                'order_subtotal_minor' => 4999, 'order_tax_minor' => 437, 'order_total_minor' => 5436],
            'source_hash' => ''];

        return self::sealed(array_replace_recursive($line, $replace));
    }

    /** The exact key set the frozen ProductionPaidOrderSourceV1::line() emits. */
    private static function v1Line(): array
    {
        $line = self::line();
        $v1 = ['schema_version' => 1, 'producer' => 'production_checkout_v1', 'origin_key' => 'production_checkout_v1:'.$line['order_id'].':'.$line['line_id'],
            'order_id' => $line['order_id'], 'order_hash' => $line['order_hash'], 'line_id' => $line['line_id'], 'line_hash' => $line['line_hash'],
            'line_record_hash' => str_repeat('f', 64), 'payment_id' => '00000000-0000-4000-8000-000000000007', 'payment_hash' => str_repeat('9', 64),
            'provider_payment_id' => 'pi_SYNTHETIC', 'provider_account' => 'acct_SYNTHETIC', 'funds_mode' => 'test', 'provenance' => 'synthetic_rehearsal',
            'payment_evidence_origin' => 'synthetic_rehearsal', 'observed_at' => '2026-10-07T12:00:00Z', 'buyer' => $line['buyer'],
            'buyer_binding_hash' => $line['buyer_binding_hash'], 'buyer_declarations' => $line['buyer_declarations'], 'product' => $line['product'],
            'product_hash' => $line['product_hash'], 'assent' => $line['assent'], 'assent_hash' => $line['assent_hash'], 'license_version_id' => 7,
            'license' => $line['license'], 'license_hash' => $line['license_hash'], 'asset_revisions' => $line['asset_revisions'],
            'asset_revisions_hash' => $line['asset_revisions_hash'], 'amounts' => ['currency' => 'USD', 'subtotal_minor' => 4999, 'tax_minor' => 0, 'total_minor' => 4999],
            'line_amount_minor' => 4999, 'line_tax_minor' => 0, 'candidate' => [], 'execution_context' => [], 'inventory' => $line['inventory'],
            'inventory_hash' => $line['inventory_hash']];

        return [...$v1, 'source_hash' => CanonicalJson::hash($v1)];
    }

    public function test_a_consistent_v2_line_is_returned_unchanged(): void
    {
        $line = self::line();
        $this->assertSame($line, ProductionTaxPaidLineAdapterV2::accept($line, 'synthetic_rehearsal'));
        $inclusive = self::line(['tax' => ['tax_behavior' => 'inclusive', 'line_total_minor' => 4999, 'order_total_minor' => 4999]]);
        $this->assertSame($inclusive, ProductionTaxPaidLineAdapterV2::accept($inclusive, 'synthetic_rehearsal'));
    }

    public function test_v1_shaped_lines_are_refused_and_never_routed_as_v2(): void
    {
        $v1 = self::v1Line();
        $this->assertFalse(ProductionTaxPaidLineAdapterV2::isV2($v1));
        foreach ([$v1, [...$v1, 'schema_version' => 2], self::sealed([...$v1, 'schema_version' => 2, 'producer' => 'production_tax_checkout_v2'])] as $candidate) {
            try {
                ProductionTaxPaidLineAdapterV2::accept($candidate, 'synthetic_rehearsal');
                $this->fail('A V1-shaped line was accepted by the V2 adapter.');
            } catch (CheckoutException $error) {
                $this->assertSame(['source_version', 409], [$error->reason, $error->status]);
            }
        }
        // Grafting the V1 tax field onto an otherwise valid V2 line is still refused.
        try {
            ProductionTaxPaidLineAdapterV2::accept(self::sealed([...self::line(), 'line_tax_minor' => 437]), 'synthetic_rehearsal');
            $this->fail('A V1 tax field was accepted beside V2 tax facts.');
        } catch (CheckoutException $error) {
            $this->assertSame('source_version', $error->reason);
        }
    }

    public static function refusals(): array
    {
        return [
            'tampered without resealing' => [fn (array $l): array => [...$l, 'provider_payment_id' => 'pi_OTHER']],
            'missing key' => [function (array $l): array {
                unset($l['reviewed_session_hash']);

                return self::sealed($l);
            }],
            'extra key' => [fn (array $l): array => self::sealed([...$l, 'client_total_minor' => 1])],
            'exclusive total is not subtotal plus tax' => [fn (array $l): array => self::sealed(array_replace_recursive($l, ['tax' => ['line_total_minor' => 5435]]))],
            'inclusive tax exceeds its total' => [fn (array $l): array => self::sealed(array_replace_recursive($l, ['tax' => ['tax_behavior' => 'inclusive',
                'line_total_minor' => 4999, 'order_total_minor' => 4999, 'line_tax_minor' => 5000, 'order_tax_minor' => 5000]]))],
            'string money' => [fn (array $l): array => self::sealed(array_replace_recursive($l, ['tax' => ['line_tax_minor' => '437']]))],
            'tax subtotal differs from pre-tax line' => [fn (array $l): array => self::sealed(array_replace_recursive($l, ['pre_tax' => ['line_amount_minor' => 4998]]))],
            'incomplete provider calculation' => [fn (array $l): array => self::sealed(array_replace_recursive($l, ['tax' => ['automatic_tax' => ['status' => 'failed']]]))],
            'non-USD currency' => [fn (array $l): array => self::sealed(array_replace_recursive($l, ['tax' => ['currency' => 'EUR']]))],
            'live funds under rehearsal' => [fn (array $l): array => self::sealed([...$l, 'funds_mode' => 'live'])],
            'exclusive license' => [fn (array $l): array => self::sealed([...$l, 'license' => ['type' => 'exclusive'], 'license_hash' => CanonicalJson::hash(['type' => 'exclusive'])])],
            'mismatched product hash' => [fn (array $l): array => self::sealed([...$l, 'product_hash' => str_repeat('0', 64)])],
            'foreign origin key' => [fn (array $l): array => self::sealed([...$l, 'origin_key' => 'production_checkout_v1:'.$l['order_id'].':'.$l['line_id']])],
        ];
    }

    #[DataProvider('refusals')]
    public function test_tampered_or_inconsistent_v2_lines_are_refused(Closure $mutation): void
    {
        try {
            ProductionTaxPaidLineAdapterV2::accept($mutation(self::line()), 'synthetic_rehearsal');
            $this->fail('An inconsistent V2 line was accepted.');
        } catch (CheckoutException $error) {
            $this->assertSame(409, $error->status);
        }
    }
}
