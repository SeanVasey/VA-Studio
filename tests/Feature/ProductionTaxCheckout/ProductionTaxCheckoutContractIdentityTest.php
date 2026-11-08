<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ContractIdentity\ContractIdentityStatements;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutEvidence;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutRecords;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutSchema;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutContractIdentityForgery;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/**
 * D-30 step 5: the tax checkout freezes the v2 contract identity behind a literal-false, local/testing-only gate. Step 6
 * binds the confirmation statement the buyer was shown (the echoed `statementVersion`) and the gate state (the preview
 * commitment) to the order. Step 8: `initiate` refuses a declaration that does not match the gate (decided 2026-11-08: in both
 * directions), and a v2 declaration that no longer verifies, before any transport call, except that a name-only order already
 * paid with the gate on answers its status; reconciliation is unchanged.
 */
class ProductionTaxCheckoutContractIdentityTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutContractIdentityForgery;
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

    /** Checkout input that echoes the statement the preview displayed for its licensee type, unless the override names one. */
    private static function identity(array $overrides = []): array
    {
        $identity = array_replace(['licenseeType' => 'individual', 'legalName' => ' Zoë Émile ', 'legalNameConfirmed' => true,
            'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000']], $overrides);

        return $identity + ['statementVersion' => ContractIdentityStatements::offered()[$identity['licenseeType']]['statementVersion']];
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
        $this->refuses('invalid', fn () => $this->order($f, self::identity(['licenseeType' => 'business', 'legalName' => 'Example Records LLC'])));
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

    public function test_with_the_gate_on_latin_extended_names_order_and_scripts_not_in_every_contract_face_refuse(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        // The printable set is the vasey-license-pdf-v2 faces (decision register, 2026-11-08): Greek, Cyrillic and
        // Vietnamese are refused at checkout rather than printed wrongly.
        foreach (['Иван Петров', 'Ελένη Παπαδοπούλου', 'Nguyễn Văn An'] as $name) {
            $this->refuses('unsupported_characters', fn () => $this->order($f, self::identity(['legalName' => $name])));
        }
        $this->refuses('unsupported_characters', fn () => $this->order($f, self::identity(['licenseeType' => 'business',
            'legalName' => 'Example Records LLC', 'representativeName' => 'Анна Иванова'])));
        $this->assertSame(0, DB::table('production_tax_checkout_orders')->count());

        $this->order($f, self::identity(['licenseeType' => 'business', 'legalName' => 'Wydawnictwo Łódź Sp. z o.o.', 'representativeName' => 'Ștefan Țepeș']));
        $frozen = self::frozenDeclarations();
        $this->assertSame(['Wydawnictwo Łódź Sp. z o.o.', 'Ștefan Țepeș'], [$frozen['legal_name'], $frozen['representative_name']]);
    }

    public function test_with_the_gate_off_the_preview_commitment_request_and_order_bytes_are_the_original_ones(): void
    {
        $f = $this->checkout();
        $order = $this->order($f, ['legalName' => ' Declared synthetic buyer ']);
        $row = (array) DB::table('production_tax_checkout_orders')->sole();
        $body = Evidence::open($row, 'production_tax_checkout_order');

        // The original ten-key commitment, spelled out here rather than through TaxCheckoutEvidence::commitment().
        $commitment = ['schema_version' => 1, 'purpose' => 'production_tax_checkout_preview', 'buyer_origin_id' => $body['buyer']['origin_id'],
            'buyer_provenance' => $body['buyer']['provenance'], 'candidate' => $body['candidate'], 'execution_context' => $body['execution_context'],
            'selection_hash' => $body['selection_hash'], 'seller' => $body['machine']['choices']['seller_identity'], 'assent' => $body['machine']['choices']['assent'],
            'amounts' => ['currency' => 'USD', 'subtotal_minor' => $body['amounts']['subtotal_minor'], 'tax' => 'calculated_by_provider_at_hosted_checkout',
                'total' => 'reviewed_by_buyer_at_hosted_checkout']];
        $this->assertSame(CanonicalJson::hash($commitment), $f['preview']['previewHash']);
        // The original preview response, byte for byte.
        $preview = ['candidateId' => $body['candidate']['candidate_id'], 'seller' => $commitment['seller'], 'assent' => $commitment['assent'],
            'amounts' => $commitment['amounts'], 'fundsMode' => $body['execution_context']['funds_mode'], 'taxBehavior' => $body['execution_context']['tax']['behavior'],
            'licenses' => array_map(fn (array $line): array => ['position' => $line['position'], 'trackId' => $line['track_id'],
                'offerRevisionId' => $line['offer_revision_id'], 'trackTitle' => $line['offer_snapshot']['product']['title'],
                'artist' => $line['offer_snapshot']['product']['artist'], 'licenseVersionId' => $line['license_version_id'],
                'priceMinor' => $line['price_minor'], 'license' => array_intersect_key($line['offer_snapshot']['license'],
                    array_flip(['name', 'version', 'authored_source', 'source_hash', 'structured_terms', 'features']))], $body['selection']['selection']['lines']),
            'previewHash' => $f['preview']['previewHash']];
        // Stored snapshots are canonical (sorted) while the live response keeps source order, so the top-level key order is
        // compared exactly and the content as canonical bytes.
        $this->assertSame(array_keys($preview), array_keys($f['preview']));
        $this->assertSame(CanonicalJson::encode($preview), CanonicalJson::encode($f['preview']));
        // The original request bytes and hash, and the original order body keys and declaration bytes.
        $request = ['candidate_id' => $f['catalog']['candidate']->id, 'items' => PreparationSelection::items($f['catalog']['items']),
            'preview_hash' => $f['preview']['previewHash'], 'accepted' => true, 'buyer_origin_id' => $body['buyer']['origin_id'],
            'buyer_declarations' => ['legal_name' => 'Declared synthetic buyer', 'identity_meaning' => 'buyer_declared_legal_name'],
            'key_digest' => Evidence::key('synthetic-identity-order')];
        $this->assertSame(CanonicalJson::encode($request), CanonicalJson::encode($body['request']));
        $this->assertSame(CanonicalJson::hash($request), $row['request_hash']);
        $this->assertSame(CanonicalJson::encode($request['buyer_declarations']), CanonicalJson::encode($body['buyer_declarations']));
        // The sealed body is canonical JSON, so its key set fixes its bytes' layout: exactly the original twenty keys.
        $this->assertEqualsCanonicalizing(['schema_version', 'purpose', 'public_id', 'created_at', 'request', 'request_hash', 'preview_hash', 'buyer',
            'buyer_declarations', 'candidate', 'machine', 'execution_context', 'selection', 'selection_hash', 'seller', 'assent', 'amounts', 'lines',
            'inventory', 'expires_at'], array_keys($body));
        $this->assertSame(['orderId', 'createdAt', 'amounts', 'assentAccepted', 'fundsMode', 'paymentStatus', 'fulfillmentStatus'], array_keys($order));
    }

    public function test_with_the_gate_on_the_order_must_echo_the_statement_the_preview_displayed(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        // A different version than the one displayed is what a wording change between preview and order looks like here.
        $this->refuses('changed', fn () => $this->order($f, self::identity(['statementVersion' => 'individual-legal-name-confirmation-v0'])));
        $this->refuses('changed', fn () => $this->order($f, self::identity(['statementVersion' => ContractIdentityStatements::offered()['business']['statementVersion']])));
        $this->refuses('invalid', fn () => $this->order($f, array_diff_key(self::identity(), ['statementVersion' => true])));
        $this->refuses('invalid', fn () => $this->order($f, self::identity(['statementVersion' => null])));
        $this->assertSame(0, DB::table('production_tax_checkout_orders')->count());
    }

    public function test_a_gate_flip_between_preview_and_order_refuses_in_either_direction(): void
    {
        $f = $this->checkout();
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->refuses('changed', fn () => $this->order($f, self::identity()));

        $on = $this->checkout2($f);
        config(['production-tax-checkout.contract_identity_v2' => false]);
        $this->refuses('changed', fn () => $this->order($on, ['legalName' => 'Declared synthetic buyer']));
        $this->refuses('invalid', fn () => $this->order($on, self::identity()));
        $this->assertSame(0, DB::table('production_tax_checkout_orders')->count());
        $this->assertNotSame($f['preview']['previewHash'], $on['preview']['previewHash']);
    }

    public function test_replay_after_a_gate_flip_refuses_and_writes_nothing_until_the_gate_is_restored(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        $order = $this->order($f, self::identity());
        config(['production-tax-checkout.contract_identity_v2' => false]);
        $this->refuses('invalid', fn () => $this->order($f, self::identity()));
        $this->refuses('changed', fn () => $this->order($f, ['legalName' => 'Zoë Émile']));
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->assertSame($order['orderId'], $this->order($f, self::identity())['orderId']);
        $this->assertSame(1, DB::table('production_tax_checkout_orders')->count());
    }

    public function test_legacy_order_evidence_requires_the_body_declarations_to_equal_the_requests(): void
    {
        $legacy = $this->checkout();
        $this->order($legacy, ['legalName' => 'Declared synthetic buyer']);
        $row = (array) DB::table('production_tax_checkout_orders')->sole();
        $this->assertSame($row['public_id'], self::openOrder($row)['row']['public_id']);
        $body = Evidence::open($row, 'production_tax_checkout_order');
        $this->refusesEvidence(self::resealed($row, [...$body, 'buyer_declarations' => ['legal_name' => 'Someone Else', 'identity_meaning' => 'buyer_declared_legal_name']]));
    }

    public function test_v2_order_evidence_requires_equal_declarations_and_reverifies_them_when_opened(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->checkout();
        $this->order($f, self::identity());
        $row = (array) DB::table('production_tax_checkout_orders')->sole();
        $body = Evidence::open($row, 'production_tax_checkout_order');
        $this->assertSame($body['buyer_declarations'], self::openOrder($row)['body']['buyer_declarations']);
        $this->refusesEvidence(self::resealed($row, [...$body, 'buyer_declarations' => [...$body['buyer_declarations'], 'legal_name' => 'Someone Else']]));
        // Consistent in the request and the body, so only the re-verification can refuse it: the name cannot be printed.
        // Cyrillic prints in the test-v2 DejaVu faces but not in the vasey-license-pdf-v2 faces checkout is bound to.
        foreach (['王小明', 'Иван Петров'] as $name) {
            $unprintable = [...$body['buyer_declarations'], 'legal_name' => $name];
            $request = [...$body['request'], 'buyer_declarations' => $unprintable];
            $consistent = [...self::resealed($row, [...$body, 'request' => $request, 'request_hash' => CanonicalJson::hash($request),
                'buyer_declarations' => $unprintable]), 'request_hash' => CanonicalJson::hash($request)];
            $this->refusesEvidence($consistent);
            // D-30 (decided 2026-11-08): the retrieve/record paths open it without re-verification, and only those.
            $this->assertSame($name, self::openOrder($consistent, false)['body']['buyer_declarations']['legal_name']);
        }
        // Without re-verification everything else is still enforced: the seal, the hashes and body == request declarations.
        $this->refusesEvidence(self::resealed($row, [...$body, 'buyer_declarations' => [...$body['buyer_declarations'], 'legal_name' => 'Someone Else']]), false);
        $this->refusesEvidence([...$row, 'payload_hash' => hash('sha256', 'tampered')], false, 'corrupt');
        $this->refusesEvidence([...$row, 'request_hash' => hash('sha256', 'tampered')], false);
    }

    /** A second preview for the same buyer and catalog, under the gate as it is now. */
    private function checkout2(array $f): array
    {
        return [...$f, 'preview' => $f['checkout']->preview($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'])];
    }

    private static function openOrder(array $row, bool $reverify = true): array
    {
        return CommandTransaction::run(fn (Records $rows): array => TaxCheckoutEvidence::order(TaxCheckoutRecords::writer($rows), $row, $reverify));
    }

    private static function resealed(array $row, array $body): array
    {
        return [...$row, ...Evidence::seal($body)];
    }

    private function refusesEvidence(array $row, bool $reverify = true, string $reason = 'changed'): void
    {
        try {
            self::openOrder($row, $reverify);
            $this->fail('Expected the tampered order evidence to refuse.');
        } catch (CheckoutException $e) {
            $this->assertSame([$reason, 409], [$e->reason, $e->status]);
        }
    }

    public function test_the_approved_statements_match_the_terms_draft_verbatim(): void
    {
        $terms = (string) file_get_contents(base_path('docs/legal/TERMS.md'));
        foreach (ContractIdentityStatements::TEXTS as $type => $statement) {
            $this->assertStringContainsString('"'.$statement['text'].'"', $terms, $type);
            $this->assertStringStartsWith($type.'-', $statement['statement_version']);
        }
    }

    /** A checkout whose recording transport (synthetic, in-process) is kept, and admitted once a test binds the provider. */
    private function payable(): array
    {
        $catalog = $this->taxCatalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $transport = new RecordingTaxCheckoutTransport;
        $checkout = new ProductionTaxCheckout(new ProductionCustomerAccess, $transport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);

        return compact('catalog', 'buyer', 'checkout', 'preview', 'transport');
    }

    private function initiate(array $f, string $orderId): array
    {
        return $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $orderId);
    }

    private function reconcile(array $f, string $orderId): array
    {
        return $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $orderId);
    }

    private static function paymentCounts(): array
    {
        return [DB::table(TaxCheckoutSchema::TABLES['request'])->count(), DB::table(TaxCheckoutSchema::TABLES['reviewed'])->count()];
    }

    public function test_with_the_gate_on_initiate_and_reconcile_pay_a_valid_v2_order(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->payable();
        $order = $this->order($f, self::identity());
        self::configureTax(self::SYNTHETIC_PROVIDER);

        $open = $this->initiate($f, $order['orderId']);
        $this->assertSame(['open', 'unverified'], [$open['checkoutStatus'], $open['paymentStatus']]);
        $this->assertSame(['create', 'retrieve'], array_column($f['transport']->calls, 0));

        // Reconciliation (unchanged by step 8) retains the paid session of a v2 order with the gate on.
        $f['transport']->paid = true;
        $paid = $this->reconcile($f, $order['orderId']);
        $this->assertSame(['complete', 'verified', 'pending_fulfillment'], [$paid['checkoutStatus'], $paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame([1, 1], self::paymentCounts());
        $this->assertSame('buyer-contract-identity-v2', self::frozenDeclarations()['schema']);
    }

    public function test_initiate_refuses_a_v2_order_whose_declaration_no_longer_verifies_before_any_provider_call(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->payable();
        $this->order($f, self::identity());
        self::configureTax(self::SYNTHETIC_PROVIDER);
        // A sealed copy whose request and body agree, so only the re-verification can refuse it: the name cannot be printed.
        $row = (array) DB::table(TaxCheckoutSchema::TABLES['order'])->sole();
        $body = Evidence::open($row, 'production_tax_checkout_order');
        $unprintable = [...$body['buyer_declarations'], 'legal_name' => '王小明'];
        $request = [...$body['request'], 'buyer_declarations' => $unprintable, 'key_digest' => Evidence::key('synthetic-forged-order')];
        $forged = [...$body, 'public_id' => (string) Str::uuid(), 'request' => $request, 'request_hash' => CanonicalJson::hash($request), 'buyer_declarations' => $unprintable];
        unset($row['id']);
        DB::table(TaxCheckoutSchema::TABLES['order'])->insert([...$row, 'public_id' => $forged['public_id'], ...Evidence::seal($forged),
            'request_key' => $request['key_digest'], 'request_hash' => $forged['request_hash']]);

        foreach ([true, false] as $gate) {
            config(['production-tax-checkout.contract_identity_v2' => $gate]);
            $this->refuses('changed', fn () => $this->initiate($f, $forged['public_id']));
            $this->assertSame([], $f['transport']->calls);
            $this->assertSame([0, 0], self::paymentCounts());
        }
    }

    public function test_with_the_gate_on_initiate_refuses_a_name_only_order_and_with_it_off_pays_it(): void
    {
        $f = $this->payable();
        $order = $this->order($f, ['legalName' => 'Declared synthetic buyer']);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame([], $f['transport']->calls);
        $this->assertSame([0, 0], self::paymentCounts());

        config(['production-tax-checkout.contract_identity_v2' => false]);
        $this->assertSame('open', $this->initiate($f, $order['orderId'])['checkoutStatus']);
        $this->assertSame(['create', 'retrieve'], array_column($f['transport']->calls, 0));
        // A session opened before the gate flip: initiate refuses again, but reconciliation still retains the payment.
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $f['transport']->paid = true;
        $this->assertSame('verified', $this->reconcile($f, $order['orderId'])['paymentStatus']);
        // The refused initiate made no call; reconciliation only read the session it had opened.
        $this->assertSame(['create', 'retrieve', 'retrieve', 'paymentIntent'], array_column($f['transport']->calls, 0));
        $this->assertSame([1, 1], self::paymentCounts());

        // Decided 2026-11-08: once paid, initiate answers the order's status with no transport call at all.
        $status = $this->initiate($f, $order['orderId']);
        $this->assertSame(['verified', 'pending_fulfillment'], [$status['paymentStatus'], $status['fulfillmentStatus']]);
        $this->assertSame($f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']), $status);
        $this->assertSame(['create', 'retrieve', 'retrieve', 'paymentIntent'], array_column($f['transport']->calls, 0));
        $this->assertSame([1, 1], self::paymentCounts());
    }

    public function test_with_the_gate_off_initiate_refuses_a_v2_order_before_any_request_or_transport_call(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->payable();
        $order = $this->order($f, self::identity());
        self::configureTax(self::SYNTHETIC_PROVIDER);

        config(['production-tax-checkout.contract_identity_v2' => false]);
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame([], $f['transport']->calls);
        $this->assertSame([0, 0], self::paymentCounts());

        // The same order pays under the gate it was taken in; once paid, a gate-off initiate still refuses without a call.
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->assertSame('open', $this->initiate($f, $order['orderId'])['checkoutStatus']);
        $f['transport']->paid = true;
        $this->assertSame('verified', $this->reconcile($f, $order['orderId'])['paymentStatus']);
        config(['production-tax-checkout.contract_identity_v2' => false]);
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame(['create', 'retrieve', 'retrieve', 'paymentIntent'], array_column($f['transport']->calls, 0));
        $this->assertSame([1, 1], self::paymentCounts());
    }

    public function test_reconcile_records_an_unverifiable_v2_payment_but_still_refuses_tampered_evidence(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->payable();
        $this->order($f, self::identity());
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $forged = self::forgedUnprintableTaxOrder();
        // Body declarations that differ from the request's (resealed), and a stored row whose seal no longer matches.
        $mismatched = self::forgedUnprintableTaxOrder('synthetic-forged-mismatch',
            fn (array $body): array => [...$body, 'buyer_declarations' => [...$body['buyer_declarations'], 'legal_name' => '李小龙']]);
        $unsealed = self::forgedUnprintableTaxOrder('synthetic-forged-unsealed', null,
            fn (array $row): array => [...$row, 'payload_hash' => hash('sha256', 'tampered')]);
        foreach ([[$mismatched, 'changed'], [$unsealed, 'corrupt']] as [$tampered, $reason]) {
            // The evidence refuses before the missing request is even considered.
            $this->refuses($reason, fn () => $this->reconcile($f, $tampered));
            $this->refuses($reason, fn () => $f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $tampered));
        }
        foreach ([true, false] as $gate) {
            config(['production-tax-checkout.contract_identity_v2' => $gate]);
            $this->refuses('changed', fn () => $this->initiate($f, $forged));
        }
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $this->assertSame([], $f['transport']->calls);

        $f['transport']->params = self::forgedTaxSession($forged, RecordingTaxCheckoutTransport::SESSION);
        $f['transport']->paid = true;
        $paid = $this->reconcile($f, $forged);
        $this->assertSame(['complete', 'verified', 'pending_fulfillment'], [$paid['checkoutStatus'], $paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame([1, 1], self::paymentCounts());
        // A repeated reconcile writes nothing more; initiate still refuses rather than answering for the unverifiable order.
        $this->assertSame($paid['paymentStatus'], $this->reconcile($f, $forged)['paymentStatus']);
        $this->refuses('changed', fn () => $this->initiate($f, $forged));
        $this->assertSame(['retrieve', 'paymentIntent'], array_column($f['transport']->calls, 0));
        $this->assertSame([1, 1], self::paymentCounts());
    }

    public function test_a_paid_name_only_order_answers_its_status_after_the_listing_changed(): void
    {
        $f = $this->payable();
        $order = $this->order($f, ['legalName' => 'Declared synthetic buyer']);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $this->assertSame('open', $this->initiate($f, $order['orderId'])['checkoutStatus']);
        $f['transport']->paid = true;
        $this->assertSame('verified', $this->reconcile($f, $order['orderId'])['paymentStatus']);
        DB::table('offers')->where('track_id', $f['catalog']['items'][0]['trackId'])->update(['is_active' => false]);
        $calls = $f['transport']->calls;

        // Review P3-4: with the gate on, the status-only answer skips the create-only candidate and selection proofs.
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $status = $this->initiate($f, $order['orderId']);
        $this->assertSame(['verified', 'pending_fulfillment'], [$status['paymentStatus'], $status['fulfillmentStatus']]);
        $this->assertSame($f['checkout']->status($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']), $status);
        // With the gate off the same order still runs those proofs (unchanged), which the withdrawn listing refuses.
        config(['production-tax-checkout.contract_identity_v2' => false]);
        try {
            $this->initiate($f, $order['orderId']);
            $this->fail('The create-only proofs run when initiate is not answering a status-only order.');
        } catch (CheckoutException|ValidationException) {
            // The withdrawn listing no longer resolves to a current selection.
        }
        $this->assertSame($calls, $f['transport']->calls);
        $this->assertSame([1, 1], self::paymentCounts());
    }

    /**
     * Review P3-2: bind() (the internal write after initiate's provider create) re-verifies the declaration itself, so a
     * declaration that stops verifying between initiate's checks and the binding write is never bound.
     */
    public function test_bind_refuses_a_v2_declaration_that_no_longer_verifies_and_writes_no_binding(): void
    {
        config(['production-tax-checkout.contract_identity_v2' => true]);
        $f = $this->payable();
        $this->order($f, self::identity());
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $forged = self::forgedUnprintableTaxOrder();
        $request = self::forgedTaxSession($forged, null)['request_id'];
        $bind = new \ReflectionMethod(ProductionTaxCheckout::class, 'bind');

        $this->refuses('changed', fn () => $bind->invoke($f['checkout'], $request, RecordingTaxCheckoutTransport::SESSION, $forged));
        $this->assertDatabaseCount(TaxCheckoutSchema::TABLES['binding'], 0);
        $this->assertSame([], $f['transport']->calls);
    }
}
