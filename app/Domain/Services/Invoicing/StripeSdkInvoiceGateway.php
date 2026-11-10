<?php

namespace App\Domain\Services\Invoicing;

use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Support\Environment\TestEnvironment;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;
use Stripe\ApiRequestor;
use Stripe\BaseStripeClient;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe Invoicing for service projects, test mode only. Same posture as StripeSdkCheckoutGateway: the pinned API version, an exact
 * sk_test_ credential, the configured own account proven through GET /v1/account before any other request, no network retries, no
 * request inside an application transaction, and no provider exception escapes (SDK exceptions can retain request bodies and keys).
 * Every mutating request carries a deterministic idempotency key derived from the local invoice identity.
 */
final class StripeSdkInvoiceGateway implements StripeInvoiceGateway
{
    public const API_VERSION = StripeSdkCheckoutGateway::API_VERSION;

    public const UNAVAILABLE = 'STRIPE_INVOICE_UNAVAILABLE';

    public function __construct(private readonly ?ClientInterface $httpClient = null) {}

    public function issue(#[SensitiveParameter] array $spec): array
    {
        if (($spec['currency'] ?? null) !== 'USD' || ! is_int($spec['amount_minor'] ?? null) || $spec['amount_minor'] < 1 || $spec['amount_minor'] > 2147483647
            || ! is_int($spec['days_until_due'] ?? null) || $spec['days_until_due'] < 1 || $spec['days_until_due'] > 90
            || ! is_string($spec['key'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $spec['key']) !== 1
            || ! is_string($spec['email'] ?? null) || $spec['email'] === '' || strlen($spec['email']) > 254
            || ! is_string($spec['invoice_ref'] ?? null) || ! is_string($spec['project_ref'] ?? null) || ! is_string($spec['description'] ?? null)
            || (($spec['customer_id'] ?? null) !== null && (! is_string($spec['customer_id']) || ! self::customerId($spec['customer_id'])))) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        return $this->withClient(function (StripeClient $client, string $account) use ($spec): array {
            $this->verifyAccount($client, $account);
            $key = $spec['key'];
            $customerId = $spec['customer_id'];
            // An earlier attempt may have created the invoice and outlived the provider's idempotency-key window: find it by the
            // reference this server set and adopt it, never create a second invoice for the same local row.
            $found = $client->invoices->search(['query' => "metadata['va_service_invoice']:'".$spec['invoice_ref']."'", 'limit' => 3])->toArray();
            $matches = array_values(array_filter($found['data'] ?? [], fn ($candidate): bool => is_array($candidate) && ($candidate['metadata']['va_service_invoice'] ?? null) === $spec['invoice_ref']));
            if (count($matches) > 1 || preg_match('/\A[0-9a-f-]{36}\z/D', $spec['invoice_ref']) !== 1) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            if (count($matches) === 1) {
                $existing = $this->invoice($matches[0], $matches[0]['id'] ?? null);
                if (! self::customerId($existing['customer'] ?? null)) {
                    throw new RuntimeException(self::UNAVAILABLE);
                }

                return ['customer_id' => $existing['customer'], 'invoice' => $this->invoice($client->invoices->retrieve($existing['id'], [])->toArray(), $existing['id'])];
            }
            if ($customerId === null) {
                $customer = $client->customers->create(['email' => $spec['email'], 'metadata' => ['va_project' => $spec['project_ref']]],
                    ['idempotency_key' => 'svcinv-cus-'.$key])->toArray();
                if (($customer['object'] ?? null) !== 'customer' || ! self::customerId($customer['id'] ?? null) || ($customer['livemode'] ?? null) !== false) {
                    throw new RuntimeException(self::UNAVAILABLE);
                }
                $customerId = $customer['id'];
            }
            $metadata = ['va_service_invoice' => $spec['invoice_ref'], 'va_project' => $spec['project_ref']];
            $draft = $client->invoices->create([
                'customer' => $customerId, 'collection_method' => 'send_invoice', 'days_until_due' => $spec['days_until_due'],
                'currency' => 'usd', 'auto_advance' => false, 'pending_invoice_items_behavior' => 'exclude',
                'description' => $spec['description'], 'metadata' => $metadata,
            ], ['idempotency_key' => 'svcinv-inv-'.$key])->toArray();
            $invoice = $this->invoice($draft, $draft['id'] ?? null);
            // Re-read before every later step: an idempotent replay older than the provider's key window must not add a second line
            // or re-finalize. The state read here, not the local row, decides what is still to do.
            $invoice = $this->invoice($client->invoices->retrieve($invoice['id'], [])->toArray(), $invoice['id']);
            if (($invoice['status'] ?? null) === 'draft') {
                if (count($invoice['lines']['data'] ?? []) === 0) {
                    $client->invoices->addLines($invoice['id'], ['lines' => [['description' => $spec['description'], 'amount' => $spec['amount_minor']]]],
                        ['idempotency_key' => 'svcinv-line-'.$key]);
                }
                $invoice = $this->invoice($client->invoices->finalizeInvoice($invoice['id'], [], ['idempotency_key' => 'svcinv-fin-'.$key])->toArray(), $invoice['id']);
            }

            return ['customer_id' => $customerId, 'invoice' => $invoice];
        });
    }

    public function retrieve(string $invoiceId): array
    {
        if (! self::invoiceId($invoiceId)) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        return $this->withClient(function (StripeClient $client, string $account) use ($invoiceId): array {
            $this->verifyAccount($client, $account);

            return $this->invoice($client->invoices->retrieve($invoiceId, [])->toArray(), $invoiceId);
        });
    }

    public static function invoiceId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\Ain_[A-Za-z0-9]{1,240}\z/D', $id) === 1;
    }

    public static function customerId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\Acus_[A-Za-z0-9]{1,240}\z/D', $id) === 1;
    }

    /** Provider-object proof only: an own-account, test-mode invoice by the requested id. Binding to a local row is the domain's job. */
    private function invoice(#[SensitiveParameter] array $invoice, mixed $expectedId): array
    {
        if (($invoice['object'] ?? null) !== 'invoice' || ! self::invoiceId($invoice['id'] ?? null) || $invoice['id'] !== $expectedId
            || ($invoice['livemode'] ?? null) !== false || ($invoice['account'] ?? null) !== null || ($invoice['context'] ?? null) !== null
            || ($invoice['application'] ?? null) !== null) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        return $invoice;
    }

    private function withClient(#[SensitiveParameter] Closure $operation): array
    {
        try {
            $account = config('payments.stripe.account_id');
            $secret = config('payments.stripe.secret_key');
            if (! TestEnvironment::admitsTestCommerce()
                || config('payments.stripe.mode') !== 'test'
                || ! is_string($account) || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)
                || ! is_string($secret) || ! preg_match('/\Ask_test_[A-Za-z0-9]{8,200}\z/', $secret)
                || Stripe::$accountId !== null || Stripe::getVerifySslCerts() !== true) {
                throw new RuntimeException(self::UNAVAILABLE);
            }
            // Include every already-open Laravel connection. No provider request while an application transaction is held.
            foreach (DB::getConnections() as $connection) {
                if ($connection->transactionLevel() !== 0) {
                    throw new RuntimeException(self::UNAVAILABLE);
                }
            }
            $client = new StripeClient([
                'api_key' => $secret,
                'api_base' => BaseStripeClient::DEFAULT_API_BASE,
                'stripe_version' => self::API_VERSION,
                'stripe_account' => null,
                'stripe_context' => null,
                'max_network_retries' => 0,
            ]);
            $transport = $this->httpClient ?? (new CurlClient)
                ->setConnectTimeout(StripeSdkCheckoutGateway::CONNECT_TIMEOUT_SECONDS)
                ->setTimeout(StripeSdkCheckoutGateway::REQUEST_TIMEOUT_SECONDS);
            // stripe-php exposes transport only through ApiRequestor's static slot: scope it to this synchronous call.
            $previous = ApiRequestor::httpClient();
            ApiRequestor::setHttpClient($transport);
            try {
                return $operation($client, $account);
            } finally {
                ApiRequestor::setHttpClient($previous);
            }
        } catch (Throwable) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
    }

    private function verifyAccount(StripeClient $client, string $expected): void
    {
        // GET /v1/account proves which account owns the credential; it is not a Connect lookup.
        $account = $client->accounts->retrieve()->toArray();
        if (($account['object'] ?? null) !== 'account' || ($account['id'] ?? null) !== $expected) {
            throw new RuntimeException(self::UNAVAILABLE);
        }
    }
}
