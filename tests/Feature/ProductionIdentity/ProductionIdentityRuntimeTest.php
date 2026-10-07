<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

class ProductionIdentityRuntimeTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public function test_actual_smtp_identity_never_authenticates_a_temporary_old_password_copy(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE identity_old_users AS SELECT * FROM users');
        $statement = $pdo->prepare('UPDATE users SET password=? WHERE id=?');
        $statement->execute([Hash::make('RecoveredPassword456'), $identity['user']->id]);
        $before = $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TEMPORARY TABLE users AS SELECT * FROM identity_old_users');
        try {
            $this->assertNull((new ProductionCustomerSessions)->authenticate('buyer@example.test', 'MailboxPassword123'));
        } finally {
            $pdo->exec('DROP TABLE users');
            $pdo->exec('DROP TABLE identity_old_users');
        }
        $this->assertSame($before, $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    #[DataProvider('identityShadows')]
    public function test_current_and_original_historical_authority_refuse_every_owned_temporary_source_shadow(string $table): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $pdo = DB::connection()->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $pdo->exec('CREATE TEMPORARY TABLE '.$table.' AS SELECT * FROM '.$table);
        try {
            try {
                $access->current($identity['principal'], $identity['user']);
                $this->fail();
            } catch (IdentityException) {
            }
            DB::beginTransaction();
            try {
                $access->lock($identity['principal'], $identity['user'], $reader);
                $this->fail();
            } catch (IdentityException) {
            }
            try {
                $access->verifyHistoricalBinding($identity['binding'], $reader);
                $this->fail();
            } catch (IdentityException) {
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            } $pdo->exec('DROP TABLE '.$table);
        }
        $access->current($identity['principal'], $identity['user']);
        $this->assertSame(1, DB::table('production_identity_origins')->count());
    }

    public static function identityShadows(): array
    {
        return [['users'], ['customer_accounts'], ['production_identity_origins'], ['production_identity_verifications'], ['production_identity_challenges']];
    }

    public function test_standalone_mint_closes_temporary_shadow_introduced_by_its_commit_callback(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $pdo = DB::connection()->getPdo();
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use ($pdo, &$fired): void {
            if (! $fired) {
                $fired = true;
                $pdo->exec('CREATE TEMPORARY TABLE users AS SELECT * FROM users');
            }
        });
        try {
            (new ProductionCustomerAccess)->principal($identity['user']);
            $this->fail();
        } catch (IdentityException) {
            $this->assertTrue($fired);
        } finally {
            Event::forget(TransactionCommitted::class);
            if ($fired) {
                $pdo->exec('DROP TABLE users');
            }
        }
    }

    public function test_owned_raw_writer_refuses_a_temporary_audit_destination_before_inserting_any_user(): void
    {
        $challenge = $this->requestIdentity();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TEMPORARY TABLE audit_events AS SELECT * FROM audit_events');
        try {
            $this->completeIdentity($challenge);
            $this->fail();
        } catch (IdentityException) {
        } finally {
            $pdo->exec('DROP TABLE audit_events');
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('production_identity_verifications')->count());
    }

    public function test_uuid_callback_cannot_verify_a_later_credential_instead_of_the_requested_password(): void
    {
        $challenge = $this->requestIdentity();
        Str::createUuidsUsing(function () {
            DB::table('users')->update(['password' => 'callback-withdrawn']);

            return Uuid::uuid4();
        });
        try {
            $this->completeIdentity($challenge);
            $this->fail();
        } catch (IdentityException) {
        } finally {
            Str::createUuidsNormally();
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('production_identity_verifications')->count());
    }

    public function test_caller_owned_foreign_pdo_reader_is_never_used_for_current_or_historical_identity(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $foreign = new CurrentRows(new PDO('sqlite::memory:'), 'sqlite');
        DB::beginTransaction();
        try {
            try {
                $access->lock($identity['principal'], $identity['user'], $foreign);
                $this->fail();
            } catch (IdentityException) {
            }
            try {
                $access->verifyHistoricalBinding($identity['binding'], $foreign);
                $this->fail();
            } catch (IdentityException) {
            }
        } finally {
            DB::rollBack();
        }
        $access->current($identity['principal'], $identity['user']);
        $this->assertSame(1, DB::table('production_identity_origins')->count());
    }
}
