<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\HostedEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionCheckoutProviderFixtures as Fixture;
use Tests\TestCase;

class ProductionHostedEvidenceTest extends TestCase
{
    public function test_retrieved_complete_session_and_successful_payment_match_exact_frozen_mapping(): void
    {
        $request = Fixture::request();
        $session = Fixture::session($request['params'], 'complete', true);
        $payment = Fixture::payment($request['params']);
        $financial = HostedEvidence::financial($session, $payment, $request);
        $this->assertSame('confirmed', $financial['outcome']);
        $this->assertSame('payment_confirmed', HostedEvidence::observationKind($financial));
        $this->assertSame(4999, $financial['session']['amount_minor']);
        $session['customer_details'] = ['email' => 'private@example.invalid', 'address' => ['line1' => 'PRIVATE CUSTOMER DATA']];
        $safe = HostedEvidence::retainSession($session);
        $this->assertArrayNotHasKey('customer_details', $safe);
        $this->assertSame($financial, HostedEvidence::financial($safe, $financial['payment'], $request));
    }

    public static function mismatches(): array
    {
        return [['live'], ['account'], ['context'], ['client_reference'], ['metadata'], ['amount'], ['tax'], ['shipping'], ['discount'], ['expires'], ['automatic_tax'], ['methods'], ['methods_card_only'], ['methods_link_only'], ['methods_bank_debit'], ['methods_extra_delayed'], ['methods_duplicate'], ['methods_string'], ['methods_missing'],
            ['payment_methods'], ['payment_methods_card_only'], ['payment_methods_link_only'], ['payment_methods_bank_debit'], ['payment_methods_extra_delayed'],
            ['missing_line'], ['partial_list'], ['line_identity'], ['line_hash'], ['line_name'], ['line_amount'], ['line_currency'], ['line_quantity'], ['line_tax'], ['url_user'], ['url_host'], ['url_path'],
            ['payment_id'], ['payment_amount'], ['payment_received'], ['payment_capturable'], ['payment_currency'], ['payment_live'], ['payment_metadata'], ['payment_capture'], ['payment_transfer'], ['disagree_status']];
    }

    #[DataProvider('mismatches')]
    public function test_foreign_incomplete_or_disagreeing_evidence_cannot_confirm(string $case): void
    {
        $request = Fixture::request();
        $session = Fixture::session($request['params'], 'complete', true);
        $payment = Fixture::payment($request['params']);
        if (str_starts_with($case, 'url_')) {
            $session = Fixture::session($request['params']);
            $payment = null;
        }
        match ($case) {
            'live' => $session['livemode'] = true,
            'account' => $session['account'] = 'acct_FOREIGN',
            'context' => $session['context'] = 'ctx_FOREIGN',
            'client_reference' => $session['client_reference_id'] = 'foreign',
            'metadata' => $session['metadata']['order_id'] = 'foreign',
            'amount' => $session['amount_total']++,
            'tax' => $session['total_details']['amount_tax'] = 1,
            'shipping' => $session['total_details']['amount_shipping'] = 1,
            'discount' => $session['total_details']['amount_discount'] = 1,
            'expires' => $session['expires_at']++,
            'automatic_tax' => $session['automatic_tax']['enabled'] = true,
            'methods' => $session['payment_method_types'] = ['card', 'klarna'],
            'methods_card_only' => $session['payment_method_types'] = ['card'],
            'methods_link_only' => $session['payment_method_types'] = ['link'],
            'methods_bank_debit' => $session['payment_method_types'] = ['us_bank_account'],
            'methods_extra_delayed' => $session['payment_method_types'] = ['card', 'link', 'us_bank_account'],
            'methods_duplicate' => $session['payment_method_types'] = ['card', 'card', 'link'],
            'methods_string' => $session['payment_method_types'] = 'card,link',
            'methods_missing' => $session['payment_method_types'] = null,
            'payment_methods' => $payment['payment_method_types'] = ['card', 'klarna'],
            'payment_methods_card_only' => $payment['payment_method_types'] = ['card'],
            'payment_methods_link_only' => $payment['payment_method_types'] = ['link'],
            'payment_methods_bank_debit' => $payment['payment_method_types'] = ['us_bank_account'],
            'payment_methods_extra_delayed' => $payment['payment_method_types'] = ['card', 'link', 'cashapp'],
            'missing_line' => $session['line_items']['data'] = [],
            'partial_list' => $session['line_items']['has_more'] = true,
            'line_identity' => $session['line_items']['data'][0]['price']['product']['metadata']['line_id'] = 'foreign',
            'line_hash' => $session['line_items']['data'][0]['price']['product']['metadata']['line_hash'] = str_repeat('0', 64),
            'line_name' => $session['line_items']['data'][0]['price']['product']['name'] = 'Different buyer-visible recording',
            'line_amount' => $session['line_items']['data'][0]['price']['unit_amount']++,
            'line_currency' => $session['line_items']['data'][0]['currency'] = 'eur',
            'line_quantity' => $session['line_items']['data'][0]['quantity'] = 2,
            'line_tax' => $session['line_items']['data'][0]['amount_tax'] = 1,
            'url_user' => $session['url'] = 'https://user@checkout.stripe.com/c/pay/'.$session['id'],
            'url_host' => $session['url'] = 'https://evil.invalid/c/pay/'.$session['id'],
            'url_path' => $session['url'] = 'https://checkout.stripe.com/c/pay/cs_test_FOREIGN',
            'payment_id' => $payment['id'] = 'pi_FOREIGN',
            'payment_amount' => $payment['amount']++,
            'payment_received' => $payment['amount_received']--,
            'payment_capturable' => $payment['amount_capturable'] = 1,
            'payment_currency' => $payment['currency'] = 'eur',
            'payment_live' => $payment['livemode'] = true,
            'payment_metadata' => $payment['metadata']['attempt_id'] = 'foreign',
            'payment_capture' => $payment['capture_method'] = 'manual',
            'payment_transfer' => $payment['transfer_data'] = ['destination' => 'acct_FOREIGN'],
            'disagree_status' => $payment['status'] = 'processing',
        };
        $this->expectException(CheckoutException::class);
        HostedEvidence::financial($session, $payment, $request);
    }

    public function test_browser_or_session_paid_claim_without_payment_intent_is_not_confirmation(): void
    {
        $request = Fixture::request();
        $session = Fixture::session($request['params'], 'complete', true);
        $session['payment_intent'] = null;
        $this->expectException(CheckoutException::class);
        HostedEvidence::financial($session, null, $request);
    }

    public function test_request_offers_exactly_card_and_link_without_dynamic_methods_or_adaptive_pricing(): void
    {
        $params = Fixture::request()['params'];
        $this->assertSame(['card', 'link'], $params['payment_method_types']);
        $this->assertArrayNotHasKey('payment_method_configuration', $params);
        $this->assertSame(['enabled' => false], $params['adaptive_pricing']);
        $this->assertSame(['enabled' => false], $params['automatic_tax']);
    }

    public function test_link_payment_in_any_order_confirms_only_after_verified_payment_intent(): void
    {
        $request = Fixture::request();
        $session = Fixture::session($request['params'], 'complete', true);
        $payment = Fixture::payment($request['params']);
        $session['payment_method_types'] = ['link', 'card'];
        $payment['payment_method_types'] = ['link', 'card'];
        $this->assertSame('confirmed', HostedEvidence::financial($session, $payment, $request)['outcome']);

        // Session says paid but the PaymentIntent has not succeeded: never a confirmation, whatever the method.
        $payment['status'] = 'processing';
        $payment['amount_received'] = 0;
        $this->expectException(CheckoutException::class);
        HostedEvidence::financial($session, $payment, $request);
    }

    public function test_requests_stored_before_link_was_offered_still_verify_only_against_card(): void
    {
        $request = Fixture::request();
        $request['params']['payment_method_types'] = ['card'];
        $session = Fixture::session($request['params'], 'complete', true);
        $payment = Fixture::payment($request['params']);
        $this->assertSame('confirmed', HostedEvidence::financial($session, $payment, $request)['outcome']);

        $session['payment_method_types'] = ['card', 'link'];
        $this->expectException(CheckoutException::class);
        HostedEvidence::financial($session, $payment, $request);
    }

    #[DataProvider('untrustedRequestedMethods')]
    public function test_request_that_asked_for_anything_else_is_never_trusted(mixed $requested): void
    {
        $request = Fixture::request();
        $request['params']['payment_method_types'] = $requested;
        $session = Fixture::session($request['params'], 'complete', true);
        $payment = Fixture::payment($request['params']);
        $this->expectException(CheckoutException::class);
        HostedEvidence::financial($session, $payment, $request);
    }

    public static function untrustedRequestedMethods(): array
    {
        return [[['card', 'klarna']], [['us_bank_account']], [['link']], [['card', 'link', 'cashapp']], [[]], [null], ['card']];
    }
}
