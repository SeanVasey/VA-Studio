<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority;
use App\Domain\Services\Projects\ServiceProjectPolicy;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentRows;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

final class ServiceProjectAttachmentAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    private function actor(array $f): AttachmentActor
    {
        return AttachmentActor::customer($f['customer']['user'], $f['customer']['principal']);
    }

    private function refused(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Current source authority must refuse this operation.');
        } catch (AttachmentException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    public function test_server_locked_proof_binds_only_current_owner_and_exact_immutable_source_graph(): void
    {
        $f = F::setup();
        $authority = new ServiceProjectAttachmentAuthority;
        $rows = new AttachmentRows;
        DB::transaction(function () use ($f, $authority, $rows): void {
            $proof = $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows);
            $binding = $proof->token->binding();
            $this->assertSame(['kind' => 'customer_account', 'id' => $f['customer']['account']->id], $binding['owner_binding']);
            $this->assertSame('test-service-project-v1', $binding['origin']);
            $this->assertSame('test_service_project_v1', $binding['family']);
            $this->assertTrue($binding['intake_open']);
            $this->assertSame(0, $proof->token->version());
            $this->assertSame($f['service']->manifest_sha256, $binding['service_hash']);
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $binding['source_graph_hash']);
            $this->assertArrayNotHasKey('owner_key', $binding);
            $this->assertArrayNotHasKey('credentialStamp', $binding);
            $authority->proveCurrent($proof, $rows);
            $staff = $authority->lock($f['project']['id'], 0, 'process', AttachmentActor::operator($f['operator']), $rows);
            $authority->proveCurrent($staff, $rows);
            $this->assertSame($proof->token->originBinding(), $staff->token->originBinding());
            $this->assertNotSame($proof->token->actorBinding(), $staff->token->actorBinding());
            foreach ([fn () => serialize($proof->token), fn () => json_encode($proof->token), fn () => serialize($proof)] as $serialize) {
                try {
                    $serialize();
                    $this->fail('Internal source proof cannot become a browser or persisted credential.');
                } catch (LogicException) {
                    $this->assertTrue(true);
                }
            }
        });
        $this->assertDatabaseCount('service_projects', 1);
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public function test_cross_customer_visitor_stale_missing_version_and_foreign_source_are_denied(): void
    {
        $f = F::setup();
        $other = CustomerFixtures::account();
        $authority = new ServiceProjectAttachmentAuthority;
        DB::transaction(function () use ($f, $other, $authority): void {
            $rows = new AttachmentRows;
            $this->refused(fn () => $authority->lock($f['project']['id'], 0, 'intake', AttachmentActor::customer($other['user'], $other['principal']), $rows), 404);
            $this->refused(fn () => $authority->lock($f['project']['id'], 0, 'intake', AttachmentActor::visitor(str_repeat('a', 64)), $rows), 403);
            $this->refused(fn () => $authority->lock($f['project']['id'], 1, 'intake', $this->actor($f), $rows), 409);
            $this->refused(fn () => $authority->lock($f['project']['id'], null, 'intake', $this->actor($f), $rows), 409);
            $this->refused(fn () => $authority->lock((string) Str::uuid(), 0, 'intake', $this->actor($f), $rows), 404);
        });
    }

    public function test_withdrawal_closes_new_intake_and_processing_but_retained_owner_access_survives(): void
    {
        $f = F::setup();
        $authority = new ServiceProjectAttachmentAuthority;
        $original = DB::transaction(fn () => $authority->lock($f['project']['id'], 0, 'list', $this->actor($f), new AttachmentRows)->token->originBinding());
        $f['journey']->customerCommand($f['project']['id'], F::command($f['project'], 'withdraw', ['reason' => 'Explicit synthetic withdrawal']), $f['customer']['principal'], $f['customer']['user']);
        DB::transaction(function () use ($f, $authority, $original): void {
            $rows = new AttachmentRows;
            foreach (['intake', 'process'] as $purpose) {
                $this->refused(fn () => $authority->lock($f['project']['id'], 1, $purpose, $this->actor($f), $rows), 409);
            }
            foreach (['list', 'download', 'delete'] as $purpose) {
                $proof = $authority->lock($f['project']['id'], null, $purpose, $this->actor($f), $rows);
                $this->assertSame(1, $proof->token->version());
                $this->assertFalse($proof->token->binding()['intake_open']);
                $this->assertSame($original, $proof->token->originBinding());
                $authority->proveCurrent($proof, $rows);
            }
        });
    }

    public function test_cancellation_review_closes_intake_and_process_without_revoking_retained_owner_binding(): void
    {
        $f = F::setup();
        $project = F::accept($f);
        $project = $f['journey']->customerCommand($project['id'], F::command($project, 'request_cancellation', ['reason' => 'Synthetic cancellation request']), $f['customer']['principal'], $f['customer']['user'])['project'];
        DB::transaction(function () use ($f, $project): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $this->refused(fn () => $authority->lock($project['id'], $project['version'], 'process', $this->actor($f), $rows), 409);
            $proof = $authority->lock($project['id'], null, 'download', $this->actor($f), $rows);
            $this->assertSame($project['version'], $proof->token->version());
            $authority->proveCurrent($proof, $rows);
        });
    }

    public function test_commit_and_rollback_expire_tokens_even_on_the_same_reopened_pdo(): void
    {
        $f = F::setup();
        $authority = new ServiceProjectAttachmentAuthority;
        $rows = new AttachmentRows;
        foreach (['commit', 'rollBack'] as $end) {
            DB::beginTransaction();
            $proof = $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows);
            DB::$end();
            DB::beginTransaction();
            try {
                $this->refused(fn () => $authority->proveCurrent($proof, $rows), 503);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_actor_withdrawal_after_terminal_framework_staff_query_cannot_mint_or_reprove_stale_source_authority(): void
    {
        $f = F::setup();
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads, $f): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, DB::connection()->getQueryGrammar()->wrapTable('users')) && ++$reads === 4) {
                DB::table('users')->where('id', $f['operator']->id)->update(['is_admin' => false]);
            }
        });
        DB::transaction(function () use ($f, &$reads): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $this->refused(fn () => $authority->lock($f['project']['id'], 0, 'intake', AttachmentActor::operator($f['operator']), new AttachmentRows), 403);
            $this->assertGreaterThanOrEqual(4, $reads);
        });
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public function test_service_withdrawal_at_final_staff_framework_query_refuses_without_changing_source_rows(): void
    {
        $f = F::setup();
        $pdo = DB::connection()->getPdo();
        $before = [];
        foreach (['users', 'customer_accounts', 'service_projects', 'service_project_events'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, DB::connection()->getQueryGrammar()->wrapTable('users')) && ++$reads === 4) {
                config(['services-projects.test_enabled' => false]);
            }
        });
        $this->refused(fn () => DB::transaction(fn () => (new ServiceProjectAttachmentAuthority)->lock(
            $f['project']['id'], 0, 'intake', AttachmentActor::operator($f['operator']), new AttachmentRows)), 404);
        $this->assertGreaterThanOrEqual(4, $reads);
        $this->assertFalse(config('services-projects.test_enabled'));
        foreach ($before as $table => $expected) {
            $this->assertSame($expected, $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    public function test_mfa_provider_service_withdrawal_after_last_framework_query_cannot_mint_proof(): void
    {
        $f = F::setup();
        $calls = 0;
        $this->withProvider(function () use (&$calls): bool {
            if (++$calls === 2) {
                config(['services-projects.test_enabled' => false]);
            }

            return true;
        }, function () use ($f): void {
            $this->refused(fn () => DB::transaction(fn () => (new ServiceProjectAttachmentAuthority)->lock(
                $f['project']['id'], 0, 'intake', AttachmentActor::operator($f['operator']), new AttachmentRows)), 404);
        });
        $this->assertSame(2, $calls);
        $this->assertFalse(config('services-projects.test_enabled'));
        $this->assertDatabaseCount('service_projects', 1);
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public function test_mfa_provider_actor_withdrawal_cannot_follow_the_terminal_raw_user_snapshot(): void
    {
        $f = F::setup();
        $calls = 0;
        $this->withProvider(function () use (&$calls, $f): bool {
            if (++$calls === 2) {
                DB::table('users')->where('id', $f['operator']->id)->update(['is_admin' => false]);
            }

            return true;
        }, function () use ($f): void {
            $this->refused(fn () => DB::transaction(fn () => (new ServiceProjectAttachmentAuthority)->lock(
                $f['project']['id'], 0, 'intake', AttachmentActor::operator($f['operator']), new AttachmentRows)), 403);
        });
        $this->assertSame(2, $calls);
        // The refused consumer transaction preserves the actor and retained graph.
        $this->assertTrue($f['operator']->fresh()->is_admin);
        $this->assertDatabaseCount('service_projects', 1);
        $this->assertDatabaseCount('service_project_events', 0);
    }

    private function withProvider(callable $enabled, callable $test): void
    {
        $panel = Filament::getPanel('admin');
        $providers = $panel->getMultiFactorAuthenticationProviders();
        $required = $panel->isMultiFactorAuthenticationRequired();
        $provider = \Mockery::mock(MultiFactorAuthenticationProvider::class);
        $provider->shouldReceive('getId')->andReturn('terminal-probe');
        $provider->shouldReceive('isEnabled')->andReturnUsing($enabled);
        $panel->multiFactorAuthentication([$provider], isRequired: true);
        try {
            $test();
        } finally {
            $panel->multiFactorAuthentication($providers, isRequired: $required);
        }
    }

    public function test_current_account_withdrawal_and_connection_replacement_are_refused_at_terminal_proof(): void
    {
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows);
            DB::table('customer_accounts')->where('id', $f['customer']['account']->id)->update(['active' => false, 'access_version' => 2]);
            $this->refused(fn () => $authority->proveCurrent($proof, $rows), 403);
        });
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows);
            $old = DB::connection()->getPdo();
            DB::connection()->setPdo(new PDO('sqlite::memory:'));
            try {
                $this->refused(fn () => $authority->proveCurrent($proof, $rows), 503);
            } finally {
                // setPdo resets Laravel's transaction counter. Explicitly release this
                // synthetic displaced descriptor so disposable fixture cleanup is honest.
                DB::connection()->setPdo($old);
                if ($old->inTransaction()) {
                    $old->rollBack();
                }
            }
        });
    }

    public function test_temporary_source_shadow_cannot_replace_persistent_source_evidence(): void
    {
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            DB::unprepared(DB::getDriverName() === 'mysql'
                ? 'CREATE TEMPORARY TABLE service_projects (id bigint unsigned primary key)'
                : 'CREATE TEMP TABLE service_projects (id integer primary key)');
            try {
                $this->refused(fn () => $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows), 503);
            } finally {
                DB::unprepared(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.service_projects' : 'DROP TEMPORARY TABLE service_projects');
            }
        });
        $this->assertDatabaseCount('service_projects', 1);
    }

    public function test_source_and_customer_admission_flags_are_revalidated_before_proof_is_returned(): void
    {
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows);
            config(['services-projects.test_enabled' => false]);
            $this->refused(fn () => $authority->proveCurrent($proof, $rows), 404);
            config(['services-projects.test_enabled' => true, 'customer.test_accounts_enabled' => false]);
            $this->refused(fn () => $authority->proveCurrent($proof, $rows), 403);
            config(['customer.test_accounts_enabled' => true]);
            $this->refused(fn () => $authority->lock(strtoupper($f['project']['id']), 0, 'intake', $this->actor($f), $rows), 404);
        });
    }

    public function test_final_customer_stamp_resolution_withdrawal_is_seen_before_raw_actor_capture_and_preserves_all_original_rows(): void
    {
        $f = F::setup();
        $pdo = DB::connection()->getPdo();
        $before = [];
        foreach (['users', 'customer_accounts', 'service_projects', 'service_project_events'] as $table) {
            $before[$table] = $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }
        $resolutions = 0;
        app()->afterResolving(CustomerAccess::class, function () use (&$resolutions, $f): void {
            if (++$resolutions === 3) {
                $f['customer']['user']->fresh()->forceFill(['password' => 'Synthetic late stamp credential only!'])->save();
            }
        });
        $this->refused(fn () => DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], null, 'list', $this->actor($f), $rows);
            $authority->proveCurrent($proof, $rows);
        }), 403);
        $this->assertSame(3, $resolutions);
        foreach ($before as $table => $expected) {
            $this->assertSame($expected, $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    public static function admissionResolvers(): array
    {
        return [[ServiceProjectPolicy::class], [CustomerAccessPolicy::class]];
    }

    #[DataProvider('admissionResolvers')]
    public function test_terminal_policy_resolution_cannot_withdraw_account_after_raw_authority_fence(string $policy): void
    {
        $f = F::setup();
        $pdo = DB::connection()->getPdo();
        $before = $pdo->query('SELECT * FROM customer_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $resolutions = 0;
        app()->afterResolving($policy, function () use (&$resolutions, $f): void {
            if (++$resolutions === 3) {
                DB::table('customer_accounts')->where('id', $f['customer']['account']->id)->update(['active' => false, 'access_version' => 2]);
            }
        });
        $this->refused(fn () => DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], null, 'list', $this->actor($f), $rows);
            $authority->proveCurrent($proof, $rows);
        }), 403);
        $this->assertSame(3, $resolutions);
        $this->assertSame($before, $pdo->query('SELECT * FROM customer_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertDatabaseCount('service_projects', 1);
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public function test_same_list_proof_authorizes_exact_open_mutation_without_new_tokens_and_refuses_closed_or_wrong_purpose(): void
    {
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], null, 'list', $this->actor($f), $rows);
            $authority->authorizeMutation($proof, 0, 'intake', $rows);
            $authority->authorizeMutation($proof, 0, 'process', $rows);
            $authority->proveCurrent($proof, $rows);
            $this->assertSame(0, $proof->token->version());
            $this->refused(fn () => $authority->authorizeMutation($proof, 1, 'intake', $rows), 409);
            $this->refused(fn () => $authority->authorizeMutation($proof, 0, 'download', $rows), 403);
            $download = $authority->lock($f['project']['id'], null, 'download', $this->actor($f), $rows);
            $this->refused(fn () => $authority->authorizeMutation($download, 0, 'intake', $rows), 403);
        });
        $f['journey']->customerCommand($f['project']['id'], F::command($f['project'], 'withdraw', ['reason' => 'Synthetic withdrawal closes intake']), $f['customer']['principal'], $f['customer']['user']);
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], null, 'list', $this->actor($f), $rows);
            $this->refused(fn () => $authority->authorizeMutation($proof, 1, 'intake', $rows), 409);
            $this->refused(fn () => $authority->authorizeMutation($proof, 1, 'process', $rows), 409);
            $authority->proveCurrent($proof, $rows);
        });
    }

    public function test_direct_pdo_commit_and_reopen_cannot_resurrect_old_source_proof(): void
    {
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], 0, 'intake', $this->actor($f), $rows);
            // Bypass Laravel events deliberately while its level counter stays1.
            $rows->identity()->commit();
            $rows->identity()->beginTransaction();
            $this->refused(fn () => $authority->proveCurrent($proof, $rows), 503);
        });
    }
}
