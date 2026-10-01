<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Filament\Resources\CustomerInquiryResource\Pages\ListCustomerInquiries;
use App\Filament\Resources\CustomerInquiryResource\Pages\ViewCustomerInquiry;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InquiryAdministrationRace;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\SiteEditorialFixtures;
use Tests\TestCase;

/** Committed fixtures; existing admission and administration cases remain separate and unchanged. */
class CustomerInquiryAdministrationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private User $operator;

    private CustomerInquiry $inquiry;

    private bool $mfaWasRequired;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql' && in_array($this->name(), [
            'test_administration_and_revocation_serialize_on_current_authority_in_both_lock_orders',
            'test_livewire_private_getters_reject_revocation_hidden_by_an_old_repeatable_read_snapshot',
        ], true)) {
            $this->markTestSkipped('Independent administration authority locking requires MySQL, not SQLite.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $this->mfaWasRequired = $panel->isMultiFactorAuthenticationRequired();
        $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
        $this->operator = LicenseFixtures::admin();
        $this->operator->saveAppAuthenticationSecret('ABCDEFGHIJKLMNOP');
        config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC PRIVATE AUTHORITY NOTICE',
            'inquiries.retention_policy_reference' => 'SYNTHETIC-AUTHORITY-RETENTION', 'inquiries.operator_user_id' => $this->operator->id,
            'inquiries.operator_notifications_enabled' => false]);
        $site = app(SiteContent::class);
        $release = $site->create(SiteEditorialFixtures::content(), 'Synthetic administration contact', $this->operator);
        $site->publish($release->id, 0, $this->operator);
        app(SubmitInquiry::class)->handle(['name' => 'Synthetic Authority Buyer', 'email' => 'authority-buyer@example.test',
            'subject' => 'Synthetic retained private subject', 'message' => "Synthetic retained private body.\nExact second line.",
            'website' => '', 'requestKey' => (string) Str::uuid()], hash('sha256', 'synthetic-administration-owner'));
        $this->inquiry = CustomerInquiry::sole();
        $this->actingAs($this->operator);
    }

    protected function tearDown(): void
    {
        if (isset($this->mfaWasRequired)) {
            $panel = Filament::getPanel('admin');
            $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $this->mfaWasRequired);
        }
        parent::tearDown();
    }

    public static function revocations(): array
    {
        return ['admin role' => ['is_admin', 0], 'verified email' => ['email_verified_at', null],
            'MFA enrollment' => ['app_authentication_secret', null]];
    }

    public static function administrationLockOrders(): array
    {
        $cases = [];
        foreach (['view', 'inbox', 'transition'] as $operation) {
            foreach (self::revocations() as $label => [$field, $value]) {
                foreach ([0 => 'administration first', 1 => 'revocation first'] as $first => $order) {
                    $cases[$operation.' '.$label.' '.$order] = [$operation, $field, $value, $first];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('administrationLockOrders')]
    public function test_administration_and_revocation_serialize_on_current_authority_in_both_lock_orders(string $operation, string $field, mixed $value, int $first): void
    {
        // Retain genuine unrelated checkout intent/session evidence, using only the bound synthetic transport.
        CheckoutFixtures::configure();
        $gateway = CheckoutFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $checkout = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($checkout['order']->public_id, InventoryFixtures::OWNER);
        config(['payments.stripe.checkout_enabled' => false]);
        $this->assertDatabaseCount('checkout_intents', 1);
        $this->assertDatabaseCount('checkout_sessions', 1);
        $calls = $gateway->calls;
        $before = $this->evidence();
        $race = InquiryAdministrationRace::run($this, $this->inquiry->id, $this->operator->id, $operation, $field, $first);
        $this->assertSame($first, $race['winner']);
        $this->assertSame(['revoked', $field], [$race['results'][1]['result'], $race['results'][1]['field']]);
        $after = $this->evidence();
        $expected = $before;
        foreach ($expected['users'] as &$row) {
            if ($row['id'] === $this->operator->id) {
                $row[$field] = $value;
            }
        }
        unset($row);
        $result = $race['results'][0];
        if ($first === 0) {
            $this->assertSame(['authorized', $operation], [$result['result'], $result['operation']]);
            $this->assertSame([['receipt' => $this->inquiry->public_id, 'state' => $operation === 'transition' ? 'archived' : 'new',
                'version' => $operation === 'transition' ? 1 : 0, 'payload' => $this->inquiry->payload]], $result['body']);
            if ($operation === 'transition') {
                $retained = $after['customer_inquiries'][0];
                $original = $before['customer_inquiries'][0];
                $this->assertSame(array_diff_key($original, array_flip(['state', 'version', 'updated_at'])),
                    array_diff_key($retained, array_flip(['state', 'version', 'updated_at'])));
                $this->assertSame(['archived', 1], [$retained['state'], $retained['version']]);
                $this->assertNotNull($retained['updated_at']);
                $expected['customer_inquiries'][0] = $retained;
            }
            $action = match ($operation) {
                'view' => 'inquiry.viewed', 'inbox' => 'inquiry.inbox_viewed', 'transition' => 'inquiry.archived',
            };
            $audit = AuditEvent::where('action', $action)->sole();
            $this->assertSame($this->operator->id, $audit->actor_id);
            $this->assertSame($operation === 'inbox' ? User::class : CustomerInquiry::class, $audit->subject_type);
            $this->assertSame($operation === 'inbox' ? $this->operator->id : $this->inquiry->id, $audit->subject_id);
            $context = match ($operation) {
                'view' => ['receipt' => $this->inquiry->public_id, 'state' => 'new'],
                'inbox' => ['scope' => 'retained inquiries'],
                'transition' => ['receipt' => $this->inquiry->public_id, 'before' => 'new', 'version' => 1],
            };
            $actualContext = $audit->context;
            ksort($context, SORT_STRING);
            ksort($actualContext, SORT_STRING);
            $this->assertSame($context, $actualContext);
            $this->assertCount(count($before['audit_events']) + 1, $after['audit_events']);
            $this->assertSame($before['audit_events'], array_slice($after['audit_events'], 0, count($before['audit_events'])));
            $expected['audit_events'][] = (array) DB::table('audit_events')->where('id', $audit->id)->first();
        } else {
            $this->assertSame(['rejected', 403], [$result['result'], $result['status']]);
            $this->assertArrayNotHasKey('body', $result);
            $this->assertArrayNotHasKey('receipt', $result);
        }
        $this->assertSame($expected, $after, 'Only the ordered revocation and authorized operation may change retained evidence.');
        foreach ($this->privateOperations() as $privateOperation) {
            $this->assertDenied($privateOperation);
        }
        $this->assertSame($after, $this->evidence());
        $this->assertSame($calls, $gateway->calls);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('revocations')]
    public function test_livewire_cached_and_uncached_private_getters_revalidate_authority_before_return(string $field, mixed $value): void
    {
        [$inbox, $detail] = $this->retainedPages();
        DB::table('users')->where('id', $this->operator->id)->update([$field => $value]);
        $before = $this->evidence();
        $this->assertPrivateGettersDenied($inbox, $detail);
        $inbox->flushCachedTableRecords();
        $this->assertPrivateGettersDenied($inbox, $detail);
        $this->assertSame($before, $this->evidence());
    }

    #[DataProvider('revocations')]
    public function test_livewire_private_getters_reject_revocation_hidden_by_an_old_repeatable_read_snapshot(string $field, mixed $value): void
    {
        [$inbox, $detail] = $this->retainedPages();
        $before = $this->evidence();
        config(['database.connections.inquiry_admin_revocation' => config('database.connections.'.DB::getDefaultConnection())]);
        $other = DB::connection('inquiry_admin_revocation');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $other->selectOne('SELECT CONNECTION_ID() AS id')->id);
        DB::beginTransaction();
        try {
            $oldActor = User::findOrFail($this->operator->id);
            $this->assertTrue(Gate::forUser($oldActor)->allows('administer-catalog'));
            $this->assertTrue(AdminMultiFactor::satisfiedBy($oldActor));
            $other->transaction(function () use ($other, $field, $value): void {
                $this->assertNotNull($other->table('users')->where('id', $this->operator->id)->lockForUpdate()->first());
                $this->assertSame(1, $other->table('users')->where('id', $this->operator->id)->update([$field => $value]));
            });
            // Ordinary preflight demonstrably still sees the eligible old RR read view.
            $this->assertSame($oldActor->id, app(InquiryAdministration::class)->actor($oldActor)->id);
            $this->assertPrivateGettersDenied($inbox, $detail);
            $inbox->flushCachedTableRecords();
            $this->assertPrivateGettersDenied($inbox, $detail);
        } finally {
            DB::rollBack();
            DB::purge('inquiry_admin_revocation');
        }
        $expected = $before;
        foreach ($expected['users'] as &$row) {
            if ($row['id'] === $this->operator->id) {
                $row[$field] = $value;
            }
        }
        unset($row);
        $after = $this->evidence();
        $this->assertSame($expected, $after, 'Denied stale-snapshot getters may retain only the independently committed authority revocation.');
        foreach ($this->privateOperations() as $privateOperation) {
            $this->assertDenied($privateOperation);
        }
        $this->assertSame($after, $this->evidence());
        $this->assertSame($value, DB::table('users')->where('id', $this->operator->id)->value($field));
    }

    public function test_locking_authority_fails_closed_outside_transactions_and_read_callbacks_cannot_return_deferred_queries(): void
    {
        $service = app(InquiryAdministration::class);
        $this->assertSame($this->operator->id, $service->actor($this->operator)->id);
        $this->assertDenied(fn () => $service->actor($this->operator, lockForUpdate: true));
        $before = $this->evidence();
        foreach ([fn () => CustomerInquiry::query(), fn () => DB::table('customer_inquiries'), fn () => $this->operator->notifications(),
            fn () => new LazyCollection(fn () => CustomerInquiry::all()), fn () => (function () {
                yield $this->inquiry;
            })()] as $deferred) {
            try {
                $service->authorizedRead($this->operator, $deferred);
                $this->fail('Deferred private read escaped its authority transaction.');
            } catch (\LogicException $error) {
                $this->assertSame('Private inquiry reads must complete before the authority lock is released.', $error->getMessage());
            }
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertSame($this->inquiry->public_id, $service->authorizedRead($this->operator, fn () => CustomerInquiry::sole())->public_id);
        $this->assertSame($before, $this->evidence());
    }

    private function retainedPages(): array
    {
        $inbox = Livewire::test(ListCustomerInquiries::class)->assertCanSeeTableRecords([$this->inquiry])->instance();
        $detail = Livewire::test(ViewCustomerInquiry::class, ['record' => $this->inquiry->public_id])->instance();
        $this->assertSame(1, $inbox->getTableRecords()->count());
        $this->assertSame($this->inquiry->id, $inbox->getTableRecord((string) $this->inquiry->id)->id);
        $this->assertSame(1, $inbox->getAllTableRecordsCount());
        $this->assertSame($this->inquiry->id, $detail->getRecord()->id);

        return [$inbox, $detail];
    }

    private function assertPrivateGettersDenied(ListCustomerInquiries $inbox, ViewCustomerInquiry $detail): void
    {
        $privateQueries = [];
        $inspect = true;
        DB::listen(function ($query) use (&$privateQueries, &$inspect): void {
            if ($inspect && str_contains($query->sql, 'customer_inquiries')) {
                $privateQueries[] = $query->sql;
            }
        });
        try {
            foreach ([fn () => $inbox->getTableRecords(), fn () => $inbox->getTableRecord((string) $this->inquiry->id),
                fn () => $inbox->getAllTableRecordsCount(), fn () => $detail->getRecord(),
                fn () => (new \ReflectionMethod($detail, 'resolveRecord'))->invoke($detail, $this->inquiry->public_id)] as $read) {
                $this->assertDenied($read);
            }
            $this->assertSame([], $privateQueries, 'Revoked private getters must fail before any inquiry SQL or cached body return.');
        } finally {
            $inspect = false;
        }
    }

    private function privateOperations(): array
    {
        $service = app(InquiryAdministration::class);

        return [fn () => $service->view($this->inquiry->id, $this->operator), fn () => $service->openInbox($this->operator),
            fn () => $service->transition($this->inquiry->id, 'archived', $this->inquiry->version, $this->operator),
            fn () => $service->authorizedRead($this->operator, fn () => CustomerInquiry::all())];
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Revoked private authority returned a result.');
        } catch (AuthorizationException $error) {
            $this->assertSame(403, $error->status() ?? 403);
        }
    }

    /** All raw retained columns, including unrelated intent and commerce evidence. */
    private function evidence(): array
    {
        $evidence = [];
        foreach (['users', 'customer_inquiries', 'audit_events', 'site_publications', 'site_releases', 'site_publication_revisions',
            'checkout_intents', 'checkout_sessions', 'checkout_observations', 'orders', 'order_lines', 'order_attempts',
            'quotes', 'quote_lines', 'quote_pricings', 'inventory_reservations', 'inventory_claims', 'promotion_uses'] as $table) {
            $evidence[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $evidence;
    }
}
