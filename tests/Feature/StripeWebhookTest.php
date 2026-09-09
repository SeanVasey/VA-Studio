<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Payments\ReceiveStripeWebhook;
use App\Domain\Commerce\Payments\VerifyStripeWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\StripeWebhookFixtures as Fixtures;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Fixtures::configure();
    }

    public function test_exact_verified_bytes_are_stored_encrypted_before_acknowledgment_without_fulfillment(): void
    {
        Queue::fake();
        $body = Fixtures::body();
        $response = $this->deliver($body)->assertOk()->assertExactJson(['received' => true]);
        $this->assertPrivate($response);
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $receipt = StripeWebhookReceipt::query()->sole();
        $this->assertSame(Fixtures::ACCOUNT, $receipt->account_id);
        $this->assertFalse($receipt->livemode);
        $this->assertSame('checkout.session.completed', $receipt->event_type);
        $this->assertSame('cs_test_FixtureSession', $receipt->object_id);
        $this->assertSame(hash('sha256', $body), $receipt->payload_sha256);
        $this->assertSame($body, Crypt::decryptString($receipt->payload_ciphertext));
        $this->assertStringNotContainsString('synthetic@example.invalid', $receipt->payload_ciphertext);
        $this->assertStringNotContainsString('payload_ciphertext', $receipt->toJson());
        $this->assertStringNotContainsString('synthetic@example.invalid', $response->getContent());
        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_delivery_retains_the_original_receipt_even_when_formatting_and_pending_webhooks_change(): void
    {
        $event = Fixtures::event();
        $body = Fixtures::body($event);
        $this->deliver($body)->assertOk();
        $original = StripeWebhookReceipt::query()->sole()->getAttributes();
        $event['pending_webhooks'] = 0;
        $reordered = json_encode(array_reverse($event, true), JSON_THROW_ON_ERROR);
        $this->assertNotSame($body, $reordered);
        $this->deliver($reordered)->assertOk();
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertSame($original, StripeWebhookReceipt::query()->sole()->getAttributes());
    }

    public function test_same_event_id_with_changed_payment_data_conflicts_without_rewriting_evidence(): void
    {
        $event = Fixtures::event();
        $body = Fixtures::body($event);
        $this->deliver($body)->assertOk();
        $event['data']['object']['amount_total']++;
        $response = $this->deliver(Fixtures::body($event))->assertConflict()->assertExactJson(['code' => 'STRIPE_EVENT_CONFLICT']);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertSame($body, Crypt::decryptString(StripeWebhookReceipt::query()->sole()->payload_ciphertext));
    }

    public function test_distinct_events_about_one_payment_are_retained_without_treating_either_as_a_grant(): void
    {
        Queue::fake();
        $event = Fixtures::event();
        $this->deliver(Fixtures::body($event))->assertOk();
        $event['id'] = 'evt_SecondFixture';
        $event['type'] = 'checkout.session.async_payment_succeeded';
        $this->deliver(Fixtures::body($event))->assertOk();
        $this->assertDatabaseCount('stripe_webhook_receipts', 2);
        $this->assertSame(1, StripeWebhookReceipt::query()->distinct()->count('object_id'));
        Queue::assertNothingPushed();
    }

    public function test_account_scope_and_case_sensitive_provider_event_ids_are_distinct(): void
    {
        $event = Fixtures::event();
        $this->deliver(Fixtures::body($event))->assertOk();
        $event['id'] = 'evt_fixtureEvent';
        $this->deliver(Fixtures::body($event))->assertOk();
        config(['payments.stripe.account_id' => 'acct_SecondFixture']);
        $this->deliver(Fixtures::body($event))->assertOk();
        $this->assertDatabaseCount('stripe_webhook_receipts', 3);
    }

    public function test_delayed_and_out_of_order_provider_events_use_delivery_signature_age_not_event_age(): void
    {
        $event = Fixtures::event();
        $event['created'] = time() - 86400 * 20;
        $this->deliver(Fixtures::body($event))->assertOk();
        $event['id'] = 'evt_OlderFixture';
        $event['created'] -= 86400;
        $this->deliver(Fixtures::body($event))->assertOk();
        $this->assertDatabaseCount('stripe_webhook_receipts', 2);
    }

    public function test_provider_decimal_fields_and_unknown_snapshot_events_are_preserved_without_interpreting_them(): void
    {
        $event = Fixtures::event();
        $event['type'] = 'future_resource.updated';
        $event['data']['object'] = ['object' => 'future_resource', 'fraction' => 0.125, 'list' => [2, 1], 'empty' => new \stdClass];
        $body = Fixtures::body($event);
        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk();
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertNull(StripeWebhookReceipt::query()->sole()->object_id);
        $this->assertSame($body, Crypt::decryptString(StripeWebhookReceipt::query()->sole()->payload_ciphertext));
    }

    public function test_multiple_v1_signatures_support_endpoint_secret_rotation(): void
    {
        $body = Fixtures::body();
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.str_repeat('0', 64).',v1='.hash_hmac('sha256', $timestamp.'.'.$body, Fixtures::SECRET);
        $this->deliver($body, $signature)->assertOk();
    }

    #[DataProvider('badSignatures')]
    public function test_invalid_or_stale_signatures_create_no_receipt(string $case): void
    {
        $body = Fixtures::body();
        $signature = match ($case) {
            'missing' => '',
            'wrong-secret' => Fixtures::signature($body, secret: 'whsec_AnotherFixtureSecret'),
            'past' => Fixtures::signature($body, time() - 600),
            'future' => Fixtures::signature($body, time() + 600),
            'legacy-only' => str_replace(',v1=', ',v0=', Fixtures::signature($body)),
            'duplicate-time' => Fixtures::signature($body).',t='.time(),
            'broken-token' => 't,v1',
            'large' => str_repeat('a', 4097),
            default => Fixtures::signature($body),
        };
        if ($case === 'altered-body') {
            $body = str_replace('3499', '3500', $body);
        }
        $response = $this->deliver($body, $signature)->assertBadRequest()->assertExactJson(['code' => 'STRIPE_WEBHOOK_INVALID']);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }

    public static function badSignatures(): array
    {
        return array_combine($cases = ['missing', 'wrong-secret', 'past', 'future', 'legacy-only', 'duplicate-time', 'broken-token', 'large', 'altered-body'], array_map(fn ($case) => [$case], $cases));
    }

    #[DataProvider('badEnvelopes')]
    public function test_signed_but_invalid_or_wrong_scope_payloads_create_no_receipt(string $case): void
    {
        $event = Fixtures::event();
        match ($case) {
            'live' => $event['livemode'] = true,
            'string-mode' => $event['livemode'] = 'false',
            'missing-mode' => $event['livemode'] = null,
            'live-object' => $event['data']['object']['livemode'] = true,
            'other-account' => $event['account'] = 'acct_AnotherStore',
            'connect-account' => $event['account'] = Fixtures::ACCOUNT,
            'context' => $event['context'] = 'org_Unsupported',
            'thin-event' => $event['object'] = 'v2.core.event',
            'missing-object' => $event['data']['object'] = null,
            'array-object' => $event['data']['object'] = [],
            'bad-id' => $event['id'] = 'not-an-event',
            'long-id' => $event['id'] = 'evt_'.str_repeat('a', 129),
            'bad-type' => $event['type'] = '<script>',
            'bad-created' => $event['created'] = '123',
            'bad-version' => $event['api_version'] = ['invalid'],
            default => null,
        };
        $body = match ($case) {
            'invalid-json' => '{"id":',
            'null-json' => 'null',
            'list-json' => '[]',
            'deep-json' => str_repeat('{"nested":', 70).'null'.str_repeat('}', 70),
            default => Fixtures::body($event),
        };
        $this->deliver($body)->assertBadRequest();
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }

    public static function badEnvelopes(): array
    {
        return array_combine($cases = ['live', 'string-mode', 'missing-mode', 'live-object', 'other-account', 'connect-account', 'context', 'thin-event', 'missing-object', 'array-object', 'bad-id', 'long-id', 'bad-type', 'bad-created', 'bad-version', 'invalid-json', 'null-json', 'list-json', 'deep-json'], array_map(fn ($case) => [$case], $cases));
    }

    #[DataProvider('unavailableConfigurations')]
    public function test_receiver_fails_closed_for_incomplete_configuration_or_production(string $case): void
    {
        match ($case) {
            'disabled' => config(['payments.stripe.webhook_enabled' => false]),
            'string-enabled' => config(['payments.stripe.webhook_enabled' => 'true']),
            'live-mode' => config(['payments.stripe.mode' => 'live']),
            'no-account' => config(['payments.stripe.account_id' => null]),
            'invalid-account' => config(['payments.stripe.account_id' => 'not-an-account']),
            'no-secret' => config(['payments.stripe.webhook_secret' => null]),
            'api-key' => config(['payments.stripe.webhook_secret' => 'rk_test_NotAWebhookSecret']),
            'production' => app()->instance('env', 'production'),
            'unknown-env' => app()->instance('env', 'prod'),
        };
        $this->deliver(Fixtures::body())->assertServiceUnavailable()->assertExactJson(['code' => 'STRIPE_WEBHOOK_UNAVAILABLE']);
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }

    public static function unavailableConfigurations(): array
    {
        return array_combine($cases = ['disabled', 'string-enabled', 'live-mode', 'no-account', 'invalid-account', 'no-secret', 'api-key', 'production', 'unknown-env'], array_map(fn ($case) => [$case], $cases));
    }

    public function test_size_and_content_type_checks_run_before_json_decoding_without_trusting_content_length(): void
    {
        $body = str_repeat('x', VerifyStripeWebhook::MAX_BODY_BYTES + 1);
        $this->deliver($body, server: ['CONTENT_LENGTH' => '1'])->assertStatus(413);
        $this->deliver('{}', server: ['CONTENT_LENGTH' => (string) (VerifyStripeWebhook::MAX_BODY_BYTES + 1)])->assertStatus(413);
        $this->deliver(Fixtures::body(), server: ['CONTENT_TYPE' => 'text/plain'])->assertStatus(415);
        $this->deliver(Fixtures::body(), server: ['HTTP_CONTENT_ENCODING' => 'gzip'])->assertStatus(415);
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
    }

    public function test_webhook_is_stateless_while_browser_checkout_still_requires_real_csrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson('/checkout')->assertStatus(419);
        $this->deliver(Fixtures::body())->assertOk();
        $this->get('/webhooks/stripe')->assertStatus(405)->assertExactJson(['code' => 'STRIPE_WEBHOOK_INVALID']);
    }

    public function test_database_failure_is_retryable_and_debug_output_and_logs_exclude_private_data(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && preg_match('/\Ainsert\b/i', $sql) && str_contains($sql, 'stripe_webhook_receipts')) {
                throw new RuntimeException('synthetic@example.invalid '.Fixtures::SECRET);
            }
        });
        $body = Fixtures::body();
        $response = $this->deliver($body)->assertServiceUnavailable()->assertExactJson(['code' => 'STRIPE_WEBHOOK_UNAVAILABLE']);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('stripe_webhook_receipts', 0);
        Log::shouldHaveReceived('error')->once()->with('Stripe webhook request failed.', ['exception_class' => RuntimeException::class]);
        $fail = false;
        $this->deliver($body)->assertOk();
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
    }

    #[DataProvider('immutableOperations')]
    public function test_original_receipts_cannot_be_changed_through_models_or_bulk_sql(string $operation): void
    {
        $body = Fixtures::body();
        $receipt = app(ReceiveStripeWebhook::class)->handle($body, Fixtures::signature($body));
        $blocked = false;
        try {
            match ($operation) {
                'model-update' => $receipt->update(['event_type' => 'changed.event']),
                'model-delete' => $receipt->delete(),
                'sql-update' => DB::table('stripe_webhook_receipts')->where('id', $receipt->id)->update(['payload_ciphertext' => 'changed']),
                'sql-delete' => DB::table('stripe_webhook_receipts')->where('id', $receipt->id)->delete(),
            };
        } catch (LogicException|QueryException) {
            $blocked = true;
        }
        $this->assertTrue($blocked);
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertSame($body, Crypt::decryptString($receipt->fresh()->payload_ciphertext));
    }

    public static function immutableOperations(): array
    {
        return [['model-update'], ['model-delete'], ['sql-update'], ['sql-delete']];
    }

    public function test_operator_command_is_bounded_and_reports_only_receipt_metadata(): void
    {
        $this->deliver(Fixtures::body())->assertOk();
        $this->artisan('vasey:stripe-inbox', ['--limit' => 1])
            ->expectsOutput('Test receipts retained: 1')
            ->doesntExpectOutputToContain('synthetic@example.invalid')
            ->doesntExpectOutputToContain(Fixtures::SECRET)->assertSuccessful();
        $this->artisan('vasey:stripe-inbox', ['--limit' => 101])->assertFailed();
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
    }

    private function deliver(string $body, ?string $signature = null, array $server = []): TestResponse
    {
        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json; charset=utf-8', 'HTTP_ACCEPT' => 'text/html',
            'HTTP_STRIPE_SIGNATURE' => $signature ?? Fixtures::signature($body), ...$server,
        ], $body);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Type', 'application/json');
    }
}
