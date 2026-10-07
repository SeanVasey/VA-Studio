<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Services\Projects\Attachments\ServiceProjectAttachmentAuthority;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentCommittedReadReceipt;
use App\Domain\SupportAttachments\AttachmentException;
use App\Domain\SupportAttachments\AttachmentRows;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

final class ServiceProjectCommittedReadTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function read(array $f, ?AttachmentActor $actor = null): array
    {
        $authority = new ServiceProjectAttachmentAuthority;
        $rows = new AttachmentRows;

        return DB::transaction(function () use ($f, $actor, $authority, $rows): array {
            $proof = $authority->lock($f['project']['id'], null, 'download', $actor ?? AttachmentActor::customer($f['customer']['user'], $f['customer']['principal']), $rows);
            $receipt = $authority->committedReadReceipt($proof, $rows);
            $authority->proveCurrent($proof, $rows);

            return compact('authority', 'rows', 'proof', 'receipt');
        });
    }

    private function refused(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('A committed read closure must refuse changed or uncommitted authority.');
        } catch (AttachmentException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    public function test_receipt_closes_original_read_after_commit_without_resolving_customer_services_or_renewing_expired_token(): void
    {
        $f = F::setup();
        $current = $this->read($f);
        $this->assertInstanceOf(AttachmentCommittedReadReceipt::class, $current['receipt']);
        $resolutions = 0;
        app()->afterResolving(CustomerAccess::class, function () use (&$resolutions): void {
            $resolutions++;
            throw new LogicException('No current stamp may be reminted after commit.');
        });
        $current['receipt']->proveClosed();
        $this->assertSame(0, $resolutions);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        DB::transaction(function () use ($current): void {
            $this->refused(fn () => $current['authority']->proveCurrent($current['proof'], $current['rows']), 503);
            $this->refused(fn () => $current['authority']->committedReadReceipt($current['proof'], $current['rows']), 503);
        });
        foreach ([fn () => serialize($current['receipt']), fn () => json_encode($current['receipt'])] as $encode) {
            try {
                $encode();
                $this->fail('The read receipt cannot leave the trusted server boundary.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public static function customerChanges(): array
    {
        return [['service-flag', 404], ['customer-flag', 404], ['credential', 403], ['account', 403], ['source', 409]];
    }

    #[DataProvider('customerChanges')]
    public function test_actual_transaction_committed_callback_withdrawal_refuses_private_read_after_earlier_current_proof(string $kind, int $status): void
    {
        $f = F::setup();
        $original = (array) DB::table('service_projects')->sole();
        $fired = false;
        Event::listen(TransactionCommitted::class, function ($event) use ($f, $kind, &$fired): void {
            if ($fired || $event->connection->transactionLevel() !== 0) {
                return;
            }
            $fired = true;
            match ($kind) {
                'service-flag' => config(['services-projects.test_enabled' => false]),
                'customer-flag' => config(['customer.test_accounts_enabled' => false]),
                'credential' => DB::table('users')->where('id', $f['customer']['user']->id)->update(['password' => 'POST-COMMIT-WITHDRAWN-CREDENTIAL']),
                'account' => DB::table('customer_accounts')->where('id', $f['customer']['account']->id)->update(['active' => false, 'access_version' => 2]),
                'source' => $f['journey']->customerCommand($f['project']['id'], F::command($f['project'], 'withdraw', ['reason' => 'Actual post-commit source withdrawal']), $f['customer']['principal'], $f['customer']['user']),
            };
        });
        $current = $this->read($f);
        $this->assertTrue($fired);
        $this->refused(fn () => $current['receipt']->proveClosed(), $status);
        $this->assertSame($original, (array) DB::table('service_projects')->sole());
        $this->assertDatabaseCount('service_project_events', $kind === 'source' ? 1 : 0);
    }

    public function test_rollback_and_live_transaction_cannot_be_used_as_committed_read_receipts(): void
    {
        $f = F::setup();
        $authority = new ServiceProjectAttachmentAuthority;
        $rows = new AttachmentRows;
        DB::beginTransaction();
        try {
            $proof = $authority->lock($f['project']['id'], null, 'list', AttachmentActor::customer($f['customer']['user'], $f['customer']['principal']), $rows);
            $receipt = $authority->committedReadReceipt($proof, $rows);
            $this->refused(fn () => $receipt->proveClosed(), 503);
        } finally {
            DB::rollBack();
        }
        $this->refused(fn () => $receipt->proveClosed(), 503);
        DB::transaction(fn () => DB::select('SELECT 1'));
        $this->refused(fn () => $receipt->proveClosed(), 503);
    }

    public function test_current_operator_read_closes_after_commit_without_a_new_locked_graph_or_transaction(): void
    {
        $f = F::setup();
        $current = $this->read($f, AttachmentActor::operator($f['operator']));
        $current['receipt']->proveClosed();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertDatabaseCount('service_projects', 1);
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public function test_mutation_purpose_cannot_mint_a_post_commit_read_receipt(): void
    {
        $f = F::setup();
        DB::transaction(function () use ($f): void {
            $authority = new ServiceProjectAttachmentAuthority;
            $rows = new AttachmentRows;
            $proof = $authority->lock($f['project']['id'], 0, 'intake', AttachmentActor::customer($f['customer']['user'], $f['customer']['principal']), $rows);
            $this->refused(fn () => $authority->committedReadReceipt($proof, $rows), 403);
        });
    }

    public function test_temporary_copy_cannot_mask_permanent_post_commit_credential_withdrawal(): void
    {
        $f = F::setup();
        $current = $this->read($f);
        $pdo = DB::connection()->getPdo();
        $old = $pdo->query('SELECT * FROM users WHERE id = '.(int) $f['customer']['user']->id)->fetch(PDO::FETCH_ASSOC);
        DB::table('users')->where('id', $f['customer']['user']->id)->update(['password' => 'PERMANENT-POST-COMMIT-WITHDRAWAL']);
        if (DB::getDriverName() === 'mysql') {
            $ddl = $pdo->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
            $pdo->exec(preg_replace('/\ACREATE TABLE /', 'CREATE TEMPORARY TABLE ', $ddl));
        } else {
            $pdo->exec('CREATE TEMP TABLE USERS AS SELECT * FROM main.users WHERE 0');
        }
        $columns = array_keys($old);
        $statement = $pdo->prepare('INSERT INTO users ('.implode(',', array_map(fn ($c) => '`'.$c.'`', $columns)).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
        $statement->execute(array_values($old));
        try {
            $this->refused(fn () => $current['receipt']->proveClosed(), 503);
        } finally {
            $pdo->exec(DB::getDriverName() === 'mysql' ? 'DROP TEMPORARY TABLE users' : 'DROP TABLE temp.USERS');
        }
        $this->assertSame('PERMANENT-POST-COMMIT-WITHDRAWAL', DB::table('users')->where('id', $f['customer']['user']->id)->value('password'));
        $this->assertDatabaseCount('service_project_events', 0);
    }

    public static function staffChanges(): array
    {
        return [['mfa', 403], ['credential', 403], ['service-flag', 404]];
    }

    #[DataProvider('staffChanges')]
    public function test_post_commit_actual_mfa_provider_is_checked_before_final_raw_staff_and_source_fence(string $kind, int $status): void
    {
        $f = F::setup();
        $panel = Filament::getPanel('admin');
        $oldProviders = $panel->getMultiFactorAuthenticationProviders();
        $oldRequired = $panel->isMultiFactorAuthenticationRequired();
        $phase = 'proof';
        $fired = false;
        $provider = \Mockery::mock(MultiFactorAuthenticationProvider::class);
        $provider->shouldReceive('getId')->andReturn('committed-read-probe');
        $provider->shouldReceive('isEnabled')->andReturnUsing(function () use ($f, $kind, &$phase, &$fired): bool {
            if ($phase !== 'closure') {
                return true;
            }
            $fired = true;
            if ($kind === 'mfa') {
                return false;
            }
            if ($kind === 'credential') {
                DB::table('users')->where('id', $f['operator']->id)->update(['password' => 'PROVIDER-POST-COMMIT-WITHDRAWAL']);
            } else {
                config(['services-projects.test_enabled' => false]);
            }

            return true;
        });
        $panel->multiFactorAuthentication([$provider], isRequired: true);
        try {
            $current = $this->read($f, AttachmentActor::operator($f['operator']));
            $phase = 'closure';
            $this->refused(fn () => $current['receipt']->proveClosed(), $status);
            $this->assertTrue($fired);
            $this->assertDatabaseCount('service_project_events', 0);
        } finally {
            $panel->multiFactorAuthentication($oldProviders, isRequired: $oldRequired);
        }
    }

    public function test_same_framework_connection_cannot_replace_the_captured_primary_or_prefix_after_commit(): void
    {
        $f = F::setup();
        $current = $this->read($f);
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $prefix = $connection->getTablePrefix();
        $connection->setPdo(new PDO('sqlite::memory:'));
        try {
            $this->refused(fn () => $current['receipt']->proveClosed(), 503);
        } finally {
            $connection->setPdo($primary);
        }
        $connection->setTablePrefix('foreign_');
        try {
            $this->refused(fn () => $current['receipt']->proveClosed(), 503);
        } finally {
            $connection->setTablePrefix($prefix);
        }
    }
}
