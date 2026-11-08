<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ContractIdentity\ContractIdentityStatements;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Customers\ProductionCustomerAccess;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/** D-30 step 5: the tax checkout freezes the v2 contract identity behind a literal-false, local/testing-only gate. */
class ProductionTaxCheckoutContractIdentityTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
        $this->identitySetup();
    }

    private function checkout(): array
    {
        $catalog = $this->taxCatalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $checkout = new ProductionTaxCheckout(new ProductionCustomerAccess, new RecordingTaxCheckoutTransport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);

        return compact('catalog', 'buyer', 'checkout', 'preview');
    }

    private function order(array $f, array $declarations, string $key = 'synthetic-identity-order'): array
    {
        return $f['checkout']->order($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
            $f['preview']['previewHash'], true, $declarations, $key);
    }

    private static function identity(array $overrides = []): array
    {
        return array_replace(['licenseeType' => 'individual', 'legalName' => ' Zoë Émile ', 'legalNameConfirmed' => true,
            'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000']], $overrides);
    }

    private static function frozenDeclarations(): array
    {
        $row = (array) DB::table('production_tax_checkout_orders')->sole();

        return Evidence::open($row, 'production_tax_checkout_order')['buyer_declarations'];
    }

    private function refuses(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected refusal '.$reason);
        } catch (CheckoutException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_the_gate_is_a_literal_false_and_admits_only_local_or_testing(): void
    {
        $this->assertFalse((require base_path('config/production-tax-checkout.php'))['contract_identity_v2']);
        $this->assertFalse(TaxCheckoutPolicy::contractIdentityV2());
        config(['production-tax-checkout.contract_identity_v2' => 'true']);
        $this->assertFalse(TaxCheckoutPolicy::contractIdentityV2());
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->assertTrue(TaxCheckoutPolicy::contractIdentityV2());
        foreach (['staging', 'production'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertFalse(TaxCheckoutPolicy::contractIdentityV2(), $environment);
        }
        $this->app['env'] = 'testing';
    }

    public function test_with_the_gate_off_the_order_keeps_the_original_name_only_declaration(): void
    {
        $f = $this->checkout();
        $this->assertArrayNotHasKey('contractIdentity', $f['preview']);
        $this->refuses('invalid', fn () => $this->order($f, self::identity()));

        $this->order($f, ['legalName' => ' Declared synthetic buyer ']);
        $this->assertSame(['identity_meaning' => 'buyer_declared_legal_name', 'legal_name' => 'Declared synthetic buyer'], self::frozenDeclarations());
    }

    public function test_with_the_gate_on_an_individual_order_freezes_the_v2_identity_and_its_approved_statement(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        $this->assertSame(['licenseeTypes' => ['individual', 'business'], 'statements' => ContractIdentityStatements::offered()], $f['preview']['contractIdentity']);
        $this->refuses('invalid', fn () => $this->order($f, ['legalName' => 'Declared synthetic buyer']));

        $order = $this->order($f, self::identity());
        $frozen = self::frozenDeclarations();
        $this->assertSame('buyer-contract-identity-v2', $frozen['schema']);
        $this->assertSame(['individual', 'Zoë Émile', null, null], [$frozen['licensee_type'], $frozen['legal_name'], $frozen['representative_name'], $frozen['artist_name']]);
        $this->assertEquals(['confirmed' => true, 'statement_version' => 'individual-legal-name-confirmation-v1',
            'statement_hash' => hash('sha256', ContractIdentityStatements::TEXTS['individual']['text'])], $frozen['legal_name_confirmation']);

        // The same request replays to the same order; a changed identity under the same key refuses.
        $this->assertSame($order['orderId'], $this->order($f, self::identity())['orderId']);
        $this->refuses('changed', fn () => $this->order($f, self::identity(['legalName' => 'Someone Else'])));
        $this->assertSame(1, DB::table('production_tax_checkout_orders')->count());
    }

    public function test_with_the_gate_on_a_business_order_freezes_the_representative_and_the_business_statement(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        $this->order($f, self::identity(['licenseeType' => 'business', 'legalName' => 'Example Records LLC', 'representativeName' => 'Zoë Émile']));

        $frozen = self::frozenDeclarations();
        $this->assertSame(['business', 'Example Records LLC', 'Zoë Émile'], [$frozen['licensee_type'], $frozen['legal_name'], $frozen['representative_name']]);
        $this->assertSame('business-legal-name-confirmation-v1', $frozen['legal_name_confirmation']['statement_version']);
        $this->assertSame(hash('sha256', ContractIdentityStatements::TEXTS['business']['text']), $frozen['legal_name_confirmation']['statement_hash']);
    }

    public function test_with_the_gate_on_unprintable_or_exclusive_only_input_refuses_before_anything_is_written(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        $this->refuses('unsupported_characters', fn () => $this->order($f, self::identity(['legalName' => '王小明'])));
        // This checkout sells non-exclusive licenses only, so an artist name is refused.
        $this->refuses('invalid', fn () => $this->order($f, self::identity(['artistName' => 'DJ Example'])));
        $this->refuses('invalid', fn () => $this->order($f, self::identity(['legalNameConfirmed' => false])));
        $this->assertSame(0, DB::table('production_tax_checkout_orders')->count());
    }

    public function test_the_approved_statements_match_the_terms_draft_verbatim(): void
    {
        $terms = (string) file_get_contents(base_path('docs/legal/TERMS.md'));
        foreach (ContractIdentityStatements::TEXTS as $type => $statement) {
            $this->assertStringContainsString('"'.$statement['text'].'"', $terms, $type);
            $this->assertStringStartsWith($type.'-', $statement['statement_version']);
        }
    }
}
