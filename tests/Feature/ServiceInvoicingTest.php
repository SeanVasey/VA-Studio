<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Services\Invoicing\Models\ServiceInvoice;
use App\Domain\Services\Invoicing\Models\ServiceInvoicePayment;
use App\Domain\Services\Invoicing\ServiceInvoiceException;
use App\Domain\Services\Invoicing\ServiceInvoicePolicy;
use App\Domain\Services\Invoicing\ServiceInvoices;
use App\Domain\Services\Invoicing\StripeInvoiceGateway;
use App\Domain\Services\Projects\ServiceProjectInput;
use App\Domain\Services\Projects\ServiceProjects;
use App\Filament\Resources\ServiceProjectResource\Pages\ViewServiceProject;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeStripeInvoiceGateway;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\Support\StripeWebhookFixtures as Hook;
use Tests\TestCase;

/**
 * BL-70 phase 1: staff issue Stripe test-mode invoices for the authored deposit and balance; only a re-read, matching, paid invoice
 * becomes a verified service payment, and only verified payments move the project's payment state. The gateway is a fake: no network.
 */
class ServiceInvoicingTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private FakeStripeInvoiceGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Queue::fake();
        Hook::configure();
        config(['payments.stripe.processing_enabled' => true, 'services-invoicing.test_enabled' => true,
            'payments.stripe.secret_key' => 'sk_'.'test_SYNTHETICONLY12345']);
        $this->stripe = new FakeStripeInvoiceGateway;
        $this->app->instance(StripeInvoiceGateway::class, $this->stripe);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{0: array, 1: array} the fixture and the accepted project (total 17500, deposit 3500, one milestone) */
    private function accepted(?array $quote = null): array
    {
        $fixture = F::setup();
        $project = $quote === null ? F::accept($fixture) : $this->acceptQuote($fixture, $quote);

        return [$fixture, $project];
    }

    private function acceptQuote(array $fixture, array $quote): array
    {
        $project = F::author($fixture, $quote);

        return $fixture['journey']->customerCommand($project['id'], F::command($project, 'accept_quote', ['quoteId' => $project['quoteId'], 'quoteHash' => $project['quoteHash']]), $fixture['customer']['principal'], $fixture['customer']['user'])['project'];
    }

    private function approveAll(array $fixture, array $project): array
    {
        foreach ([['staff', 'begin_milestone'], ['staff', 'ready_milestone'], ['buyer', 'approve_milestone']] as [$who, $action]) {
            $command = F::command($project, $action, ['milestoneId' => 'mix-review', 'reason' => 'Synthetic scope step']);
            $project = $who === 'staff'
                ? $fixture['journey']->staffCommand($project['id'], $command, $fixture['operator'])['project']
                : $fixture['journey']->customerCommand($project['id'], $command, $fixture['customer']['principal'], $fixture['customer']['user'])['project'];
        }

        return $project;
    }

    private function issue(array $fixture, array $project, string $kind = 'deposit', int $days = 14): array
    {
        return app(ServiceInvoices::class)->issue($project['id'], $kind, $days, $fixture['operator']);
    }

    private function state(array $fixture, array $project): array
    {
        return $fixture['journey']->staffShow($project['id'], $fixture['operator']);
    }

    private function event(string $type, string $invoiceId, ?string $eventId = null, array $object = []): array
    {
        return ['id' => $eventId ?? 'evt_'.Str::random(16), 'object' => 'event', 'api_version' => '2026-08-26.dahlia', 'created' => time(), 'livemode' => false,
            'type' => $type, 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null],
            'data' => ['object' => ['id' => $invoiceId, 'object' => 'invoice', 'livemode' => false, ...$object]]];
    }

    private function deliver(array $event, ?string $signature = null): TestResponse
    {
        $body = Hook::body($event);

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json; charset=utf-8', 'HTTP_ACCEPT' => 'text/html',
            'HTTP_STRIPE_SIGNATURE' => $signature ?? Hook::signature($body),
        ], $body);
    }

    /** Delivers the event through the real receiver and runs the real processor on its receipt. */
    private function settle(array $event): string
    {
        $this->deliver($event)->assertOk();
        $receipt = StripeWebhookReceipt::where('event_id', $event['id'])->sole();

        return app(ProcessStripeReceipt::class)->handle($receipt->id);
    }

    public function test_flag_off_refuses_every_entry_and_never_reaches_stripe(): void
    {
        [$fixture, $project] = $this->accepted();
        config(['services-invoicing.test_enabled' => false]);
        $this->assertFalse(app(ServiceInvoicePolicy::class)->enabled());
        try {
            $this->issue($fixture, $project);
            $this->fail('A disabled flag issued an invoice.');
        } catch (ServiceInvoiceException $error) {
            $this->assertSame(404, $error->status);
        }
        $this->assertSame(0, $this->stripe->issueCalls);
        $this->assertSame(0, ServiceInvoice::count());
        $this->actingAs($fixture['operator']);
        Livewire::test(ViewServiceProject::class, ['record' => $project['id']])->assertActionHidden('issue_deposit_invoice')->assertActionHidden('issue_balance_invoice');
        // A signed paid event is acknowledged, but the processor treats it as unsupported and records nothing.
        $this->assertSame('service_invoicing_off', $this->settle($this->event('invoice.paid', 'in_FakeNothing')));
        $this->assertSame(0, ServiceInvoicePayment::count());
    }

    public function test_production_and_unlisted_environments_refuse_even_with_the_flag_on(): void
    {
        [$fixture, $project] = $this->accepted();
        foreach (['production', 'preview', 'Staging', 'staging-eu'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            try {
                $this->assertFalse(app(ServiceInvoicePolicy::class)->enabled(), $environment);
                $this->assertSame(404, $this->refusal(fn () => $this->issue($fixture, $project)), $environment);
            } finally {
                $this->app->detectEnvironment(fn () => 'testing');
            }
        }
        $this->assertSame(0, $this->stripe->issueCalls);
        $this->assertSame(0, ServiceInvoice::count());
    }

    public function test_staging_admits_the_lane_and_a_live_mode_configuration_does_not(): void
    {
        config(['services-projects.test_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'staging');
        try {
            $this->assertTrue(app(ServiceInvoicePolicy::class)->enabled());
            config(['payments.stripe.mode' => 'live']);
            $this->assertFalse(app(ServiceInvoicePolicy::class)->enabled());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_the_deposit_invoice_is_authored_server_side_from_the_accepted_quote(): void
    {
        [$fixture, $project] = $this->accepted();
        $result = $this->issue($fixture, $project);
        $this->assertFalse($result['replayed']);
        $invoice = $result['invoice'];
        $this->assertSame(['deposit', 'open', 3500, 'USD', 14], [$invoice['kind'], $invoice['state'], $invoice['amountMinor'], $invoice['currency'], $invoice['daysUntilDue']]);
        $this->assertStringStartsWith('https://invoice.stripe.com/', $invoice['hostedInvoiceUrl']);
        $spec = $this->stripe->specs[0];
        $this->assertSame(3500, $spec['amount_minor']);
        $this->assertSame('USD', $spec['currency']);
        $this->assertSame($fixture['customer']['user']->email, $spec['email']);
        $this->assertSame(1, preg_match('/\A[a-f0-9]{64}\z/', $spec['key']));
        // Derived from project, kind, quote and revision, so the same identity can only ever address the same provider invoice.
        $this->assertSame(hash('sha256', 'service-invoice-v1|'.$project['id'].'|deposit|'.$project['quoteId'].'|1'), $spec['key']);
        $row = ServiceInvoice::sole();
        $this->assertSame($this->stripe->onlyInvoiceId(), $row->stripe_invoice_id);
        $this->assertSame($spec['customer_id'] ?? $this->stripe->invoices[$row->stripe_invoice_id]['customer'], $row->stripe_customer_id);
        // Issuing is not payment: the project still reads as uncollected and nothing is authorized.
        $state = $this->state($fixture, $project);
        $this->assertSame('not_collected', $state['paymentState']);
        $this->assertFalse($state['deliveryAuthorized']);
        $this->assertSame(0, ServiceInvoicePayment::count());
        $this->assertSame(['service_invoice.reserved', 'service_invoice.issued'], AuditEvent::where('action', 'like', 'service_invoice.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_the_balance_is_the_authored_total_less_the_deposit_and_waits_for_every_milestone(): void
    {
        [$fixture, $project] = $this->accepted();
        try {
            $this->issue($fixture, $project, 'balance');
            $this->fail('A balance was invoiced before scope review.');
        } catch (ServiceInvoiceException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->approveAll($fixture, $project);
        $this->assertSame(14000, $this->issue($fixture, $project, 'balance')['invoice']['amountMinor']);
    }

    public function test_it_refuses_projects_with_no_accepted_quote_and_quotes_with_nothing_to_invoice(): void
    {
        $fixture = F::setup();
        foreach ([null, 'quoted'] as $step) {
            if ($step === 'quoted') {
                F::author($fixture);
            }
            try {
                $this->issue($fixture, ['id' => $fixture['project']['id']]);
                $this->fail('Invoiced before acceptance.');
            } catch (ServiceInvoiceException $error) {
                $this->assertSame(409, $error->status);
            }
        }
        $this->assertSame(0, $this->stripe->issueCalls);
        [$zero, $zeroProject] = $this->accepted(F::quote(['depositMinor' => 0]));
        $this->assertSame(422, $this->refusal(fn () => $this->issue($zero, $zeroProject)));
        [$euro, $euroProject] = $this->accepted(F::quote(['currency' => 'EUR']));
        $this->assertSame(422, $this->refusal(fn () => $this->issue($euro, $euroProject)));
        [$whole, $wholeProject] = $this->accepted(F::quote(['depositMinor' => 17500]));
        $this->approveAll($whole, $wholeProject);
        $this->assertSame(422, $this->refusal(fn () => $this->issue($whole, $wholeProject, 'balance')));
        $this->assertSame(0, $this->stripe->issueCalls);
        $this->assertSame(0, ServiceInvoice::count());
    }

    private function refusal(callable $operation): int
    {
        try {
            $operation();
        } catch (ServiceInvoiceException $error) {
            return $error->status;
        }
        $this->fail('The command was not refused.');
    }

    public function test_the_command_validates_its_only_inputs(): void
    {
        [$fixture, $project] = $this->accepted();
        $invoices = app(ServiceInvoices::class);
        $this->assertSame(422, $this->refusal(fn () => $invoices->issue($project['id'], 'milestone', 14, $fixture['operator'])));
        foreach ([0, 91, -1] as $days) {
            $this->assertSame(422, $this->refusal(fn () => $invoices->issue($project['id'], 'deposit', $days, $fixture['operator'])));
        }
        $this->assertSame(404, $this->refusal(fn () => $invoices->issue('not-a-uuid', 'deposit', 14, $fixture['operator'])));
        $this->assertSame(404, $this->refusal(fn () => $invoices->issue((string) Str::uuid(), 'deposit', 14, $fixture['operator'])));
        $this->expectException(AuthorizationException::class);
        $invoices->issue($project['id'], 'deposit', 14, $fixture['customer']['user']);
    }

    public function test_reissuing_is_idempotent_and_never_creates_a_second_invoice(): void
    {
        [$fixture, $project] = $this->accepted();
        $first = $this->issue($fixture, $project);
        $again = $this->issue($fixture, $project);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['invoice']['id'], $again['invoice']['id']);
        $this->assertSame(1, ServiceInvoice::count());
        $this->assertCount(1, $this->stripe->invoices);
        $this->assertSame(1, $this->stripe->issueCalls, 'A settled replay must not call the provider again.');
        // The same identity with other terms is a conflict, not a second invoice.
        $this->assertSame(409, $this->refusal(fn () => $this->issue($fixture, $project, 'deposit', 30)));
        $this->assertSame(1, ServiceInvoice::count());
    }

    public function test_an_unconfirmed_provider_call_leaves_a_resumable_row_and_a_repeat_creates_nothing_new(): void
    {
        [$fixture, $project] = $this->accepted();
        foreach (['before', 'after'] as $failure) {
            $this->stripe->failIssue = $failure;
            $this->assertSame(503, $this->refusal(fn () => $this->issue($fixture, $project)));
            $this->assertSame('creating', ServiceInvoice::sole()->state);
            $this->assertNull(ServiceInvoice::sole()->stripe_invoice_id);
            if ($failure === 'before') {
                continue;
            }
        }
        $resumed = $this->issue($fixture, $project);
        $this->assertTrue($resumed['replayed']);
        $this->assertSame('open', $resumed['invoice']['state']);
        $this->assertSame(1, ServiceInvoice::count());
        $this->assertCount(1, $this->stripe->invoices, 'The provider idempotency key yields one invoice across the retries.');
        $this->assertSame(3, $this->stripe->issueCalls);
        $this->assertSame(1, count(array_unique(array_column($this->stripe->specs, 'key'))));
    }

    public function test_a_provider_invoice_that_does_not_match_the_quote_is_never_recorded(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->app->instance(StripeInvoiceGateway::class, new class($this->stripe) implements StripeInvoiceGateway
        {
            public function __construct(private FakeStripeInvoiceGateway $inner) {}

            public function issue(array $spec): array
            {
                $result = $this->inner->issue($spec);
                $result['invoice']['total'] = $result['invoice']['amount_due'] = 1;

                return $result;
            }

            public function retrieve(string $invoiceId): array
            {
                return $this->inner->retrieve($invoiceId);
            }
        });
        $this->assertSame(503, $this->refusal(fn () => $this->issue($fixture, $project)));
        $row = ServiceInvoice::sole();
        $this->assertSame('creating', $row->state);
        $this->assertNull($row->stripe_invoice_id);
    }

    public function test_a_verified_paid_invoice_records_one_payment_and_moves_the_payment_state(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id);
        // The event claims an absurd amount: its numbers are never read.
        $outcome = $this->settle($this->event('invoice.paid', $id, null, ['amount_paid' => 999999, 'total' => 1, 'currency' => 'eur']));
        $this->assertSame('payment_verified', $outcome);
        $payment = ServiceInvoicePayment::sole();
        $this->assertSame([3500, 'USD', $id], [$payment->amount_minor, $payment->currency, $payment->stripe_invoice_id]);
        $this->assertSame('paid', ServiceInvoice::sole()->state);
        $state = $this->state($fixture, $project);
        $this->assertSame('partially_paid', $state['paymentState']);
        $this->assertFalse($state['deliveryAuthorized']);
        $this->assertNotContains($state['status'], ['completed', 'complete', 'paid', 'delivered']);
        $this->assertSame('processed', StripeReceiptWork::sole()->state);
        foreach (['orders', 'verified_payments', 'payment_observations', 'pending_entitlements'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_deposit_and_balance_payments_together_make_the_project_paid_in_full(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->approveAll($fixture, $project);
        $deposit = $this->issue($fixture, $project)['invoice'];
        $balance = $this->issue($fixture, $project, 'balance')['invoice'];
        $this->assertNotSame($deposit['id'], $balance['id']);
        $ids = ServiceInvoice::orderBy('id')->pluck('stripe_invoice_id', 'kind')->all();
        $this->stripe->pay($ids['balance']);
        $this->assertSame('payment_verified', $this->settle($this->event('invoice.paid', $ids['balance'])));
        $this->assertSame('partially_paid', $this->state($fixture, $project)['paymentState']);
        $this->stripe->pay($ids['deposit']);
        $this->assertSame('payment_verified', $this->settle($this->event('invoice.paid', $ids['deposit'])));
        $this->assertSame('paid_in_full', $this->state($fixture, $project)['paymentState']);
        $this->assertSame(17500, (int) ServiceInvoicePayment::sum('amount_minor'));
    }

    public function test_replayed_and_duplicate_webhooks_never_double_record(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id);
        $event = $this->event('invoice.paid', $id);
        $this->assertSame('payment_verified', $this->settle($event));
        $receipt = StripeWebhookReceipt::sole();
        // The same event delivered again: one receipt, no second work, no second payment.
        $this->deliver($event)->assertOk();
        $this->assertSame(1, StripeWebhookReceipt::count());
        $this->assertSame('payment_verified', app(ProcessStripeReceipt::class)->handle($receipt->id));
        // An operator replay re-reads the provider and finds the settled invoice unchanged.
        $this->assertSame('already_paid', app(ProcessStripeReceipt::class)->handle($receipt->id, true));
        // A different event id for the same settled invoice only re-checks the evidence.
        $this->assertSame('already_paid', $this->settle($this->event('invoice.paid', $id)));
        $this->assertSame(1, ServiceInvoicePayment::count());
        $this->assertSame(1, AuditEvent::where('action', 'service_invoice.payment_verified')->count());
        // The same event id with changed content is a conflict on the receiver.
        $changed = $event;
        $changed['data']['object']['amount_paid'] = 5;
        $this->deliver($changed)->assertStatus(409);
    }

    public function test_an_unpaid_invoice_is_never_a_payment_whatever_the_event_says(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->assertSame('still_open', $this->settle($this->event('invoice.paid', $id, null, ['status' => 'paid', 'amount_paid' => 3500])));
        $this->assertSame(0, ServiceInvoicePayment::count());
        $this->assertSame('open', ServiceInvoice::sole()->state);
        $this->assertSame('not_collected', $this->state($fixture, $project)['paymentState']);
    }

    public static function contradictions(): array
    {
        return [
            'short payment' => [['amount_paid' => 100, 'amount_remaining' => 3400]],
            'overpayment' => [['amount_overpaid' => 50]],
            'paid off Stripe' => [['amount_paid_off_stripe' => 3500]],
            'credit note after payment' => [['post_payment_credit_notes_amount' => 3500]],
            'changed total' => [['total' => 3000, 'amount_due' => 3000, 'amount_paid' => 3000]],
            'other currency' => [['currency' => 'eur']],
            'another customer' => [['customer' => 'cus_SomeoneElse']],
            'another invoice reference' => [['metadata' => ['va_service_invoice' => '2f9d2e0e-3d0e-4c5a-9a3e-1b2c3d4e5f60']]],
            'auto charge invoice' => [['collection_method' => 'charge_automatically']],
            'live mode object' => [['livemode' => true]],
            'connected account' => [['account' => 'acct_Other']],
            'no paid time' => [['status_transitions' => ['paid_at' => null]]],
        ];
    }

    #[DataProvider('contradictions')]
    public function test_a_paid_invoice_that_contradicts_the_authored_facts_is_quarantined_and_records_nothing(array $change): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id, $change);
        $this->assertSame('payment_changed', $this->settle($this->event('invoice.paid', $id)));
        $this->assertSame(0, ServiceInvoicePayment::count());
        $this->assertSame('open', ServiceInvoice::sole()->state);
        $this->assertSame('quarantined', StripeReceiptWork::sole()->state);
        $this->assertSame('not_collected', $this->state($fixture, $project)['paymentState']);
    }

    public function test_receiver_refusals_create_no_receipt_and_no_payment(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id);
        $live = $this->event('invoice.paid', $id);
        $live['livemode'] = true;
        $this->deliver($live)->assertStatus(400);
        $connect = $this->event('invoice.paid', $id);
        $connect['account'] = 'acct_ConnectedOther';
        $this->deliver($connect)->assertStatus(400);
        $this->deliver($this->event('invoice.paid', $id), 't='.time().',v1='.str_repeat('0', 64))->assertStatus(400);
        $this->deliver($this->event('invoice.paid', $id), 'invalid')->assertStatus(400);
        $this->assertSame(0, StripeWebhookReceipt::count());
        $this->assertSame(0, ServiceInvoicePayment::count());
    }

    public function test_a_provider_outage_retries_and_a_foreign_invoice_is_unsupported(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id);
        $event = $this->event('invoice.paid', $id);
        $this->stripe->retrieveUnavailable = true;
        $this->assertSame('retry', $this->settle($event));
        $this->assertSame(0, ServiceInvoicePayment::count());
        $this->assertSame('retry', StripeReceiptWork::sole()->state);
        $this->stripe->retrieveUnavailable = false;
        $this->travelTo(now()->addHour());
        $this->assertSame('payment_verified', app(ProcessStripeReceipt::class)->handle(StripeWebhookReceipt::sole()->id));
        // An invoice from some other Stripe use of the account is not ours and is never touched.
        $this->stripe->invoices['in_FakeForeign'] = [...$this->stripe->invoices[$id], 'id' => 'in_FakeForeign', 'metadata' => []];
        $this->assertSame('not_a_service_invoice', $this->settle($this->event('invoice.paid', 'in_FakeForeign')));
        $this->assertSame(1, ServiceInvoicePayment::count());
    }

    public function test_void_and_uncollectible_follow_the_provider_and_a_void_can_be_reissued_as_a_new_revision(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $first = $this->stripe->onlyInvoiceId();
        $this->stripe->set($first, ['status' => 'void']);
        $this->assertSame('void', $this->settle($this->event('invoice.voided', $first)));
        $this->assertSame('void', ServiceInvoice::sole()->state);
        // A late "paid" event for a voided invoice contradicts the provider's own state and is not a payment.
        $this->stripe->set($first, ['status' => 'paid', 'amount_paid' => 3500, 'amount_remaining' => 0, 'status_transitions' => ['paid_at' => time()]]);
        $this->assertSame('payment_changed', $this->settle($this->event('invoice.paid', $first)));
        $this->assertSame(0, ServiceInvoicePayment::count());
        $second = $this->issue($fixture, $project);
        $this->assertFalse($second['replayed']);
        $this->assertSame(2, $second['invoice']['revision']);
        $this->assertCount(2, $this->stripe->invoices);
        $this->assertCount(2, array_unique(array_column($this->stripe->specs, 'key')));
        // Uncollectible does not forbid a later payment.
        $next = array_key_last($this->stripe->invoices);
        $this->stripe->set($next, ['status' => 'uncollectible']);
        $this->assertSame('uncollectible', $this->settle($this->event('invoice.marked_uncollectible', $next)));
        $this->stripe->pay($next);
        $this->assertSame('payment_verified', $this->settle($this->event('invoice.paid', $next)));
        $this->assertSame('paid', ServiceInvoice::where('revision', 2)->sole()->state);
        $this->assertSame('partially_paid', $this->state($fixture, $project)['paymentState']);
    }

    public function test_payment_failures_are_counted_per_event_and_never_pay(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $id = $this->stripe->onlyInvoiceId();
        $this->assertSame('payment_failure_recorded', $this->settle($this->event('invoice.payment_failed', $id)));
        $this->assertSame('payment_failure_recorded', $this->settle($this->event('invoice.payment_failed', $id)));
        $event = $this->event('invoice.payment_failed', $id);
        $this->settle($event);
        $this->settle($event);
        $row = ServiceInvoice::sole();
        $this->assertSame(3, $row->payment_failures);
        $this->assertSame('open', $row->state);
        $this->assertSame(0, ServiceInvoicePayment::count());
    }

    public function test_a_crash_between_stripe_and_the_local_record_is_adopted_by_the_paid_event(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->stripe->failIssue = 'after';
        $this->assertSame(503, $this->refusal(fn () => $this->issue($fixture, $project)));
        $this->assertNull(ServiceInvoice::sole()->stripe_invoice_id);
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id);
        $this->assertSame('payment_verified', $this->settle($this->event('invoice.paid', $id)));
        $row = ServiceInvoice::sole();
        $this->assertSame([$id, 'paid'], [$row->stripe_invoice_id, $row->state]);
        $this->assertSame(1, ServiceInvoicePayment::count());
    }

    public function test_a_provider_invoice_naming_a_row_with_other_facts_is_not_adopted(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->stripe->failIssue = 'after';
        $this->assertSame(503, $this->refusal(fn () => $this->issue($fixture, $project)));
        $id = $this->stripe->onlyInvoiceId();
        $this->stripe->pay($id, ['total' => 1, 'amount_due' => 1, 'amount_paid' => 1]);
        $this->assertSame('payment_changed', $this->settle($this->event('invoice.paid', $id)));
        $this->assertNull(ServiceInvoice::sole()->stripe_invoice_id);
        $this->assertSame(0, ServiceInvoicePayment::count());
    }

    public function test_the_database_refuses_forged_or_rewritten_invoice_evidence(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $this->stripe->pay($this->stripe->onlyInvoiceId());
        $this->settle($this->event('invoice.paid', $this->stripe->onlyInvoiceId()));
        $row = ServiceInvoice::sole();
        $payment = ServiceInvoicePayment::sole();
        $attempt = function (string $sql, array $bindings = []): bool {
            try {
                DB::statement($sql, $bindings);

                return true;
            } catch (QueryException) {
                return false;
            }
        };
        $this->assertFalse($attempt('UPDATE service_invoice_payments SET amount_minor = 1 WHERE id = ?', [$payment->id]));
        $this->assertFalse($attempt('DELETE FROM service_invoice_payments WHERE id = ?', [$payment->id]));
        $this->assertFalse($attempt('DELETE FROM service_invoices WHERE id = ?', [$row->id]));
        $this->assertFalse($attempt("UPDATE service_invoices SET state = 'open' WHERE id = ?", [$row->id]), 'A paid invoice is final.');
        $this->assertFalse($attempt('UPDATE service_invoices SET amount_minor = 1 WHERE id = ?', [$row->id]));
        $this->assertSame(3500, ServiceInvoice::sole()->amount_minor);
        // A paid state needs a matching verified payment; a payment must match its invoice's amount and open state.
        $this->approveAll($fixture, $project);
        $balance = $this->issue($fixture, $project, 'balance')['invoice'];
        $other = ServiceInvoice::where('public_id', $balance['id'])->sole();
        $this->assertFalse($attempt("UPDATE service_invoices SET state = 'paid' WHERE id = ?", [$other->id]));
        $forged = fn (int $amount): bool => $attempt('INSERT INTO service_invoice_payments (public_id, service_invoice_id, project_id, quote_id, stripe_invoice_id, stripe_webhook_receipt_id, amount_minor, currency, evidence_hash, verified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $other->id, $other->project_id, $other->quote_id, $other->stripe_invoice_id, StripeWebhookReceipt::sole()->id, $amount, 'USD', str_repeat('a', 64), now()->toDateTimeString()]);
        $this->assertFalse($forged(1), 'A payment for a different amount than the invoice.');
        $this->assertTrue($forged($other->amount_minor), 'The matching payment of an open invoice is the one allowed insert.');
    }

    public function test_no_buyer_or_staff_project_command_gained_a_payment_or_completion_path(): void
    {
        foreach (['issue_invoice', 'invoice', 'mark_paid', 'record_payment', 'complete', 'authorize_delivery'] as $action) {
            foreach ([true, false] as $staff) {
                try {
                    ServiceProjectInput::command(['requestKey' => (string) Str::uuid(), 'expectedVersion' => 1, 'action' => $action, 'reason' => 'x'], $staff);
                    $this->fail($action.' must not be a project command.');
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_the_operator_action_issues_the_authored_invoice_and_repeating_it_is_harmless(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->actingAs($fixture['operator']);
        $page = Livewire::test(ViewServiceProject::class, ['record' => $project['id']])->assertActionVisible('issue_deposit_invoice')->assertActionHidden('issue_balance_invoice');
        $page->mountAction('issue_deposit_invoice')->setActionData(['daysUntilDue' => 21])->callMountedAction()->assertHasNoActionErrors()->assertNotified('Test invoice ready');
        $page->mountAction('issue_deposit_invoice')->setActionData(['daysUntilDue' => 21])->callMountedAction()->assertHasNoActionErrors();
        $row = ServiceInvoice::sole();
        $this->assertSame([3500, 21, 'open'], [$row->amount_minor, $row->days_until_due, $row->state]);
        $page->mountAction('issue_deposit_invoice')->setActionData(['daysUntilDue' => 30])->callMountedAction()->assertHasActionErrors();
        $this->assertSame(1, ServiceInvoice::count());
        // The operator page shows the hosted page, not any secret, and the payment state.
        Livewire::test(ViewServiceProject::class, ['record' => $project['id']])->assertSee($row->hosted_invoice_url)->assertSee('Payment state: not collected');
    }

    public function test_the_policy_requires_the_service_project_lane_too(): void
    {
        config(['services-projects.test_enabled' => false]);
        $this->assertFalse(app(ServiceInvoicePolicy::class)->enabled());
    }

    public function test_the_project_projection_exposes_no_provider_identifier(): void
    {
        [$fixture, $project] = $this->accepted();
        $this->issue($fixture, $project);
        $json = json_encode($this->state($fixture, $project));
        $this->assertStringNotContainsString($this->stripe->onlyInvoiceId(), $json);
        $this->assertStringNotContainsString('invoice.stripe.com', $json);
        $this->assertInstanceOf(ServiceProjects::class, $fixture['journey']);
    }
}
