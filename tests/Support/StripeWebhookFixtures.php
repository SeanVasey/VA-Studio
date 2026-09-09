<?php

namespace Tests\Support;

/** Nonbinding synthetic provider messages; no real account, customer or payment data. */
final class StripeWebhookFixtures
{
    public const ACCOUNT = 'acct_FixtureStore';

    public const SECRET = 'whsec_FixtureOnlyEndpointSecret2026';

    public static function configure(): void
    {
        config(['payments.stripe.webhook_enabled' => true, 'payments.stripe.mode' => 'test',
            'payments.stripe.account_id' => self::ACCOUNT, 'payments.stripe.webhook_secret' => self::SECRET]);
    }

    public static function event(): array
    {
        return [
            'id' => 'evt_FixtureEvent', 'object' => 'event', 'api_version' => '2026-07-29.dahlia',
            'created' => time() - 86400, 'livemode' => false, 'type' => 'checkout.session.completed',
            'pending_webhooks' => 2, 'request' => ['id' => 'req_Fixture', 'idempotency_key' => 'fixture-only'],
            'data' => ['object' => ['id' => 'cs_test_FixtureSession', 'object' => 'checkout.session',
                'livemode' => false, 'payment_status' => 'paid', 'payment_intent' => 'pi_FixturePayment',
                'amount_total' => 3499, 'currency' => 'usd', 'customer_email' => 'synthetic@example.invalid',
                'metadata' => ['fixture' => 'nonbinding', 'title' => '  Música fixture  ']]],
        ];
    }

    public static function body(?array $event = null): string
    {
        return json_encode($event ?? self::event(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function signature(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();

        // Independent fixture construction using Stripe's documented signing protocol.
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
