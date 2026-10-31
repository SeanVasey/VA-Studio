<?php

namespace Tests\Feature\ProductionMembershipHttp;

use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/**
 * The member mount over the real C4d domain with the synthetic rehearsal fixtures and test-bound capabilities (as
 * MemberActivationFixtures binds them; production registers none): the journey, one not-found for every refusal
 * (review F8), writer outcomes that are never "nothing happened" (F7) and the index pre-bytes proof (C2).
 */
final class MembershipHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    private const REQUEST_KEY = 'http-synthetic-reservation-0001';

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = 'testing';
            $app['config']->set('production-memberships.http_mounted', true);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->memberActivationSetup();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_journey_index_reserve_replay_and_release_through_the_mounted_routes(): void
    {
        $fixture = $this->memberActivationFixture();
        $period = $fixture['reservation']['period_id'];
        $this->actAs($fixture['buyer']);
        $preBytes = $this->countInside(TransactionBeginning::class, 'proveCapturedBeforeBytes');
        $index = $this->privateJson($this->getMember('/production-memberships/periods'))->assertOk()->json('index');
        $this->assertSame(1, $preBytes(), 'The index body was released after exactly one pre-bytes re-read.');
        $this->assertSame((new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']), $index);
        $this->assertCount(1, $index['periods'][0]['reservations']);

        $before = DB::table(MembershipReservationRecords::TABLE)->count();
        // A body of the wrong shape is refused before the domain is called.
        $this->privateJson($this->postMember('/production-memberships/periods/'.$period.'/reservations', ['offerId' => (string) $fixture['selection'],
            'expectedRevision' => (string) $fixture['expected_revision'], 'requestKey' => self::REQUEST_KEY]))->assertStatus(422)->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE']);
        $this->privateJson($this->postMember('/production-memberships/periods/'.$period.'/expire', ['force' => true]))->assertStatus(422);
        $this->assertSame($before, DB::table(MembershipReservationRecords::TABLE)->count());
        $result = $this->privateJson($this->reserve($fixture, $period))->assertOk()->json('result');
        $this->assertSame(['periodId', 'status', 'balances', 'redemptionId', 'creditAmount', 'honorDeadline'], array_keys($result));
        $this->assertSame([$period, 'reserved', 1], [$result['periodId'], $result['status'], $result['creditAmount']]);
        $this->assertSame(['available' => 1, 'reserved' => 2, 'consumed' => 0, 'expired' => 0], $result['balances']);
        // Replay of the same request key returns the same retained reservation and writes nothing more.
        $this->assertSame($result, $this->reserve($fixture, $period)->assertOk()->json('result'));
        $this->assertSame($before + 1, DB::table(MembershipReservationRecords::TABLE)->count());

        // Before its honor deadline a release is refused, and that refusal is the one not-found response.
        $this->notFound($this->postMember('/production-memberships/periods/'.$period.'/reservations/'.$result['redemptionId'].'/release', []));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($result['honorDeadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $released = $this->privateJson($this->postMember('/production-memberships/periods/'.$period.'/reservations/'.$result['redemptionId'].'/release', []))
            ->assertOk()->json('result');
        $this->assertSame(['periodId' => $period, 'status' => 'released', 'balances' => ['available' => 2, 'reserved' => 1, 'consumed' => 0, 'expired' => 0],
            'redemptionId' => $result['redemptionId']], $released);
        $statuses = array_column($this->getMember('/production-memberships/periods')->assertOk()->json('index.periods.0.reservations'), 'status', 'id');
        $this->assertSame('released', $statuses[$result['redemptionId']]);
        // No billing-ledger or provider identifier, hash or seal reaches the customer.
        $encoded = json_encode([$index, $result, $released], JSON_THROW_ON_ERROR);
        foreach ([$fixture['invoice']['id'], $fixture['provider_invoice_ref']] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        // A runtime withdrawal of the mount refuses the registered routes before any query, with every gate open.
        config(['production-memberships.http_mounted' => false]);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        // The domain reads with raw PDO, which query events do not see; its transactions are visible.
        $transactions = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, static function () use (&$transactions): void {
            $transactions++;
        });
        $this->notFound($this->getMember('/production-memberships/periods'));
        $this->notFound($this->reserve($fixture, $period));
        $this->assertSame([], $queries);
        $this->assertSame(0, $transactions);
    }

    public function test_reserve_accepts_its_three_keys_in_any_order_and_refuses_a_missing_or_extra_key(): void
    {
        // Codex P2 on PR #174: JSON object members are unordered, so a valid body in another key order must reach the
        // domain; the key set itself stays exact and every value check is unchanged.
        $fixture = $this->memberActivationFixture();
        $period = $fixture['reservation']['period_id'];
        $this->actAs($fixture['buyer']);
        $path = '/production-memberships/periods/'.$period.'/reservations';
        $valid = ['offerId' => (string) $fixture['selection'], 'expectedRevision' => $fixture['expected_revision'], 'requestKey' => self::REQUEST_KEY];
        $before = DB::table(MembershipReservationRecords::TABLE)->count();
        $refused = [
            'missing key' => ['offerId' => $valid['offerId'], 'requestKey' => $valid['requestKey']],
            'extra key' => [...$valid, 'force' => true],
            'extra key, reordered' => ['force' => true, 'requestKey' => $valid['requestKey'], 'offerId' => $valid['offerId'], 'expectedRevision' => $valid['expectedRevision']],
            'replaced key' => ['offerId' => $valid['offerId'], 'expectedRevisions' => $valid['expectedRevision'], 'requestKey' => $valid['requestKey']],
            'reordered with a wrong value type' => ['requestKey' => $valid['requestKey'], 'expectedRevision' => (string) $valid['expectedRevision'], 'offerId' => $valid['offerId']],
        ];
        foreach ($refused as $case => $body) {
            $this->privateJson($this->postMember($path, $body))->assertStatus(422)->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE']);
            $this->assertSame($before, DB::table(MembershipReservationRecords::TABLE)->count(), $case);
        }
        $reordered = ['requestKey' => $valid['requestKey'], 'expectedRevision' => $valid['expectedRevision'], 'offerId' => $valid['offerId']];
        $this->assertNotSame(array_keys($valid), array_keys($reordered));
        $result = $this->privateJson($this->postMember($path, $reordered))->assertOk()->json('result');
        $this->assertSame([$period, 'reserved', 1], [$result['periodId'], $result['status'], $result['creditAmount']]);
        $this->assertSame($before + 1, DB::table(MembershipReservationRecords::TABLE)->count());
        // The same request in the original key order is the same retained reservation (replay), so order carries no meaning.
        $this->assertSame($result, $this->reserve($fixture, $period)->assertOk()->json('result'));
        $this->assertSame($before + 1, DB::table(MembershipReservationRecords::TABLE)->count());
    }

    public function test_every_refusal_is_the_one_not_found_response(): void
    {
        $fixture = $this->memberActivationFixture();
        $period = $fixture['reservation']['period_id'];
        $redemption = $fixture['reservation']['redemption_id'];
        $before = DB::table(MembershipReservationRecords::TABLE)->count();
        $other = $this->enrollThroughLocalSmtp('member-other@example.test');
        // Another customer: the buyer's period and redemption do not exist for them, and their own index is empty.
        $this->actAs($other);
        $this->notFound($this->reserve($fixture, $period));
        $this->notFound($this->postMember('/production-memberships/periods/'.$period.'/reservations/'.$redemption.'/release', []));
        $this->notFound($this->postMember('/production-memberships/periods/'.$period.'/expire', []));
        $this->assertSame([], $this->getMember('/production-memberships/periods')->assertOk()->json('index.periods'));
        // The owner: an unknown period, a non-v4 id, an unknown redemption and a not-yet-expired credit.
        $this->actAs($fixture['buyer']);
        $this->notFound($this->reserve($fixture, (string) Str::uuid()));
        $this->notFound($this->postMember('/production-memberships/periods/11111111-1111-1111-8111-111111111111/expire', []));
        $this->notFound($this->postMember('/production-memberships/periods/'.$period.'/reservations/'.Str::uuid().'/release', []));
        $this->notFound($this->postMember('/production-memberships/periods/'.$period.'/expire', []));
        // A forged session marker for the right actor is a capsule refusal.
        $this->withSession(['_production_customer_identity' => ['binding_digest' => str_repeat('a', 64)]]);
        $this->privateJson($this->getMember('/production-memberships/periods'))->assertForbidden()->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE']);
        $this->actAs($fixture['buyer']);
        // A withdrawn owner is refused by the request capsule before any period is looked up: the same fixed
        // forbidden response for every path, so it says nothing about the period either.
        DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->update(['active' => false, 'access_version' => 2]);
        $withdrawn = $this->privateJson($this->reserve($fixture, $period))->assertForbidden()->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE']);
        $this->assertSame($withdrawn->getContent(), $this->reserve($fixture, (string) Str::uuid())->assertForbidden()->getContent());
        $this->assertSame($before, DB::table(MembershipReservationRecords::TABLE)->count());
        // No signed-in customer at all is a capsule refusal before any domain call.
        Auth::guard('customer')->logout();
        $this->flushSession();
        $this->privateJson($this->getMember('/production-memberships/periods'))->assertForbidden()->assertExactJson(['code' => 'MEMBERSHIP_UNAVAILABLE']);
    }

    public function test_a_generic_writer_refusal_is_outcome_unknown_and_the_same_request_then_converges(): void
    {
        $fixture = $this->memberActivationFixture();
        $period = $fixture['reservation']['period_id'];
        $this->actAs($fixture['buyer']);
        $before = DB::table(MembershipReservationRecords::TABLE)->count();
        $thrown = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$thrown): void {
            if ($thrown === 0 && self::inside(MembershipReservationOperations::class, 'runOwned')) {
                $thrown++;

                throw new RuntimeException('synthetic committing failure: the writer cannot classify this outcome');
            }
        });
        $response = $this->privateJson($this->reserve($fixture, $period))->assertStatus(503)->assertHeader('Retry-After', '1')
            ->assertExactJson(['code' => 'MEMBERSHIP_OUTCOME_UNKNOWN', 'refresh' => true, 'retry' => 'same_request']);
        $this->assertNotSame(MembershipHttpMountTest::NOT_FOUND, $response->getContent());
        $this->assertSame(1, $thrown);
        $this->assertSame($before, DB::table(MembershipReservationRecords::TABLE)->count());
        // Repeating the exact request is safe: it converges on one reservation.
        $first = $this->reserve($fixture, $period)->assertOk()->json('result');
        $this->assertSame($first, $this->reserve($fixture, $period)->assertOk()->json('result'));
        $this->assertSame($before + 1, DB::table(MembershipReservationRecords::TABLE)->count());
    }

    public function test_a_committed_write_with_a_refused_receipt_is_submitted_never_a_refusal(): void
    {
        $fixture = $this->memberActivationFixture();
        $period = $fixture['reservation']['period_id'];
        $this->actAs($fixture['buyer']);
        $before = DB::table(MembershipReservationRecords::TABLE)->count();
        $armed = true;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$armed): void {
            if ($armed && self::inside(MembershipReservationOperations::class, 'runOwned')) {
                $armed = false;
                DB::afterCommit(static function (): void {
                    throw new RuntimeException('synthetic afterCommit failure after the witnessed commit');
                });
            }
        });
        $this->submitted($this->reserve($fixture, $period));
        $this->assertSame($before + 1, DB::table(MembershipReservationRecords::TABLE)->count());
        // The durable effect is visible on refresh, and the same request replays it.
        $this->assertCount(2, $this->getMember('/production-memberships/periods')->assertOk()->json('index.periods.0.reservations'));
        $this->assertSame('reserved', $this->reserve($fixture, $period)->assertOk()->json('result.status'));
        $this->assertSame($before + 1, DB::table(MembershipReservationRecords::TABLE)->count());

        // A capsule withdrawal after the writer committed (here the session marker) hides only the receipt.
        $withdrawn = false;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use (&$withdrawn): void {
            if (! $withdrawn && self::inside(MembershipReservationOperations::class, 'runOwned')) {
                $withdrawn = true;
                $this->app['session.store']->forget('_production_customer_identity');
            }
        });
        $this->submitted($this->postMember('/production-memberships/periods/'.$period.'/reservations', [
            'offerId' => (string) $fixture['selection'], 'expectedRevision' => $fixture['expected_revision'], 'requestKey' => 'http-synthetic-reservation-0002']));
        $this->assertTrue($withdrawn);
        $this->assertSame($before + 2, DB::table(MembershipReservationRecords::TABLE)->count());
    }

    public function test_the_index_body_is_withheld_when_the_pre_bytes_re_read_refuses(): void
    {
        $fixture = $this->memberActivationFixture();
        $this->actAs($fixture['buyer']);
        $armed = 'owner';
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$armed, $fixture): void {
            if ($armed === null || ! self::inside(MembershipCustomerIndex::class, 'proveCapturedBeforeBytes')) {
                return;
            }
            if ($armed === 'owner') {
                // Inside the pre-bytes transaction only, so its rollback restores the account afterwards.
                DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)
                    ->update(['active' => false, 'access_version' => DB::raw('access_version + 1')]);
            } elseif ($armed === 'marker') {
                // Only the request capsule sees this: the domain re-read does not read the session.
                $this->app['session.store']->forget('_production_customer_identity');
            } else {
                config(['production-memberships.http_mounted' => false]);
            }
            $armed = null;
        });
        $this->notFound($this->getMember('/production-memberships/periods'))->assertDontSee('periods');
        $this->assertNull($armed);
        $this->assertSame(1, (int) DB::table('customer_accounts')->where('id', $fixture['buyer']['principal']->accountId)->value('active'));
        // Unchanged state releases the body again; a mount withdrawal at pre-bytes withholds it.
        $this->getMember('/production-memberships/periods')->assertOk();
        $armed = 'marker';
        $this->notFound($this->getMember('/production-memberships/periods'))->assertDontSee('periods');
        $this->assertNull($armed);
        $this->actAs($fixture['buyer']);
        $armed = 'mount';
        $this->notFound($this->getMember('/production-memberships/periods'))->assertDontSee('periods');
        $this->assertNull($armed);
    }

    private function actAs(array $owner): void
    {
        Auth::guard('customer')->setUser($owner['user']);
        $this->withSession(['_token' => str_repeat('c', 40), '_production_customer_identity' => ['binding_digest' => $owner['principal']->sessionBindingDigest()]]);
    }

    private function reserve(array $fixture, string $period): TestResponse
    {
        return $this->postMember('/production-memberships/periods/'.$period.'/reservations', [
            'offerId' => (string) $fixture['selection'], 'expectedRevision' => $fixture['expected_revision'], 'requestKey' => self::REQUEST_KEY]);
    }

    private function getMember(string $path): TestResponse
    {
        return $this->get($path, ['Accept' => 'application/json']);
    }

    private function postMember(string $path, array $body): TestResponse
    {
        return $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => session()->token()], json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function notFound(TestResponse $response): TestResponse
    {
        $this->privateJson($response)->assertNotFound();
        $this->assertSame(MembershipHttpMountTest::NOT_FOUND, $response->getContent());

        return $response;
    }

    private function submitted(TestResponse $response): void
    {
        $this->privateJson($response)->assertStatus(202)->assertExactJson(['code' => 'MEMBERSHIP_SUBMITTED', 'refresh' => true]);
    }

    private function privateJson(TestResponse $response): TestResponse
    {
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());

        return $response;
    }

    private function countInside(string $event, string $method): \Closure
    {
        $count = 0;
        DB::connection()->getEventDispatcher()->listen($event, static function () use (&$count, $method): void {
            if (self::inside(MembershipCustomerIndex::class, $method)) {
                $count++;
            }
        });

        return static function () use (&$count): int {
            return $count;
        };
    }

    private static function inside(string $class, string $method): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            if (($frame['class'] ?? null) === $class && ($frame['function'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }
}
