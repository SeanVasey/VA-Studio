<?php

namespace App\Domain\Memberships\Billing;

use Closure;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

/**
 * The only Billing class that touches Stripe\StripeClient. Retrieval only: it calls retrieve/all on
 * account, invoices, invoice lines, invoice payments, payment intents, charges, balance transactions,
 * subscriptions and subscription items, and nothing that creates, updates, pays or cancels. An
 * injectable HTTP transport is confined to test mode in the testing environment.
 */
final class StripeSdkBillingGateway implements BillingProviderGateway
{
    public const MAX_LINES = 100;

    public const MAX_PAYMENTS = 10;

    public const MAX_ITEMS = 20;

    public function __construct(private readonly ?ClientInterface $fixtureTransport = null) {}

    public function provenance(): string
    {
        $configuration = (new BillingPolicy)->current();
        BillingException::require($this->fixtureTransport === null || ($configuration['mode'] === 'test' && app()->environment('testing')), 'provider_transport');

        return $configuration['provenance'];
    }

    public function account(): array
    {
        return $this->withClient(fn (StripeClient $client): array => BillingProjection::project('account', $client->accounts->retrieve()->toArray()));
    }

    public function retrieveInvoice(string $ref): array
    {
        BillingException::require(BillingValues::is('invoice', $ref), 'invalid_value');

        return $this->withClient(function (StripeClient $client) use ($ref): array {
            $invoice = $client->invoices->retrieve($ref, [])->toArray();
            BillingException::require(($invoice['id'] ?? null) === $ref, 'provider_inconsistent');
            if (($invoice['lines']['has_more'] ?? null) === true) {
                // The embedded list is only the first page; read the bounded complete list instead.
                $invoice['lines'] = $client->invoices->allLines($ref, ['limit' => self::MAX_LINES])->toArray();
            }
            $projected = BillingProjection::project('invoice', $invoice);
            BillingException::require($projected['lines']['has_more'] === false && count($projected['lines']['data']) <= self::MAX_LINES, 'provider_incomplete');

            return $projected;
        });
    }

    public function listInvoicePayments(string $invoiceRef): array
    {
        BillingException::require(BillingValues::is('invoice', $invoiceRef), 'invalid_value');

        return $this->withClient(function (StripeClient $client) use ($invoiceRef): array {
            $list = BillingProjection::collection($client->invoicePayments->all(['invoice' => $invoiceRef, 'limit' => self::MAX_PAYMENTS])->toArray(), 'invoice_payment');
            BillingException::require($list['has_more'] === false && count($list['data']) <= self::MAX_PAYMENTS, 'provider_incomplete');
            foreach ($list['data'] as $payment) {
                BillingException::require($payment['invoice'] === $invoiceRef, 'provider_inconsistent');
            }

            return $list['data'];
        });
    }

    public function retrievePaymentIntent(string $ref): array
    {
        return $this->one('payment_intent', $ref, fn (StripeClient $client) => $client->paymentIntents->retrieve($ref, []));
    }

    public function retrieveCharge(string $ref): array
    {
        return $this->one('charge', $ref, fn (StripeClient $client) => $client->charges->retrieve($ref, []));
    }

    public function retrieveBalanceTransaction(string $ref): array
    {
        return $this->one('balance_transaction', $ref, fn (StripeClient $client) => $client->balanceTransactions->retrieve($ref, []));
    }

    public function retrieveSubscription(string $ref): array
    {
        BillingException::require(BillingValues::is('subscription', $ref), 'invalid_value');

        return $this->withClient(function (StripeClient $client) use ($ref): array {
            $subscription = $client->subscriptions->retrieve($ref, [])->toArray();
            BillingException::require(($subscription['id'] ?? null) === $ref, 'provider_inconsistent');
            if (($subscription['items']['has_more'] ?? null) === true) {
                $subscription['items'] = $client->subscriptionItems->all(['subscription' => $ref, 'limit' => self::MAX_ITEMS])->toArray();
            }
            $projected = BillingProjection::project('subscription', $subscription);
            BillingException::require($projected['items']['has_more'] === false && count($projected['items']['data']) <= self::MAX_ITEMS, 'provider_incomplete');

            return $projected;
        });
    }

    private function one(string $object, string $ref, Closure $retrieve): array
    {
        BillingException::require(BillingValues::is($object, $ref), 'invalid_value');

        return $this->withClient(function (StripeClient $client) use ($object, $ref, $retrieve): array {
            $projected = BillingProjection::project($object, $retrieve($client)->toArray());
            BillingException::require($projected['id'] === $ref, 'provider_inconsistent');

            return $projected;
        });
    }

    /**
     * Configuration refusals keep their reason. Anything raised by the SDK or transport becomes
     * provider_unavailable without chaining: SDK exceptions can carry URLs, headers, bodies and keys.
     */
    private function withClient(Closure $operation): array
    {
        BillingProviderPin::assertInstalled();
        $policy = new BillingPolicy;
        $configuration = $policy->providerIo();
        BillingException::require($this->fixtureTransport === null || ($configuration['mode'] === 'test' && app()->environment('testing')), 'provider_transport');
        BillingException::require(Stripe::$accountId === null && Stripe::getVerifySslCerts() === true && Stripe::$logger === null, 'provider_transport');
        // Provider I/O never runs while any application transaction is held.
        foreach (DB::getConnections() as $connection) {
            BillingException::require($connection->transactionLevel() === 0, 'transaction_open');
        }
        try {
            $client = new StripeClient(BillingProviderPin::clientOptions($policy->secret()));
            $transport = $this->fixtureTransport ?? (new CurlClient)->setConnectTimeout(3)->setTimeout(10);
            // stripe-php exposes transport only through ApiRequestor's static slot; scope and restore it.
            $previous = ApiRequestor::httpClient();
            ApiRequestor::setHttpClient($transport);
            try {
                $result = $operation($client);
            } finally {
                ApiRequestor::setHttpClient($previous);
            }
        } catch (BillingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new BillingException('provider_unavailable');
        }
        $policy->proveConfiguration($configuration);

        return $result;
    }
}
