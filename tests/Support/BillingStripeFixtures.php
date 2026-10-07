<?php

namespace Tests\Support;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingExpectation;
use App\Domain\Memberships\Billing\BillingProjection;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingSnapshots;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Production\MembershipSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use Stripe\Account;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\Invoice;
use Stripe\InvoiceLineItem;
use Stripe\InvoicePayment;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\SubscriptionItem;

/**
 * SYNTHETIC REHEARSAL FIXTURES. Every id, amount, currency and period here is an obviously synthetic
 * placeholder (currency XTS is the ISO 4217 testing code; 1234 minor units is not a price) and never a
 * plan, price, allowance or account fact. Objects are built through the locked SDK's own classes with
 * constructFrom(), and every key is checked against that class's generated @property docblock (and,
 * one level down, against the documented object shape), so a fixture cannot invent a provider field.
 */
final class BillingStripeFixtures
{
    public const ACCOUNT = 'acct_SYNTHETICREHEARSAL';

    public const CUSTOMER = 'cus_SYNTHETIC';

    public const SUBSCRIPTION = 'sub_SYNTHETIC';

    public const PRICE = 'price_SYNTHETIC';

    public const INVOICE = 'in_SYNTHETIC';

    public const INVOICE_PAYMENT = 'inpay_SYNTHETIC';

    public const PAYMENT_INTENT = 'pi_SYNTHETIC';

    public const CHARGE = 'ch_SYNTHETIC';

    public const BALANCE_TRANSACTION = 'txn_SYNTHETIC';

    public const AMOUNT = 1234;

    public const CURRENCY = 'XTS';

    public const PERIOD_START = 1791331200;

    public const PERIOD_END = 1794009600;

    public static function configure(array $overrides = []): void
    {
        self::testing();
        config(['production-membership-billing' => [...['enabled' => true, 'provider_io_enabled' => false, 'account_ref' => self::ACCOUNT,
            'mode' => 'test', 'approved_subscription_policy_hash' => hash('sha256', 'synthetic rehearsal subscription policy, not a fact'),
            'secret_key' => 'sk_'.'test_'.'SYNTHETICREHEARSAL', 'webhook_secret' => 'whsec_'.'SYNTHETICREHEARSAL'], ...$overrides]]);
    }

    /** Inserts a synthetic plan version, verified customer and sealed subscription binding. Returns the binding row. */
    public static function binding(array $payload = []): array
    {
        self::testing();
        $customer = CustomerFixtures::account();
        $planId = (string) Str::uuid();
        self::insert((new MembershipSchema)->table(MembershipSchema::TABLES[0]), ['id' => $planId, 'policy_hash' => hash('sha256', 'synthetic plan '.$planId),
            'original_terms_hash' => str_repeat('a', 64), 'provenance' => IdentityPolicy::REHEARSAL, 'payload_ciphertext' => 'synthetic placeholder, not policy proof',
            'seal' => hash('sha256', 'plan'.$planId), 'created_at' => '2026-10-07 00:00:00']);
        $payload = [...['schema_version' => 1, 'purpose' => 'production_membership_billing_subscription', 'account_ref' => self::ACCOUNT, 'mode' => 'test',
            'customer_ref' => self::CUSTOMER, 'subscription_ref' => self::SUBSCRIPTION, 'price_ref' => self::PRICE, 'currency' => self::CURRENCY,
            'amount_minor' => self::AMOUNT, 'plan_version_id' => $planId], ...$payload];
        $account = $payload['account_ref'];
        $mode = $payload['mode'];
        $row = ['id' => (string) Str::uuid(), 'account_id' => $customer['account']->id, 'user_id' => $customer['user']->id, 'identity_origin_id' => 1,
            'provider_account_hash' => BillingValues::hash('provider-account', $account, $mode, $account),
            'mode' => $mode, 'customer_ref_hash' => BillingValues::hash('customer', $account, $mode, $payload['customer_ref']),
            'subscription_ref_hash' => BillingValues::hash('subscription', $account, $mode, $payload['subscription_ref']),
            'price_ref_hash' => BillingValues::hash('price', $account, $mode, $payload['price_ref']), 'plan_version_id' => $planId,
            'approval_binding_hash' => hash('sha256', 'synthetic approval, no staff MFA writer exists in this lane'),
            'provenance' => IdentityPolicy::REHEARSAL, 'payload_ciphertext' => BillingValues::encrypt($payload), 'created_at' => '2026-10-07 00:00:00'];
        $row['seal'] = BillingValues::seal($row);
        self::insert((new BillingSchema)->table(BillingSchema::TABLES[0]), $row);

        return $row;
    }

    /** The settled graph; each override map replaces documented top-level keys of that object. */
    public static function graph(array $overrides = []): array
    {
        $o = fn (string $key): array => $overrides[$key] ?? [];
        $line = self::sdk(InvoiceLineItem::class, [...['id' => 'il_SYNTHETIC', 'object' => 'line_item', 'livemode' => false, 'amount' => self::AMOUNT,
            'subtotal' => self::AMOUNT, 'currency' => 'xts', 'description' => 'NONBINDING SYNTHETIC MEMBERSHIP LINE', 'discount_amounts' => [],
            'discountable' => true, 'discounts' => [], 'invoice' => self::INVOICE, 'metadata' => [],
            'parent' => ['type' => 'subscription_item_details', 'invoice_item_details' => null, 'subscription_item_details' => ['invoice_item' => null,
                'proration' => false, 'proration_details' => null, 'subscription' => self::SUBSCRIPTION, 'subscription_item' => 'si_SYNTHETIC']],
            'period' => ['start' => self::PERIOD_START, 'end' => self::PERIOD_END], 'pretax_credit_amounts' => [],
            'pricing' => ['type' => 'price_details', 'price_details' => ['price' => self::PRICE, 'product' => 'prod_SYNTHETIC'], 'unit_amount_decimal' => (string) self::AMOUNT],
            'quantity' => 1, 'taxes' => []], ...$o('line')]);
        $lines = $overrides['lines'] ?? [$line];
        $invoice = self::sdk(Invoice::class, [...['id' => self::INVOICE, 'object' => 'invoice', 'livemode' => false, 'status' => 'paid',
            'customer' => self::CUSTOMER, 'currency' => 'xts', 'collection_method' => 'charge_automatically', 'billing_reason' => 'subscription_cycle',
            'parent' => ['type' => 'subscription_details', 'quote_details' => null, 'subscription_details' => ['metadata' => [], 'subscription' => self::SUBSCRIPTION]],
            'lines' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoices/'.self::INVOICE.'/lines', 'data' => $lines],
            'total' => self::AMOUNT, 'subtotal' => self::AMOUNT, 'amount_due' => self::AMOUNT, 'amount_paid' => self::AMOUNT, 'amount_remaining' => 0,
            'amount_overpaid' => 0, 'amount_paid_off_stripe' => null, 'discounts' => [], 'total_discount_amounts' => [], 'total_pretax_credit_amounts' => [],
            'total_taxes' => [], 'starting_balance' => 0, 'ending_balance' => 0, 'pre_payment_credit_notes_amount' => 0,
            'post_payment_credit_notes_amount' => 0, 'on_behalf_of' => null, 'application' => null,
            'period_start' => self::PERIOD_START - 86400, 'period_end' => self::PERIOD_START], ...$o('invoice')]);
        $payment = self::sdk(InvoicePayment::class, [...['id' => self::INVOICE_PAYMENT, 'object' => 'invoice_payment', 'livemode' => false,
            'invoice' => self::INVOICE, 'status' => 'paid', 'amount_paid' => self::AMOUNT, 'amount_requested' => self::AMOUNT, 'currency' => 'xts',
            'is_default' => true, 'created' => self::PERIOD_START,
            'payment' => ['type' => 'payment_intent', 'payment_intent' => self::PAYMENT_INTENT],
            'status_transitions' => ['canceled_at' => null, 'paid_at' => self::PERIOD_START]], ...$o('payment')]);
        $payments = $overrides['payments'] ?? [$payment];
        $intent = self::sdk(PaymentIntent::class, [...['id' => self::PAYMENT_INTENT, 'object' => 'payment_intent', 'livemode' => false,
            'status' => 'succeeded', 'amount' => self::AMOUNT, 'amount_received' => self::AMOUNT, 'amount_capturable' => 0, 'currency' => 'xts',
            'customer' => self::CUSTOMER, 'latest_charge' => self::CHARGE, 'capture_method' => 'automatic', 'client_secret' => 'pi_SYNTHETIC_secret_DROPPED',
            'on_behalf_of' => null, 'transfer_data' => null, 'application' => null, 'application_fee_amount' => null], ...$o('intent')]);
        $charge = self::sdk(Charge::class, [...['id' => self::CHARGE, 'object' => 'charge', 'livemode' => false, 'status' => 'succeeded',
            'paid' => true, 'captured' => true, 'refunded' => false, 'amount' => self::AMOUNT, 'amount_captured' => self::AMOUNT, 'amount_refunded' => 0,
            'disputed' => false, 'currency' => 'xts', 'customer' => self::CUSTOMER, 'payment_intent' => self::PAYMENT_INTENT,
            'balance_transaction' => self::BALANCE_TRANSACTION, 'receipt_email' => 'dropped@example.invalid', 'on_behalf_of' => null,
            'transfer_data' => null, 'application' => null, 'application_fee_amount' => null], ...$o('charge')]);
        $transaction = self::sdk(BalanceTransaction::class, [...['id' => self::BALANCE_TRANSACTION, 'object' => 'balance_transaction',
            'amount' => self::AMOUNT, 'currency' => 'xts', 'exchange_rate' => null, 'status' => 'available', 'type' => 'charge',
            'source' => self::CHARGE, 'fee' => 0, 'net' => self::AMOUNT, 'available_on' => self::PERIOD_START, 'reporting_category' => 'charge'], ...$o('transaction')]);
        $item = self::sdk(SubscriptionItem::class, ['id' => 'si_SYNTHETIC', 'object' => 'subscription_item', 'price' => ['id' => self::PRICE, 'object' => 'price'],
            'subscription' => self::SUBSCRIPTION, 'quantity' => 1]);
        $subscription = self::sdk(Subscription::class, [...['id' => self::SUBSCRIPTION, 'object' => 'subscription', 'livemode' => false,
            'customer' => self::CUSTOMER, 'status' => 'active', 'currency' => 'xts',
            'items' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/subscription_items?subscription='.self::SUBSCRIPTION, 'data' => [$item]]], ...$o('subscription')]);
        $account = self::sdk(Account::class, ['id' => $overrides['account'] ?? self::ACCOUNT, 'object' => 'account', 'charges_enabled' => true]);

        return ['account' => $account, 'invoice' => $invoice, 'payments' => $payments, 'intents' => [self::PAYMENT_INTENT => $intent],
            'charges' => [self::CHARGE => $charge], 'transactions' => [self::BALANCE_TRANSACTION => $transaction], 'subscription' => $subscription];
    }

    /** The graph as the evaluator receives it: projections of the SDK objects, as both gateways return them. */
    public static function snapshots(array $graph, int $retrievedAt = self::PERIOD_START + 3600): BillingSnapshots
    {
        $invoice = BillingProjection::project('invoice', $graph['invoice']);

        return new BillingSnapshots(self::INVOICE, BillingProjection::project('account', $graph['account']), $invoice, $invoice['lines']['data'],
            array_map(fn (array $payment) => BillingProjection::project('invoice_payment', $payment), $graph['payments']),
            array_map(fn (array $intent) => BillingProjection::project('payment_intent', $intent), $graph['intents']),
            array_map(fn (array $charge) => BillingProjection::project('charge', $charge), $graph['charges']),
            array_map(fn (array $transaction) => BillingProjection::project('balance_transaction', $transaction), $graph['transactions']),
            $graph['subscription'] === null ? null : BillingProjection::project('subscription', $graph['subscription']), $retrievedAt, IdentityPolicy::REHEARSAL);
    }

    public static function expectation(): BillingExpectation
    {
        return new BillingExpectation(self::ACCOUNT, 'test', self::CUSTOMER, self::SUBSCRIPTION, self::PRICE, self::CURRENCY, self::AMOUNT);
    }

    /** Build an SDK object of $class from $values after checking every key against its generated docblock. Returns toArray(). */
    public static function sdk(string $class, array $values): array
    {
        $documented = self::documented($class);
        foreach ($values as $key => $value) {
            if (! isset($documented[$key])) {
                throw new LogicException("Fixture key {$class}::\${$key} is not a documented property of the locked SDK model.");
            }
            if (is_array($value) && ! array_is_list($value) && str_contains($documented[$key], 'object{')) {
                foreach (array_keys($value) as $nested) {
                    if (! preg_match('/[{,]\s*'.preg_quote((string) $nested, '/').'\??:/', $documented[$key])) {
                        throw new LogicException("Fixture key {$class}::\${$key}.{$nested} is not in the documented object shape.");
                    }
                }
            }
        }
        $object = $class::constructFrom($values);
        if (! $object instanceof StripeObject || $object::class !== $class && ! $object instanceof Collection) {
            throw new LogicException('The SDK did not construct the requested model.');
        }

        return $object->toArray();
    }

    /** @return array<string, string> property name => documented type text */
    private static function documented(string $class): array
    {
        $doc = (string) (new ReflectionClass($class))->getDocComment();
        preg_match_all('/@property\s+(.+?)\s+\$([a-z_0-9]+)/', $doc, $matches, PREG_SET_ORDER);
        $documented = [];
        foreach ($matches as [, $type, $name]) {
            $documented[$name] = $type;
        }

        return $documented;
    }

    private static function insert(string $table, array $row): void
    {
        DB::connection()->getPdo()->prepare('INSERT INTO '.$table.' ('.implode(',', array_map(fn ($k) => '`'.$k.'`', array_keys($row))).') VALUES ('
            .implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }

    private static function testing(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Billing fixtures require the testing environment.');
        }
    }
}
