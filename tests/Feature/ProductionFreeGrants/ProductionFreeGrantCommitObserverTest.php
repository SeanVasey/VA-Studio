<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRows;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use ArrayObject;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use ReflectionProperty;
use Stringable;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Owner doc step 3: every Free256 frame is committed only after the ordinary committing listeners have run and the
 * command's terminal proof (owner, policy, graph) plus the permanent parent floor have been re-proved on the same
 * primary. A withdrawal inside a committing listener rolls the whole command back; reopen, nesting and dispatcher drift
 * refuse; the original dispatcher and transaction manager are always restored.
 */
final class ProductionFreeGrantCommitObserverTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_ordinary_committing_listeners_run_once_before_the_proof_and_the_frame_is_restored(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $events = DB::connection()->getEventDispatcher();
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue(DB::connection());
        $seen = [];
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$seen): void {
            // A listener still sees the frame it is committing, with the origin written.
            $seen[] = [DB::transactionLevel(), DB::table('production_free_origins')->count()];
        });
        $origin = (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        $this->assertSame('pending', $origin['documentStatus']);
        $this->assertSame([[1, 1]], $seen);
        $this->assertSame($events, DB::connection()->getEventDispatcher());
        $this->assertSame($manager, (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue(DB::connection()));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_revocation_inside_a_committing_listener_rolls_back_the_authorization(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $calls = $this->duringCommit('production_free_authorizations', function () use ($origin): void {
            $this->insertRevocation($origin['id']);
        });
        $this->refuses(fn () => (new ProductionFreeGrantDownloads)->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']), 'revoked');
        $this->assertSame(1, $calls());
        // The command and the listener's write roll back together; nothing partial is committed.
        $this->assertSame(0, DB::table('production_free_authorizations')->count());
        $this->assertSame(0, DB::table('production_free_revocations')->count());
        $this->assertIdle();
    }

    public function test_policy_withdrawal_inside_a_committing_listener_rolls_back_the_origin(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $calls = $this->duringCommit('production_free_origins', fn () => config(['production-free-grants.enabled' => false]));
        $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'changed_policy');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertIdle();
    }

    public function test_owner_withdrawal_inside_a_committing_listener_rolls_back_the_origin_and_the_withdrawal(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $calls = $this->duringCommit('production_free_origins', fn () => DB::table('customer_accounts')
            ->where('id', $owner['principal']->accountId)->update(['active' => false, 'access_version' => 2]));
        $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'identity_refused');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertSame(1, (int) DB::table('customer_accounts')->where('id', $owner['principal']->accountId)->value('active'));
    }

    public function test_session_marker_withdrawal_inside_a_committing_listener_rolls_back_through_the_http_capsule(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $request = $this->request($owner);
        $identity = ProductionFreeGrantRequestIdentity::from($request);
        $calls = $this->duringCommit('production_free_origins', fn () => $request->session()->forget('_production_customer_identity'));
        $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $identity->principal, $identity->actor, $identity->authority()), 'identity_refused');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertIdle();
    }

    public function test_staff_policy_withdrawal_inside_a_committing_listener_rolls_back_the_definition(): void
    {
        $author = $this->staff();
        $calls = $this->duringCommit('production_free_definitions', fn () => config(['production-free-grants.approved_terms_hashes' => []]));
        $this->refuses(fn () => (new ProductionFreeGrantDefinitions)->propose($this->definitionInput(), $author), 'changed_policy');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_definitions')->count());
        $this->assertIdle();
    }

    public function test_a_raw_reopen_by_a_committing_listener_is_refused_and_the_replacement_is_left_to_its_owner(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $pdo = DB::connection()->getPdo();
        $calls = $this->duringCommit('production_free_origins', function () use ($pdo): void {
            $pdo->rollBack();
            $pdo->beginTransaction();
        });
        try {
            $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'changed_transaction');
            $this->assertSame(1, $calls());
            // The callback's replacement transaction is not rolled back by the observer; the framework level is reset.
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        $this->assertSame(0, DB::table('production_free_origins')->count());
    }

    public function test_a_nested_frame_inside_the_owned_frame_is_refused(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $calls = $this->duringCommit('production_free_origins', fn () => DB::transaction(fn () => null));
        $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'changed_transaction');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertIdle();
    }

    public function test_a_command_started_inside_another_free_frame_is_refused(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $inner = null;
        $calls = $this->duringCommit('production_free_origins', function () use ($owner, &$inner): void {
            try {
                (new ProductionFreeGrantLibrary)->index($owner['principal'], $owner['user']);
            } catch (ProductionFreeGrantException $error) {
                $inner = $error->reason;

                throw $error;
            }
        });
        $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'transaction_forbidden');
        $this->assertSame('transaction_forbidden', $inner);
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertIdle();
    }

    public function test_a_replaced_dispatcher_refuses_the_commit_and_the_original_is_restored(): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $events = DB::connection()->getEventDispatcher();
        $calls = $this->duringCommit('production_free_origins', fn () => DB::connection()->setEventDispatcher(new Dispatcher($this->app)));
        $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'changed_transaction');
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertSame($events, DB::connection()->getEventDispatcher());
        $this->assertIdle();
    }

    /** @return array<string, array{0:string}> */
    public static function metadata(): array
    {
        return ['facade cache' => ['facade'], 'primary configuration' => ['configuration'], 'database name' => ['database'], 'table prefix' => ['prefix']];
    }

    /**
     * The shared generic metadata P2 on the surface this frame reaches: a late committing listener replaces the facade
     * cache, the primary's raw config or its database/prefix with a callback-capable value. The observer refuses before
     * any offset or string conversion, and the frame rolls back.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('metadata')]
    public function test_callback_capable_metadata_planted_at_commit_is_refused_without_its_callback(string $target): void
    {
        [$owner, $definition, $review] = $this->reviewed();
        $connection = DB::connection();
        $facade = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $property = match ($target) {
            'configuration' => new ReflectionProperty(Connection::class, 'config'),
            'database' => new ReflectionProperty(Connection::class, 'database'),
            'prefix' => new ReflectionProperty(Connection::class, 'tablePrefix'),
            default => null,
        };
        $original = $target === 'facade' ? $facade->getValue() : $property->getValue($connection);
        $sentinel = new FreeMetadataSentinel(is_array($original) ? $original : [], is_string($original) ? $original : '', $connection->getPdo());
        $calls = $this->duringCommit('production_free_origins', function () use ($target, $facade, $property, $connection, $sentinel): void {
            if ($target === 'facade') {
                $facade->setValue(null, $sentinel);
            } else {
                $property->setValue($connection, $sentinel);
            }
        });
        try {
            $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'changed_connection');
        } finally {
            if ($target === 'facade') {
                $facade->setValue(null, $original);
            } else {
                $property->setValue($connection, $original);
            }
        }
        $this->assertSame(1, $calls());
        // Counted while the owned frame is still open: the final proof. Framework cleanup after the refusal (the
        // rollback names its connection) is outside it and restores nothing on its own; the fixture restores the value.
        $this->assertSame(0, $sentinel->calls, 'No offset or conversion callback ran inside the final proof.');
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertIdle();
    }

    public function test_native_temporary_parent_shadow_planted_at_commit_rolls_the_frame_back(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native session TEMPORARY table shadow of a permanent parent (F-4); SQLite shadows are covered by the schema tests.');
        }
        [$owner, $definition, $review] = $this->reviewed();
        $pdo = DB::connection()->getPdo();
        $schema = '`'.str_replace('`', '``', DB::connection()->getDatabaseName()).'`';
        // MySQL refuses CREATE TEMPORARY TABLE ... LIKE the same name (1066); CREATE ... SELECT reads the durable table.
        $calls = $this->duringCommit('production_free_origins', fn () => $pdo->exec('CREATE TEMPORARY TABLE users AS SELECT * FROM '.$schema.'.users'));
        try {
            $this->refuses(fn () => (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'identity_refused', 'parent_floor');
        } finally {
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users');
        }
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $this->assertIdle();
    }

    public function test_native_temporary_owned_table_shadow_planted_at_commit_rolls_the_frame_back(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native session TEMPORARY table shadow of an owned table read by the graph proof; SQLite shadows are covered by the schema tests.');
        }
        [$owner, $origin, $seal] = $this->delivered();
        $pdo = DB::connection()->getPdo();
        $schema = '`'.str_replace('`', '``', DB::connection()->getDatabaseName()).'`';
        // An empty session shadow of the revocations table would hide a durable revocation from the graph read itself;
        // the parent floor and owned-schema proof refuses it immediately before the commit.
        $calls = $this->duringCommit('production_free_authorizations', fn () => $pdo->exec('CREATE TEMPORARY TABLE production_free_revocations AS SELECT * FROM '.$schema.'.production_free_revocations WHERE 1 = 0'));
        try {
            $this->refuses(fn () => (new ProductionFreeGrantDownloads)->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']), 'shadowed_schema');
        } finally {
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS production_free_revocations');
        }
        $this->assertSame(1, $calls());
        $this->assertSame(0, DB::table('production_free_authorizations')->count());
        $this->assertIdle();
    }

    /** Runs `$withdraw` once, from an ordinary committing listener, during the first commit that has a row in `$table`. */
    private function duringCommit(string $table, \Closure $withdraw): \Closure
    {
        $calls = 0;
        Event::listen(TransactionCommitting::class, function () use (&$calls, $table, $withdraw): void {
            if ($calls === 0 && DB::table($table)->count() > 0) {
                $calls++;
                $withdraw();
            }
        });

        return function () use (&$calls): int {
            return $calls;
        };
    }

    /** A sealed revocation row written inside the current frame, exactly as staff revocation writes it. */
    private function insertRevocation(string $originId): void
    {
        $rows = new ProductionFreeGrantRows;
        $origin = $rows->one('production_free_origins', 'id = ?', [$originId]);
        $at = ProductionFreeGrantInput::now();
        $id = (string) Str::uuid();
        $actor = $this->staff();
        $rows->insert('production_free_revocations', ['id' => $id, 'origin_id' => $originId, 'actor_user_id' => (int) $actor->getKey(),
            'reason_hash' => hash('sha256', 'Synthetic concurrent revocation'), 'created_at' => ProductionFreeGrantInput::stored($at)],
            ['schema_version' => 'production-free-revocation-v1', 'revocation_id' => $id, 'origin_id' => $originId,
                'origin_seal' => $origin['seal'], 'reason' => 'Synthetic concurrent revocation', 'actor_user_id' => (int) $actor->getKey(),
                'at' => ProductionFreeGrantInput::iso($at)]);
    }

    private function reviewed(): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $review = (new ProductionFreeGrants)->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return [$owner, $definition, $review];
    }

    private function delivered(): array
    {
        [$owner, $definition, $review] = $this->reviewed();
        $origin = (new ProductionFreeGrants)->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);

        return [$owner, $origin, (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal']];
    }

    private function request(array $owner): Request
    {
        Auth::guard('customer')->setUser($owner['user']);
        $request = Request::create('/production-free-grants/library');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $owner['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard ?? 'web')->user());

        return $request;
    }

    private function assertIdle(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function refuses(callable $operation, string ...$reasons): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.implode(' or ', $reasons));
        } catch (ProductionFreeGrantException $error) {
            $this->assertContains($error->reason, $reasons);
        }
    }
}

/** A callback-capable stand-in for raw metadata: counts every offset or string conversion made while the frame is open. */
final class FreeMetadataSentinel extends ArrayObject implements Stringable
{
    public int $calls = 0;

    public function __construct(array $values, private readonly string $text, private readonly \PDO $primary)
    {
        parent::__construct($values);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->record();

        return parent::offsetGet($key);
    }

    public function offsetExists(mixed $key): bool
    {
        $this->record();

        return parent::offsetExists($key);
    }

    public function __toString(): string
    {
        $this->record();

        return $this->text;
    }

    private function record(): void
    {
        if ($this->primary->inTransaction()) {
            $this->calls++;
        }
    }
}
