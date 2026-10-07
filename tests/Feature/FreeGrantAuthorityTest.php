<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantReads;
use App\Domain\Grants\Free\FreeGrants;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantAuthorityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function retained(): array
    {
        return array_map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson(), ['users', 'customer_accounts', 'free_definitions', 'free_reviews', 'free_availability', 'free_origins', 'free_document_work', 'free_originals', 'audit_events']);
    }

    public static function changedAuthority(): array
    {
        return [['credential'], ['account'], ['flag'], ['shadow']];
    }

    #[DataProvider('changedAuthority')]
    public function test_terminal_real_container_resolution_withdrawal_cannot_commit_assent_or_return_private_customer_graph(string $kind): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $input = ['requestKey' => (string) Str::uuid(), 'definitionHash' => $d['definitionHash'], 'reviewHash' => $d['reviewHash'],
            'expectedVersion' => $d['version'], 'declaredName' => 'PRIVATE-WITHDRAWN-ASSENT', 'affirmed' => true,
            'assentHash' => (new FreeGrants)->assentHash($d, 'PRIVATE-WITHDRAWN-ASSENT')];
        $before = $this->retained();
        $count = 0;
        app()->afterResolving(CustomerAccess::class, function () use (&$count, $kind, $f): void {
            if (++$count !== 2) {
                return;
            }
            if ($kind === 'flag') {
                config(['free-grants.test_enabled' => false]);

                return;
            }
            if ($kind === 'account') {
                DB::table('customer_accounts')->where('id', $f['customer']['account']->id)->update(['active' => false, 'access_version' => 2]);

                return;
            }
            DB::table('users')->where('id', $f['customer']['user']->id)->update(['password' => 'WITHDRAWN-PRIVATE-CREDENTIAL']);
            if ($kind === 'shadow') {
                $pdo = DB::connection()->getPdo();
                if (DB::getDriverName() === 'mysql') {
                    $definition = $pdo->query('SHOW CREATE TABLE users')->fetch(\PDO::FETCH_NUM)[1];
                    $pdo->exec(preg_replace('/\ACREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition));
                    $columns = array_keys($f['customer']['user']->getAttributes());
                    $statement = $pdo->prepare('INSERT INTO users ('.implode(',', array_map(fn ($c) => '`'.$c.'`', $columns)).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
                    $statement->execute(array_values($f['customer']['user']->getAttributes()));
                } else {
                    $pdo->exec('CREATE TEMP TABLE users AS SELECT * FROM main.users');
                }
            }
        });
        try {
            (new FreeGrants)->accept($d['id'], $input, $f['customer']['principal'], $f['customer']['user']);
            $this->fail('Late authority withdrawal must refuse.');
        } catch (FreeGrantException) {
            $this->assertSame(2, $count);
        } finally {
            if ($kind === 'shadow' && DB::getDriverName() === 'mysql') {
                DB::unprepared('DROP TEMPORARY TABLE IF EXISTS users');
            } elseif ($kind === 'shadow') {
                DB::unprepared('DROP TABLE IF EXISTS temp.users');
            }
            config(['free-grants.test_enabled' => true]);
        }
        $this->assertSame($before, $this->retained());
        $this->assertDatabaseCount('free_origins', 0);
        $this->assertDatabaseCount('free_document_work', 0);
    }

    public function test_audit_callback_closure_refuses_assent_preserving_authored_terms_prior_events_and_original_actor(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $before = $this->retained();
        $fired = false;
        AuditEvent::created(function ($event) use (&$fired, $f, $d): void {
            if ($event->action !== 'free_origin.assented') {
                return;
            }
            $fired = true;
            (new FreeGrantDefinitions)->availability($d['id'], ['requestKey' => (string) Str::uuid(), 'expectedVersion' => 1, 'open' => false, 'reason' => 'Actual terminal callback closure'], $f['author']);
        });
        try {
            FreeGrantFixtures::accept($f, $d);
            $this->fail('Late closure cannot commit stale assent.');
        } catch (FreeGrantException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertTrue($fired);
        $this->assertSame($before, $this->retained());
    }

    public function test_forged_principal_unverified_staff_buyer_and_stale_assent_have_no_free_authority(): void
    {
        $this->fakePrivateMediaStorage();
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $before = $this->retained();
        foreach ([new \stdClass, new User] as $principal) {
            try {
                (new FreeGrantReads)->customer($principal, $f['customer']['user']);
                $this->fail('Raw browser-shaped evidence is not a principal.');
            } catch (FreeGrantException $error) {
                $this->assertSame(403, $error->status);
            }
        }
        $p = $f['customer']['principal'];
        try {
            (new FreeGrantReads)->customer($p, $f['author']);
            $this->fail('Operator cannot act as a buyer.');
        } catch (FreeGrantException $error) {
            $this->assertSame(403, $error->status);
        }
        $closed = (new FreeGrantDefinitions)->availability($d['id'], ['requestKey' => (string) Str::uuid(), 'expectedVersion' => 1, 'open' => false, 'reason' => 'Exact old displayed terms stale'], $f['author']);
        try {
            FreeGrantFixtures::accept($f, $d);
            $this->fail('Stale display cannot create a grant.');
        } catch (FreeGrantException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame(2, $closed['version']);
        $this->assertDatabaseCount('free_origins', 0);
        $this->assertSame($before[3], $this->retained()[3]);
    }
}
