<?php

namespace Tests\Feature;

use App\Domain\Commerce\ContractIdentity\ContractIdentityStatements;
use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\ContractIdentityPolicy;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\OrderEvidence;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Commerce\ProductionPreparation\PreparationSelection;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Grants\ContractIdentityHolds;
use App\Http\Middleware\ProductionCheckoutPrivacy;
use App\Models\User;
use App\Providers\ProductionCheckoutServiceProvider;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutContractIdentityForgery;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;
use Throwable;

/**
 * D-30 step 6: the V1 review freezes the v2 contract identity behind its own literal-false, local/testing-only gate. The
 * review request echoes the confirmation statement the buyer was shown; assent refuses a review taken under the other gate
 * state; with the gate off every byte is the original. Step 8: `HostedCheckout::initiate` re-verifies a v2 declaration and
 * refuses a declaration that does not match the gate (decided 2026-11-08: in both directions) before any provider call, except
 * that a name-only order already paid with the gate on answers its status; reconciliation is unchanged.
 */
class ProductionCheckoutContractIdentityTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutContractIdentityForgery;
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true]);
        Queue::fake();
    }

    /** The shared enrolment, source, catalog and exemption basis, without a review. */
    private function prepared(): array
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $catalog['items'],
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification', 'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-qualified-buyer', $catalog['actor']);

        return ['catalog' => $catalog, 'buyer' => $buyer, 'basis' => $basis, 'checkout' => new ProductionCheckout($access)];
    }

    private function review(array $f, array $declarations, string $key = 'synthetic-identity-review'): array
    {
        return $f['checkout']->review($f['buyer']['principal'], $f['buyer']['user'], $f['catalog']['candidate']->id, $f['catalog']['items'],
            $f['basis']['public_id'], $declarations, $key);
    }

    private function accept(array $f, array $review, string $key = 'synthetic-identity-accept'): array
    {
        return $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $review['reviewId'], $review['reviewHash'], true, $key);
    }

    /** Review input that echoes the statement displayed for its licensee type, unless the override names one. */
    private static function identity(array $overrides = []): array
    {
        $identity = array_replace(['licenseeType' => 'individual', 'legalName' => ' Zoë Émile ', 'legalNameConfirmed' => true,
            'postalAddress' => ['country' => 'US', 'lines' => ['1 Example Street'], 'locality' => 'Springfield', 'region' => 'IA', 'postalCode' => '50000']], $overrides);

        return $identity + ['statementVersion' => ContractIdentityStatements::offered()[$identity['licenseeType']]['statementVersion']];
    }

    private static function reviewRow(): array
    {
        return (array) DB::table(CheckoutSchema::TABLES['review'])->sole();
    }

    private static function orderBody(): array
    {
        $row = (array) DB::table(CheckoutSchema::TABLES['order'])->sole();

        return Evidence::open($row, 'production_checkout_order');
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

    private static function counts(): array
    {
        return [DB::table(CheckoutSchema::TABLES['review'])->count(), DB::table(CheckoutSchema::TABLES['order'])->count()];
    }

    public function test_the_gate_is_a_literal_false_separate_from_the_tax_gate_and_admits_only_local_or_testing(): void
    {
        $this->assertFalse((require base_path('config/production_checkout.php'))['contract_identity_v2']);
        $this->assertFalse(ContractIdentityPolicy::contractIdentityV2());
        foreach (['true', 1, [true]] as $loose) {
            config(['production_checkout.contract_identity_v2' => $loose]);
            $this->assertFalse(ContractIdentityPolicy::contractIdentityV2(), json_encode($loose));
        }
        config(['production_checkout.contract_identity_v2' => false, 'production-tax-checkout.contract_identity_v2' => true]);
        $this->assertFalse(ContractIdentityPolicy::contractIdentityV2());
        config(['production_checkout.contract_identity_v2' => true]);
        $this->assertTrue(ContractIdentityPolicy::contractIdentityV2());
        foreach (['staging', 'production'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertFalse(ContractIdentityPolicy::contractIdentityV2(), $environment);
        }
        $this->app['env'] = 'testing';
    }

    public function test_the_offer_is_refused_with_the_gate_off_and_lists_the_approved_statements_with_it_on(): void
    {
        $this->refuses('disabled', fn () => ProductionCheckout::contractIdentityOffer());
        config(['production_checkout.contract_identity_v2' => true]);
        $this->assertSame(['licenseeTypes' => ['individual', 'business'], 'statements' => ContractIdentityStatements::offered()],
            ProductionCheckout::contractIdentityOffer());
    }

    /** Mounts the checkout and customer identity routes by hand, as ProductionCheckoutJourneyTest does (lane PCC). */
    private function mountHttp(): void
    {
        $this->withoutVite();
        $this->app->register(ProductionCheckoutServiceProvider::class);
        $this->app[Kernel::class]->prependMiddleware(ProductionCheckoutPrivacy::class);
        Route::middleware('web')->group(base_path('routes/production-checkout.php'));
        Route::middleware('web')->group(base_path('routes/production-customer-identity.php'));
        config(['production_checkout.mounted' => true]);
    }

    private static function privateResponse(TestResponse $response, int $status): TestResponse
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        if ($status !== 200) {
            $response->assertExactJson(['code' => 'PRODUCTION_CHECKOUT_UNAVAILABLE',
                'message' => 'Checkout could not be confirmed. Retry the same request or check the saved order.']);
        }

        return $response;
    }

    public function test_the_http_offer_needs_the_http_switch_the_signed_in_buyer_and_the_gate(): void
    {
        $this->prepared();
        $this->mountHttp();
        $path = '/production/checkout/contract-identity';
        config(['production_checkout.contract_identity_v2' => true]);
        self::privateResponse($this->get($path), 503);
        config(['production_checkout.http_enabled' => true]);
        self::privateResponse($this->get($path), 403);
        // A cached guard actor without the new authenticated credential marker has no checkout authority here either.
        $this->actingAs(User::query()->where('email', 'buyer@example.test')->sole(), 'customer');
        self::privateResponse($this->get($path), 403);
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertOk();
        self::privateResponse($this->get($path.'?licenseeType=business'), 422);
        self::privateResponse($this->postJson($path, []), 405);
        config(['production_checkout.contract_identity_v2' => false]);
        self::privateResponse($this->get($path), 503);
        config(['production_checkout.contract_identity_v2' => true]);
        self::privateResponse($this->get($path), 200)->assertExactJson(['checkout' => ['licenseeTypes' => ['individual', 'business'],
            'statements' => ContractIdentityStatements::offered()]]);
        $this->assertSame([0, 0], self::counts());
    }

    public function test_a_review_echoing_the_statement_served_over_http_freezes_it(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        $this->mountHttp();
        config(['production_checkout.http_enabled' => true]);
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertOk();
        $offer = $this->get('/production/checkout/contract-identity')->assertOk()->json('checkout');
        $this->assertSame(['individual', 'business'], $offer['licenseeTypes']);
        $displayed = $offer['statements']['business']['statementVersion'];
        $buyer = ['statementVersion' => $displayed] + self::identity(['licenseeType' => 'business', 'legalName' => 'Example Records LLC', 'representativeName' => 'Zoë Émile']);
        $body = ['candidateId' => $f['catalog']['candidate']->id, 'items' => $f['catalog']['items'], 'basisId' => $f['basis']['public_id'],
            'buyer' => $buyer, 'requestKey' => 'synthetic-http-identity-review'];
        $this->postJson('/production/checkout/reviews', [...$body, 'buyer' => ['statementVersion' => $offer['statements']['individual']['statementVersion']] + $buyer,
            'requestKey' => 'synthetic-http-identity-mismatch'])->assertStatus(409);
        $this->assertSame([0, 0], self::counts());
        $this->postJson('/production/checkout/reviews', $body)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $frozen = Evidence::open(self::reviewRow(), 'production_checkout_review')['buyer_declarations'];
        $this->assertSame(['business', 'Example Records LLC', 'Zoë Émile', $displayed],
            [$frozen['licensee_type'], $frozen['legal_name'], $frozen['representative_name'], $frozen['legal_name_confirmation']['statement_version']]);
    }

    public function test_with_the_gate_off_the_request_review_order_and_response_bytes_are_the_original_ones(): void
    {
        $f = $this->prepared();
        $this->refuses('invalid', fn () => $this->review($f, self::identity()));
        $review = $this->review($f, ['legalName' => ' Declared synthetic buyer ']);
        $row = self::reviewRow();
        $body = Evidence::open($row, 'production_checkout_review');

        $declarations = ['legal_name' => 'Declared synthetic buyer', 'identity_meaning' => 'buyer_declared_legal_name'];
        $request = ['candidate_id' => $f['catalog']['candidate']->id, 'items' => PreparationSelection::items($f['catalog']['items']),
            'basis_id' => $f['basis']['public_id'], 'buyer_origin_id' => $body['buyer']['origin_id'], 'buyer_declarations' => $declarations,
            'key_digest' => Evidence::key('synthetic-identity-review')];
        $this->assertSame(CanonicalJson::encode($request), CanonicalJson::encode($body['request']));
        $this->assertSame(CanonicalJson::hash($request), $row['request_hash']);
        $this->assertSame(CanonicalJson::encode($declarations), CanonicalJson::encode($body['buyer_declarations']));
        // The sealed body is canonical JSON, so its key set fixes its layout: exactly the original twenty-one keys.
        $this->assertEqualsCanonicalizing(['schema_version', 'purpose', 'public_id', 'created_at', 'expires_at', 'request', 'request_hash', 'buyer',
            'buyer_declarations', 'candidate', 'machine', 'execution_context', 'selection', 'selection_hash', 'basis_public_id', 'basis_hash', 'amounts',
            'seller', 'assent', 'payable', 'review_hash'], array_keys($body));
        // The original review response, spelled out here rather than through the checkout's projection.
        $expected = ['reviewId' => $body['public_id'], 'reviewHash' => $body['review_hash'], 'expiresAt' => $body['expires_at'],
            'buyer' => ['legalName' => 'Declared synthetic buyer'], 'seller' => $body['seller'],
            'amounts' => array_intersect_key($body['amounts'], array_flip(['currency', 'subtotal_minor', 'tax_minor', 'total_minor'])), 'assent' => $body['assent'],
            'licenses' => array_map(fn (array $line): array => ['position' => $line['position'], 'trackId' => $line['track_id'], 'offerRevisionId' => $line['offer_revision_id'],
                'trackTitle' => $line['offer_snapshot']['product']['title'], 'artist' => $line['offer_snapshot']['product']['artist'],
                'licenseVersionId' => $line['license_version_id'], 'license' => array_intersect_key($line['offer_snapshot']['license'],
                    array_flip(['name', 'version', 'authored_source', 'source_hash', 'structured_terms', 'features']))], $body['selection']['selection']['lines']),
            'fundsMode' => $body['execution_context']['funds_mode']];
        // Stored snapshots are canonical (sorted) while the live response keeps source order: top-level order exactly, content as canonical bytes.
        $this->assertSame(array_keys($expected), array_keys($review));
        $this->assertSame(CanonicalJson::encode($expected), CanonicalJson::encode($review));
        // A replay projects the stored (canonical) snapshot, as it always has, so it equals the live response as canonical bytes.
        $this->assertSame(CanonicalJson::encode($review), CanonicalJson::encode($this->review($f, ['legalName' => ' Declared synthetic buyer '])));

        $order = $this->accept($f, $review);
        $orderBody = self::orderBody();
        $this->assertSame(CanonicalJson::encode($declarations), CanonicalJson::encode($orderBody['buyer_declarations']));
        $this->assertEqualsCanonicalizing(['schema_version', 'purpose', 'public_id', 'created_at', 'review_id', 'review_hash', 'review_payload_hash', 'request',
            'request_hash', 'buyer', 'buyer_declarations', 'accepted_at', 'assent', 'amounts', 'lines', 'attempt_id', 'expires_at', 'payable'], array_keys($orderBody));
        $this->assertSame(['orderId', 'createdAt', 'amounts', 'assentAccepted', 'fundsMode', 'paymentStatus', 'fulfillmentStatus'], array_keys($order));
        $this->assertSame($order, $f['checkout']->read($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']));
    }

    public function test_with_the_gate_on_an_individual_review_and_order_freeze_the_v2_identity_and_its_statement(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        $this->refuses('invalid', fn () => $this->review($f, ['legalName' => 'Declared synthetic buyer']));

        $review = $this->review($f, self::identity());
        $frozen = Evidence::open(self::reviewRow(), 'production_checkout_review')['buyer_declarations'];
        $this->assertSame('buyer-contract-identity-v2', $frozen['schema']);
        $this->assertSame(['individual', 'Zoë Émile', null, null], [$frozen['licensee_type'], $frozen['legal_name'], $frozen['representative_name'], $frozen['artist_name']]);
        $this->assertEquals(['confirmed' => true, 'statement_version' => 'individual-legal-name-confirmation-v1',
            'statement_hash' => hash('sha256', ContractIdentityStatements::TEXTS['individual']['text'])], $frozen['legal_name_confirmation']);
        $this->assertSame(['licenseeType' => 'individual', 'legalName' => 'Zoë Émile', 'representativeName' => null,
            'postalAddress' => '1 Example Street, Springfield, IA, 50000, United States', 'statementVersion' => 'individual-legal-name-confirmation-v1'],
            $review['contractIdentity']);
        $this->assertSame(['legalName' => 'Zoë Émile'], $review['buyer']);

        // The same request replays to the same review; a changed identity under the same key refuses.
        $this->assertSame(CanonicalJson::encode($review), CanonicalJson::encode($this->review($f, self::identity())));
        $this->refuses('changed', fn () => $this->review($f, self::identity(['legalName' => 'Someone Else'])));

        $order = $this->accept($f, $review);
        $this->assertSame(CanonicalJson::encode($frozen), CanonicalJson::encode(self::orderBody()['buyer_declarations']));
        $this->assertSame($order, $this->accept($f, $review));
        $this->assertSame($order, $f['checkout']->read($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']));
        $this->assertSame([1, 1], self::counts());
    }

    public function test_with_the_gate_on_a_business_review_freezes_the_representative_and_the_business_statement(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        $this->refuses('invalid', fn () => $this->review($f, self::identity(['licenseeType' => 'business', 'legalName' => 'Example Records LLC'])));
        $review = $this->review($f, self::identity(['licenseeType' => 'business', 'legalName' => 'Example Records LLC', 'representativeName' => 'Zoë Émile']));
        $this->accept($f, $review);

        $frozen = self::orderBody()['buyer_declarations'];
        $this->assertSame(['business', 'Example Records LLC', 'Zoë Émile'], [$frozen['licensee_type'], $frozen['legal_name'], $frozen['representative_name']]);
        $this->assertSame('business-legal-name-confirmation-v1', $frozen['legal_name_confirmation']['statement_version']);
        $this->assertSame(hash('sha256', ContractIdentityStatements::TEXTS['business']['text']), $frozen['legal_name_confirmation']['statement_hash']);
    }

    public function test_with_the_gate_on_the_review_must_echo_the_displayed_statement_and_printable_non_exclusive_input(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        // A different version than the one displayed is what a wording change between display and review looks like here.
        $this->refuses('changed', fn () => $this->review($f, self::identity(['statementVersion' => 'individual-legal-name-confirmation-v0'])));
        $this->refuses('changed', fn () => $this->review($f, self::identity(['statementVersion' => ContractIdentityStatements::offered()['business']['statementVersion']])));
        $this->refuses('invalid', fn () => $this->review($f, array_diff_key(self::identity(), ['statementVersion' => true])));
        $this->refuses('unsupported_characters', fn () => $this->review($f, self::identity(['legalName' => '王小明'])));
        // Exclusive has no production path, so an artist name is refused.
        $this->refuses('invalid', fn () => $this->review($f, self::identity(['artistName' => 'DJ Example'])));
        $this->refuses('invalid', fn () => $this->review($f, self::identity(['legalNameConfirmed' => false])));
        $this->assertSame([0, 0], self::counts());
    }

    public function test_with_the_gate_on_the_review_accepts_latin_extended_names_and_refuses_scripts_not_in_every_contract_face(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        // The printable set is the vasey-license-pdf-v2 faces (decision register, 2026-11-08): Greek, Cyrillic and
        // Vietnamese are refused at checkout rather than printed wrongly.
        foreach (['Иван Петров', 'Ελένη Παπαδοπούλου', 'Nguyễn Văn An'] as $name) {
            $this->refuses('unsupported_characters', fn () => $this->review($f, self::identity(['legalName' => $name])));
        }
        $this->refuses('unsupported_characters', fn () => $this->review($f, self::identity(['postalAddress' => ['country' => 'BG', 'lines' => ['ул. Витоша 1']]])));
        $this->assertSame([0, 0], self::counts());

        $review = $this->review($f, self::identity(['legalName' => 'Łukasz Żółć', 'postalAddress' => ['country' => 'RO', 'lines' => ['Strada Ștefan cel Mare 1'], 'locality' => 'Iași']]));
        $frozen = Evidence::open(self::reviewRow(), 'production_checkout_review')['buyer_declarations'];
        $this->assertSame(['Łukasz Żółć', ['Strada Ștefan cel Mare 1'], 'Iași'], [$frozen['legal_name'], $frozen['postal_address']['lines'], $frozen['postal_address']['locality']]);
        $this->accept($f, $review);
        $this->assertSame('Łukasz Żółć', self::orderBody()['buyer_declarations']['legal_name']);
        $this->assertSame([1, 1], self::counts());
    }

    public function test_a_gate_flip_between_review_and_assent_refuses_in_either_direction(): void
    {
        $f = $this->prepared();
        $legacy = $this->review($f, ['legalName' => 'Declared synthetic buyer'], 'synthetic-legacy-review');
        config(['production_checkout.contract_identity_v2' => true]);
        $identity = $this->review($f, self::identity(), 'synthetic-identity-review');
        $this->refuses('changed', fn () => $this->accept($f, $legacy, 'synthetic-legacy-accept'));

        config(['production_checkout.contract_identity_v2' => false]);
        $this->refuses('changed', fn () => $this->accept($f, $identity, 'synthetic-identity-accept'));
        $this->assertSame([2, 0], self::counts());

        // Each review still orders under the gate state it was taken in.
        $this->accept($f, $legacy, 'synthetic-legacy-accept');
        config(['production_checkout.contract_identity_v2' => true]);
        $this->accept($f, $identity, 'synthetic-identity-accept');
        $this->assertSame([2, 2], self::counts());
    }

    public function test_replay_after_a_gate_flip_refuses_new_input_and_returns_only_the_existing_order(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        $review = $this->review($f, self::identity());
        $order = $this->accept($f, $review);
        config(['production_checkout.contract_identity_v2' => false]);
        $this->refuses('invalid', fn () => $this->review($f, self::identity()));
        $this->refuses('changed', fn () => $this->review($f, ['legalName' => 'Zoë Émile']));
        // Replaying the assent writes nothing: it returns the order already retained, as read() does.
        $this->assertSame($order, $this->accept($f, $review));
        $this->assertSame($order, $f['checkout']->read($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']));
        config(['production_checkout.contract_identity_v2' => true]);
        $this->assertSame(CanonicalJson::encode($review), CanonicalJson::encode($this->review($f, self::identity())));
        $this->assertSame([1, 1], self::counts());
    }

    public function test_assent_reverifies_a_retained_v2_declaration_that_the_frozen_evidence_checks_only_for_equality(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->prepared();
        $this->review($f, self::identity());
        $row = self::reviewRow();
        $body = Evidence::open($row, 'production_checkout_review');

        // A well-sealed review whose request and body agree, so OrderEvidence accepts it, but whose name cannot be printed.
        // Cyrillic prints in the test-v2 DejaVu faces but not in the vasey-license-pdf-v2 faces checkout is bound to.
        unset($row['id']);
        foreach (['王小明', 'Иван Петров'] as $index => $name) {
            $declarations = [...$body['buyer_declarations'], 'legal_name' => $name];
            $digest = Evidence::key('synthetic-forged-review-'.$index);
            $request = [...$body['request'], 'buyer_declarations' => $declarations, 'key_digest' => $digest];
            $public = (string) Str::uuid();
            $forged = [...$body, 'public_id' => $public, 'request' => $request, 'request_hash' => CanonicalJson::hash($request), 'buyer_declarations' => $declarations];
            $forged['review_hash'] = OrderEvidence::reviewHash($forged);
            DB::table(CheckoutSchema::TABLES['review'])->insert([...$row, 'public_id' => $public, ...Evidence::seal($forged),
                'request_key' => $digest, 'request_hash' => $forged['request_hash']]);

            $this->refuses('changed', fn () => $this->accept($f, ['reviewId' => $public, 'reviewHash' => $forged['review_hash']]));
        }
        $this->assertSame([3, 0], self::counts());
    }

    /** The synthetic hosted checkout for a prepared buyer: a recording fake gateway, never a network call. */
    private function hosted(array $f): array
    {
        config(['production_checkout.reconciliation_enabled' => true]);
        $gateway = new ProductionCheckoutGatewayFixture;
        $calls = self::counting($gateway);

        return [...$f, 'gateway' => $gateway, 'calls' => $calls, 'hosted' => new HostedCheckout(new ProductionCustomerAccess, $calls)];
    }

    /** Records every provider method the checkout calls (provenance included), delegating to the synthetic fixture. */
    private static function counting(ProviderGateway $inner): ProviderGateway
    {
        return new class($inner) implements ProviderGateway
        {
            public array $calls = [];

            public function __construct(private readonly ProviderGateway $inner) {}

            public function provenance(ExecutionContextV1 $context): string
            {
                $this->calls[] = 'provenance';

                return $this->inner->provenance($context);
            }

            public function account(ExecutionContextV1 $context): array
            {
                $this->calls[] = 'account';

                return $this->inner->account($context);
            }

            public function create(ExecutionContextV1 $context, array $params, string $key): array
            {
                $this->calls[] = 'create';

                return $this->inner->create($context, $params, $key);
            }

            public function retrieve(ExecutionContextV1 $context, string $sessionId): array
            {
                $this->calls[] = 'retrieve';

                return $this->inner->retrieve($context, $sessionId);
            }

            public function paymentIntent(ExecutionContextV1 $context, string $paymentId): array
            {
                $this->calls[] = 'paymentIntent';

                return $this->inner->paymentIntent($context, $paymentId);
            }
        };
    }

    private function initiate(array $f, string $orderId): array
    {
        return $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $orderId);
    }

    private function reconcile(array $f, string $orderId): array
    {
        return $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $orderId);
    }

    private static function paymentCounts(): array
    {
        return [DB::table(CheckoutSchema::TABLES['intent'])->count(), DB::table(CheckoutSchema::TABLES['payment'])->count()];
    }

    /** The D-30 staff hold records (ContractIdentityHolds), as [order public id] per row. */
    private static function holds(): array
    {
        return DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->orderBy('id')->pluck('context')
            ->map(fn (string $context): string => json_decode($context, true, 4, JSON_THROW_ON_ERROR)['order_public_id'])->all();
    }

    /** A paid forged order: an unprintable v2 declaration the frozen evidence accepts, an intent, and a paid synthetic session. */
    private function paidForgedOrder(): array
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->hosted($this->prepared());
        $this->accept($f, $this->review($f, self::identity()));
        $forged = self::forgedUnprintableOrder();
        $f['gateway']->params = self::forgedIntent($forged);
        $f['gateway']->paid = true;

        return [$f, $forged];
    }

    private function reconcileForged(array $f, string $forged): array
    {
        return $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $forged, 'cs_test_SYNTHETIC');
    }

    public function test_with_the_gate_on_initiate_and_reconcile_pay_a_valid_v2_order(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->hosted($this->prepared());
        $order = $this->accept($f, $this->review($f, self::identity(['licenseeType' => 'business', 'legalName' => 'Example Records LLC',
            'representativeName' => 'Zoë Émile'])));

        $open = $this->initiate($f, $order['orderId']);
        $this->assertSame(['open', 'unverified'], [$open['checkoutStatus'], $open['paymentStatus']]);
        $this->assertCount(1, $f['gateway']->creates);
        // A retry while the session is bound re-verifies again and reuses the one provider session.
        $this->assertSame($open, $this->initiate($f, $order['orderId']));
        $this->assertCount(1, $f['gateway']->creates);

        // Reconciliation (unchanged by step 8) records the verified payment of a v2 order with the gate on.
        $f['gateway']->paid = true;
        $paid = $this->reconcile($f, $order['orderId']);
        $this->assertSame(['verified', 'pending_fulfillment'], [$paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame([1, 1], self::paymentCounts());
        $this->assertSame('Example Records LLC', self::orderBody()['buyer_declarations']['legal_name']);
        // A declaration that still verifies leaves no staff hold record, however often it is reconciled.
        $this->reconcile($f, $order['orderId']);
        $this->assertSame([], self::holds());
    }

    /**
     * D-30: a buyer who pays and never finalizes still leaves the staff record. Reconciliation records the payment first and
     * then, after that transaction has committed, the one hold record; repeated reconciliation and a later finalization keep
     * it at one (finalization: PaidGrantDocumentJourneyTest). The buyer-facing answer is the unchanged status.
     */
    public function test_reconcile_records_the_payment_and_then_one_hold_for_a_declaration_that_no_longer_verifies(): void
    {
        [$f, $forged] = $this->paidForgedOrder();
        $paid = $this->reconcileForged($f, $forged);
        $this->assertSame(['verified', 'pending_fulfillment'], [$paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame($f['hosted']->status($f['buyer']['principal'], $f['buyer']['user'], $forged), $paid);
        $this->assertSame([1, 1], self::paymentCounts());
        $this->assertSame([$forged], self::holds());
        $hold = (array) DB::table('audit_events')->where('action', ContractIdentityHolds::ACTION)->sole();
        $orderRow = DB::table(CheckoutSchema::TABLES['order'])->where('public_id', $forged)->sole();
        $this->assertSame(['production_checkout_order', (int) $orderRow->id, null], [$hold['subject_type'], (int) $hold['subject_id'], $hold['actor_id']]);
        $this->assertSame(['chain' => 'production_checkout_v1', 'reason' => 'contract_identity_held', 'order_public_id' => $forged],
            json_decode($hold['context'], true, 4, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('王小明', $hold['context']);

        foreach ([1, 2] as $attempt) {
            $this->assertSame($paid, $this->reconcileForged($f, $forged), 'attempt '.$attempt);
            $this->assertSame([$forged], self::holds(), 'attempt '.$attempt);
            $this->assertSame([1, 1], self::paymentCounts());
        }
        $this->assertSame([], $f['gateway']->creates);
    }

    /** A failed hold write is reported and swallowed: the payment and the answer are unchanged, and the next reconcile writes it. */
    public function test_a_failed_hold_write_never_affects_the_recorded_payment_or_the_reconcile_result(): void
    {
        [$f, $forged] = $this->paidForgedOrder();
        Exceptions::fake();
        $failing = true;
        AuditEvent::creating(function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('synthetic audit write failure');
            }
        });
        $paid = $this->reconcileForged($f, $forged);
        $this->assertSame(['verified', 'pending_fulfillment'], [$paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame($f['hosted']->status($f['buyer']['principal'], $f['buyer']['user'], $forged), $paid);
        $this->assertSame([1, 1], self::paymentCounts());
        $payment = (array) DB::table(CheckoutSchema::TABLES['payment'])->sole();
        $this->assertSame([], self::holds());
        Exceptions::assertReported(fn (RuntimeException $error): bool => $error->getMessage() === 'synthetic audit write failure');

        $failing = false;
        $this->assertSame($paid, $this->reconcileForged($f, $forged));
        $this->assertSame([$forged], self::holds());
        $this->assertSame($payment, (array) DB::table(CheckoutSchema::TABLES['payment'])->sole());
    }

    /** Review P3-1: a reporter that itself throws never reaches the buyer after the payment committed. */
    public function test_a_failing_reporter_never_surfaces_after_the_payment_committed(): void
    {
        [$f, $forged] = $this->paidForgedOrder();
        AuditEvent::creating(fn () => throw new RuntimeException('synthetic audit write failure'));
        $this->app->instance(ExceptionHandler::class, new class implements ExceptionHandler
        {
            public function report(Throwable $e): void
            {
                throw new RuntimeException('synthetic reporter failure');
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void {}
        });
        $paid = $this->reconcileForged($f, $forged);
        $this->assertSame(['verified', 'pending_fulfillment'], [$paid['paymentStatus'], $paid['fulfillmentStatus']]);
        $this->assertSame([1, 1], self::paymentCounts());
        $this->assertSame([], self::holds());
    }

    /** Review P3-2(a): inside any open transaction the hold writes nothing and reports why. */
    public function test_the_hold_is_never_written_inside_an_open_transaction(): void
    {
        [, $forged] = $this->paidForgedOrder();
        $declarations = Evidence::open((array) DB::table(CheckoutSchema::TABLES['order'])->where('public_id', $forged)->sole(),
            'production_checkout_order')['buyer_declarations'];
        Exceptions::fake();
        DB::transaction(fn () => ContractIdentityHolds::recordAfterPayment('production_checkout_v1', $forged, $declarations));
        $this->assertSame([], self::holds());
        Exceptions::assertReported(LogicException::class);
        // The same call after commit records it: the declarations are held, so only the transaction guard refused above.
        ContractIdentityHolds::recordAfterPayment('production_checkout_v1', $forged, $declarations);
        $this->assertSame([$forged], self::holds());
    }

    /** Review P3-2(b): an open, unpaid session of an unverifiable order is no payment, so no hold; payment then records one. */
    public function test_reconcile_of_an_unpaid_session_writes_no_hold(): void
    {
        [$f, $forged] = $this->paidForgedOrder();
        $f['gateway']->paid = false;
        $open = $this->reconcileForged($f, $forged);
        $this->assertSame('unverified', $open['paymentStatus']);
        $this->assertSame([1, 0], self::paymentCounts());
        $this->assertSame([], self::holds());
        $f['gateway']->paid = true;
        $this->assertSame('verified', $this->reconcileForged($f, $forged)['paymentStatus']);
        $this->assertSame([$forged], self::holds());
    }

    /** Review P3-2(c): operator reconciliation of a paid unverifiable order writes exactly one hold, also when repeated. */
    public function test_operator_reconcile_of_a_paid_unverifiable_order_writes_one_hold(): void
    {
        [$f, $forged] = $this->paidForgedOrder();
        config(['production_checkout.operator_reconciliation_enabled' => true]);
        foreach ([1, 2] as $attempt) {
            $this->assertSame('verified', $f['hosted']->operatorReconcile($forged, 'cs_test_SYNTHETIC')['paymentStatus'], 'attempt '.$attempt);
            $this->assertSame([$forged], self::holds(), 'attempt '.$attempt);
        }
        $this->assertSame([1, 1], self::paymentCounts());
    }

    public function test_initiate_refuses_a_v2_order_whose_declaration_no_longer_verifies_before_any_provider_call(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->hosted($this->prepared());
        $this->accept($f, $this->review($f, self::identity()));
        $forged = self::forgedUnprintableOrder();
        // The frozen evidence opens: it checks only that the order, review and request declarations are equal.
        $this->assertSame($forged, CommandTransaction::run(fn (Records $rows): string => OrderEvidence::order($rows, $rows->one('order', $forged))['row']['public_id']));

        $this->refuses('changed', fn () => $this->initiate($f, $forged));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame([0, 0], self::paymentCounts());
        // The same refusal with the gate off: a v2 declaration is re-verified whatever the gate.
        config(['production_checkout.contract_identity_v2' => false]);
        $this->refuses('changed', fn () => $this->initiate($f, $forged));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame([0, 0], self::paymentCounts());
    }

    public function test_with_the_gate_on_initiate_refuses_a_name_only_order_and_with_it_off_pays_it(): void
    {
        $f = $this->hosted($this->prepared());
        $order = $this->accept($f, $this->review($f, ['legalName' => 'Declared synthetic buyer']));
        config(['production_checkout.contract_identity_v2' => true]);
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame([], $f['gateway']->creates);
        $this->assertSame([0, 0], self::paymentCounts());

        config(['production_checkout.contract_identity_v2' => false]);
        $this->assertSame('open', $this->initiate($f, $order['orderId'])['checkoutStatus']);
        $this->assertCount(1, $f['gateway']->creates);
        // A session opened before the gate flip: initiate refuses again, but reconciliation still records the payment.
        config(['production_checkout.contract_identity_v2' => true]);
        $calls = $f['calls']->calls;
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame($calls, $f['calls']->calls);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $this->reconcile($f, $order['orderId'])['paymentStatus']);
        $this->assertCount(1, $f['gateway']->creates);
        $this->assertSame([1, 1], self::paymentCounts());

        // Decided 2026-11-08: once paid, initiate answers the order's status with no provider call at all.
        $calls = $f['calls']->calls;
        $status = $this->initiate($f, $order['orderId']);
        $this->assertSame(['verified', 'pending_fulfillment'], [$status['paymentStatus'], $status['fulfillmentStatus']]);
        $this->assertSame($f['hosted']->status($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']), $status);
        $this->assertSame($calls, $f['calls']->calls);
        $this->assertSame([1, 1], self::paymentCounts());
        // A paid name-only (legacy) declaration is never held.
        $this->assertSame([], self::holds());
    }

    public function test_with_the_gate_off_initiate_refuses_a_v2_order_before_any_intent_or_provider_call(): void
    {
        config(['production_checkout.contract_identity_v2' => true]);
        $f = $this->hosted($this->prepared());
        $order = $this->accept($f, $this->review($f, self::identity()));

        config(['production_checkout.contract_identity_v2' => false]);
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame([], $f['calls']->calls);
        $this->assertSame([0, 0], self::paymentCounts());

        // The same order pays under the gate it was taken in; once paid, a gate-off initiate still refuses without a call.
        config(['production_checkout.contract_identity_v2' => true]);
        $this->assertSame('open', $this->initiate($f, $order['orderId'])['checkoutStatus']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $this->reconcile($f, $order['orderId'])['paymentStatus']);
        config(['production_checkout.contract_identity_v2' => false]);
        $calls = $f['calls']->calls;
        $this->refuses('changed', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame($calls, $f['calls']->calls);
        $this->assertSame([1, 1], self::paymentCounts());
    }

    public function test_a_paid_name_only_order_answers_its_status_after_the_listing_and_switches_changed(): void
    {
        $f = $this->hosted($this->prepared());
        $order = $this->accept($f, $this->review($f, ['legalName' => 'Declared synthetic buyer']));
        $this->assertSame('open', $this->initiate($f, $order['orderId'])['checkoutStatus']);
        $f['gateway']->paid = true;
        $this->assertSame('verified', $this->reconcile($f, $order['orderId'])['paymentStatus']);
        DB::table('offers')->where('track_id', $f['catalog']['items'][0]['trackId'])->update(['is_active' => false]);
        config(['production_checkout.fresh_checkout_enabled' => false, 'production_checkout.reconciliation_enabled' => false]);
        $calls = $f['calls']->calls;

        // Review P3-4: with the gate on, the status-only answer skips the create-only policy, selection, basis and
        // fresh-checkout proofs (and the reconciliation switch), keeping the owner, lock and evidence checks.
        config(['production_checkout.contract_identity_v2' => true]);
        $status = $this->initiate($f, $order['orderId']);
        $this->assertSame(['verified', 'pending_fulfillment'], [$status['paymentStatus'], $status['fulfillmentStatus']]);
        $this->assertSame($f['hosted']->status($f['buyer']['principal'], $f['buyer']['user'], $order['orderId']), $status);
        $this->assertSame($calls, $f['calls']->calls);
        // With the gate off the same order still runs those proofs (unchanged): the disabled fresh checkout refuses.
        config(['production_checkout.contract_identity_v2' => false]);
        $this->refuses('disabled', fn () => $this->initiate($f, $order['orderId']));
        $this->assertSame($calls, $f['calls']->calls);
        $this->assertSame([1, 1], self::paymentCounts());
    }
}
