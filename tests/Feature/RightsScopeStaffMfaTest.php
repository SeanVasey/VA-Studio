<?php

namespace Tests\Feature;

use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\QuoteException;
use App\Domain\Media\MalwareScanner;
use App\Domain\Staff\StaffWriteAuthority;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LicenseFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/**
 * #68 review condition C2 for the shared rights inventory: `ManageRightsScope` applies the admin panel's staff
 * multi-factor rule itself, and a console caller may replace it only with a recorded attestation bound to the
 * operator, action, scope and revision of the write.
 */
class RightsScopeStaffMfaTest extends TestCase
{
    use RefreshDatabase;

    /** RefreshDatabase wraps each test in a transaction; refusals must leave that level untouched. */
    private int $restingLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->restingLevel = DB::transactionLevel();
    }

    private function in(string $environment, Closure $probe): mixed
    {
        $this->app->detectEnvironment(fn () => $environment);
        try {
            return $probe();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    /** `QuoteFixtures::selection()` enrolls its admin; this one is a second, unenrolled administrator. */
    private function unenrolled(): User
    {
        return LicenseFixtures::admin();
    }

    /** Staging accepts only ClamAV scan evidence, so the fixture media carry a synthetic host-scan stand-in. */
    private function selection(): array
    {
        return QuoteFixtures::selection(scanner: new class extends MalwareScanner
        {
            public function scan(string $path): array
            {
                return ['engine' => 'clamav', 'version' => 'synthetic-host-scan-stand-in', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
            }
        });
    }

    private function evidence(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['rights_scopes', 'rights_scope_offers', 'audit_events']);
    }

    private function refused(Closure $operation, string $message = StaffWriteAuthority::REFUSAL, string $case = ''): void
    {
        try {
            $operation();
            $this->fail('The rights-scope write was admitted'.($case === '' ? '.' : ' ('.$case.').'));
        } catch (AuthorizationException $exception) {
            $this->assertSame($message, $exception->getMessage(), $case);
        }
        $this->assertSame($this->restingLevel, DB::transactionLevel());
    }

    private function unavailable(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('The rights-scope write was admitted outside the test environments.');
        } catch (QuoteException $exception) {
            $this->assertSame('INVENTORY_UNAVAILABLE', $exception->errorCode);
            $this->assertSame(503, $exception->status);
        }
        $this->assertSame($this->restingLevel, DB::transactionLevel());
    }

    public function test_staging_refuses_register_link_and_block_for_an_admin_without_the_panel_factor(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $scope = $manage->register('synthetic-existing', 'SYNTHETIC-SCOPE', $f['actor']);
        $unenrolled = $this->unenrolled();
        $before = $this->evidence();

        $this->in('staging', function () use ($manage, $scope, $f, $unenrolled): void {
            $this->refused(fn () => $manage->register('synthetic-new', 'SYNTHETIC-SCOPE', $unenrolled));
            $this->refused(fn () => $manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $unenrolled));
            $this->refused(fn () => $manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $unenrolled));
        });

        $this->assertSame($before, $this->evidence());
    }

    public function test_staging_admits_an_enrolled_admin_without_an_attestation(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);

        $this->in('staging', function () use ($manage, $f): void {
            $scope = $manage->register('synthetic-staging', 'SYNTHETIC-SCOPE', $f['actor']);
            $link = $manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $f['actor']);
            $this->assertTrue($link->wasRecentlyCreated);
            $this->assertTrue($manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $f['actor'])->blocked);
        });

        $audits = DB::table('audit_events')->where('action', 'like', 'commerce.inventory.%')->orderBy('id')->get();
        $this->assertSame(['commerce.inventory.scope_registered', 'commerce.inventory.offer_linked', 'commerce.inventory.control_changed'],
            $audits->pluck('action')->all());
        $this->assertSame([$f['actor']->id, $f['actor']->id, $f['actor']->id], $audits->pluck('actor_id')->map(fn ($id) => (int) $id)->all());
        foreach ($audits as $audit) {
            $this->assertArrayNotHasKey('attestation_audit_id', json_decode($audit->context, true), 'A panel-rule write carries no attestation.');
        }
    }

    public function test_production_still_refuses_every_admin_with_inventory_unavailable(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $scope = $manage->register('synthetic-existing', 'SYNTHETIC-SCOPE', $f['actor']);
        $before = $this->evidence();

        foreach ([$f['actor'], $this->unenrolled()] as $admin) {
            $this->in('production', function () use ($manage, $scope, $f, $admin): void {
                $this->unavailable(fn () => $manage->register('synthetic-production', 'SYNTHETIC-SCOPE', $admin));
                $this->unavailable(fn () => $manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $admin));
                $this->unavailable(fn () => $manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $admin));
            });
        }

        $this->assertSame($before, $this->evidence());
    }

    public function test_non_staff_users_are_refused_in_every_environment_even_when_enrolled(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $scope = $manage->register('synthetic-existing', 'SYNTHETIC-SCOPE', $f['actor']);
        $customer = User::factory()->create();
        $customer->saveAppAuthenticationSecret(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app']->generateSecret());
        $default = (new AuthorizationException)->getMessage();
        $before = $this->evidence();

        foreach (['local', 'testing', 'staging', 'production'] as $environment) {
            $this->in($environment, function () use ($manage, $scope, $f, $customer, $default): void {
                $this->refused(fn () => $manage->register('synthetic-customer', 'SYNTHETIC-SCOPE', $customer), $default);
                $this->refused(fn () => $manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $customer), $default);
                $this->refused(fn () => $manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $customer), $default);
            });
        }

        $this->assertSame($before, $this->evidence());
    }

    public function test_a_recorded_console_attestation_admits_the_bound_write_in_staging_and_is_linked_from_its_audit(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $operator = $this->unenrolled();

        $this->in('staging', function () use ($manage, $f, $operator): void {
            $attestation = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'register', 'synthetic-attested');
            $record = AuditEvent::findOrFail($attestation->auditEventId);
            $this->assertSame(StaffWriteAuthority::ATTESTATION_ACTION, $record->action);
            $this->assertSame($operator->id, $record->actor_id);
            $this->assertSame(User::class, $record->subject_type);
            $this->assertSame($operator->id, $record->subject_id);
            $this->assertTrue($record->created_at->equalTo(now()));
            // MySQL's JSON type normalizes object-key order, so compare key-sorted copies; values stay strict.
            $expected = [
                'schema_version' => 1, 'authority' => 'trusted_interactive_console', 'command' => 'vasey:rights-scope',
                'action' => 'register', 'scope_key' => 'synthetic-attested', 'offer_revision_id' => null,
                'attested_at' => now()->utc()->toIso8601String(), 'environment' => 'staging', 'mfa_required' => true, 'mfa_enrolled' => false,
            ];
            $context = $record->context;
            ksort($expected, SORT_STRING);
            ksort($context, SORT_STRING);
            $this->assertSame($expected, $context);

            $scope = $manage->register('synthetic-attested', 'SYNTHETIC-SCOPE', $operator, $attestation);
            $registered = AuditEvent::where('action', 'commerce.inventory.scope_registered')->firstOrFail();
            $this->assertSame($operator->id, $registered->actor_id);
            $this->assertSame($attestation->auditEventId, $registered->context['attestation_audit_id']);

            $linkAttestation = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'link', 'synthetic-attested', $f['revision']->id);
            $this->assertSame($f['revision']->id, AuditEvent::findOrFail($linkAttestation->auditEventId)->context['offer_revision_id']);
            $link = $manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $operator, $linkAttestation);
            $this->assertTrue($link->wasRecentlyCreated);
            $linked = AuditEvent::where('action', 'commerce.inventory.offer_linked')->firstOrFail();
            $this->assertSame($linkAttestation->auditEventId, $linked->context['attestation_audit_id']);

            // An enrolled operator's attestation records the enrollment too; the console still has no challenge.
            $blockAttestation = StaffWriteAuthority::attest($f['actor'], 'vasey:rights-scope', 'block', 'synthetic-attested');
            $this->assertTrue(AuditEvent::findOrFail($blockAttestation->auditEventId)->context['mfa_enrolled']);
            $this->assertTrue($manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $f['actor'], $blockAttestation)->blocked);
            $this->assertSame($blockAttestation->auditEventId,
                AuditEvent::where('action', 'commerce.inventory.control_changed')->firstOrFail()->context['attestation_audit_id']);
        });

        $this->assertSame(3, AuditEvent::where('action', StaffWriteAuthority::ATTESTATION_ACTION)->count());
    }

    public function test_an_attestation_bound_elsewhere_or_without_its_record_is_refused_without_effect(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $scope = $manage->register('synthetic-existing', 'SYNTHETIC-SCOPE', $f['actor']);
        $other = $manage->register('synthetic-other', 'SYNTHETIC-SCOPE', $f['actor']);
        $operator = $this->unenrolled();
        $stranger = $this->unenrolled();
        $before = $this->evidence();

        $this->in('staging', function () use ($manage, $scope, $other, $f, $operator, $stranger): void {
            $register = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'register', 'synthetic-a');
            $this->refused(fn () => $manage->register('synthetic-b', 'SYNTHETIC-SCOPE', $operator, $register), case: 'another scope key');
            $this->refused(fn () => $manage->register('synthetic-a', 'SYNTHETIC-SCOPE', $stranger, $register), case: 'another operator');
            // Same operator, scope key and (null) revision as the block below, so only the action binding can refuse it.
            $existing = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'register', 'synthetic-existing');
            $this->refused(fn () => $manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $operator, $existing), case: 'another action');

            $link = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'link', 'synthetic-existing', $f['revision']->id);
            $this->refused(fn () => $manage->link($other->id, $f['revision']->id, 'SYNTHETIC-LINK', $operator, $link), case: 'another scope');
            $this->refused(fn () => $manage->link($scope->id, $f['revision']->id + 1000, 'SYNTHETIC-LINK', $operator, $link), case: 'another revision');

            DB::beginTransaction();
            $unrecorded = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'register', 'synthetic-rolled-back');
            DB::rollBack();
            $this->assertNull(AuditEvent::find($unrecorded->auditEventId));
            $this->refused(fn () => $manage->register('synthetic-rolled-back', 'SYNTHETIC-SCOPE', $operator, $unrecorded), case: 'no audit record');

            $stale = StaffWriteAuthority::attest($operator, 'vasey:rights-scope', 'register', 'synthetic-stale');
            $this->travel(StaffWriteAuthority::ATTESTATION_LIFETIME_SECONDS + 1)->seconds();
            $this->refused(fn () => $manage->register('synthetic-stale', 'SYNTHETIC-SCOPE', $operator, $stale), case: 'expired');
        });

        $this->assertSame(4, AuditEvent::where('action', StaffWriteAuthority::ATTESTATION_ACTION)->count(), 'Only the committed attestations remain.');
        $this->assertSame([$before[0], $before[1]], array_slice($this->evidence(), 0, 2), 'rights_scopes and rights_scope_offers unchanged');
        $this->assertSame(['synthetic-existing', 'synthetic-other'], RightsScope::orderBy('id')->pluck('scope_key')->all());
    }

    public function test_staging_refuses_a_stale_enrolled_actor_object_after_enrollment_was_withdrawn(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $scope = $manage->register('synthetic-existing', 'SYNTHETIC-SCOPE', $f['actor']);
        $stale = $f['actor'];
        User::findOrFail($stale->id)->saveAppAuthenticationSecret(null);
        $this->assertNotNull($stale->getAppAuthenticationSecret(), 'The caller still holds the enrolled object.');
        $this->assertNull(User::findOrFail($stale->id)->getAppAuthenticationSecret(), 'The persisted row is withdrawn.');
        $before = $this->evidence();

        $this->in('staging', function () use ($manage, $scope, $f, $stale): void {
            $this->refused(fn () => $manage->register('synthetic-stale', 'SYNTHETIC-SCOPE', $stale), case: 'register');
            $this->refused(fn () => $manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $stale), case: 'link');
            $this->refused(fn () => $manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $stale), case: 'block');
        });

        $this->assertSame($before, $this->evidence());
    }

    public function test_local_and_testing_keep_enrollment_optional_without_an_attestation(): void
    {
        $f = $this->selection();
        $manage = app(ManageRightsScope::class);
        $unenrolled = $this->unenrolled();

        $this->in('local', function () use ($manage, $unenrolled): void {
            $scope = $manage->register('synthetic-local', 'SYNTHETIC-SCOPE', $unenrolled);
            $this->assertTrue($manage->block($scope->id, true, 0, 'SYNTHETIC-BLOCK', $unenrolled)->blocked);
        });
        $this->in('testing', function () use ($manage, $f, $unenrolled): void {
            $scope = $manage->register('synthetic-testing', 'SYNTHETIC-SCOPE', $unenrolled);
            $this->assertTrue($manage->link($scope->id, $f['revision']->id, 'SYNTHETIC-LINK', $unenrolled)->wasRecentlyCreated);
        });

        $this->assertSame(0, AuditEvent::where('action', StaffWriteAuthority::ATTESTATION_ACTION)->count());
        $this->assertSame(['synthetic-local', 'synthetic-testing'], RightsScope::orderBy('id')->pluck('scope_key')->all());
    }
}
