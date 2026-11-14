<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\TaxPaidOriginalCommitDispatcher;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxPolicy;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxReads;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxTransaction;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\PaidTaxRootHttpFixture;
use Tests\TestCase;

/** Bounded Tax history locators over the consumer's own retained rows; synthetic buyers and payments only. */
final class PaidTaxHistoryIndexTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;
    use PaidTaxRootHttpFixture;

    private const PRIVATE_COLUMNS = ['provider_account', 'provider_session_id', 'provider_payment_id', 'request_public_id', 'request_hash',
        'reviewed_session_public_id', 'reviewed_session_hash', 'order_hash', 'payload', 'payload_hash'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->preparePaidTaxFixture();
    }

    private function login(array $buyer): void
    {
        $this->actingAs($buyer['user'], 'customer')->withSession(['_token' => str_repeat('c', 40),
            '_production_customer_identity' => ['binding_digest' => $buyer['principal']->sessionBindingDigest()]]);
    }

    private function finalized(): array
    {
        $f = $this->paidTaxOrder();
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);

        return $f;
    }

    private function index(array $buyer): array
    {
        $read = PaidTaxProjectionRead::begin();
        $list = (new PaidTaxReads)->index($buyer['principal'], $buyer['user'], $read);
        $read->proveBeforeBytes();

        return $list;
    }

    /**
     * Rows re-sealed with the testing key from one genuine retained batch: valid list inputs, but no producer order
     * exists for them, so they test only the bounded window and are never opened through show().
     */
    private function sealedCopies(int $count): array
    {
        $template = (array) DB::table('paid_tax_order_origins')->orderBy('id')->first();
        $payload = PaidTaxRecords::decode($template);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [...$template, 'public_id' => (string) Str::uuid(), 'order_public_id' => (string) Str::uuid(), 'provider_payment_id' => 'pi_synthetic_index_'.$i.'_'.Str::random(8)];
            unset($row['id']);
            $sealed = [...$payload, 'origin_id' => $row['public_id'], 'order_id' => $row['order_public_id'], 'source_identity' => (new PaidTaxGrants)->batchIdentity($row)];
            DB::table('paid_tax_order_origins')->insert([...$row, ...PaidTaxRecords::encode($sealed)]);
            $ids[] = $row['public_id'];
        }

        return $ids;
    }

    public function test_zero_rows_returns_an_empty_bounded_list_over_http(): void
    {
        $f = $this->paidTaxOrder();
        $this->login($f['buyer']);
        $response = $this->get('/paid-tax-grants/index', ['Accept' => 'text/html'])->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame(['schemaVersion' => 2, 'originLimit' => 20, 'hasMore' => false, 'origins' => []], $response->json());
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertContains('Cookie', $response->baseResponse->getVary());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_genuine_batches_are_newest_first_display_safe_and_open_through_show(): void
    {
        $f = $this->paidTaxOrder();
        $this->login($f['buyer']);
        $finalize = fn (string $order) => $this->call('POST', '/paid-tax-grants/orders/'.$order.'/finalize', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => str_repeat('c', 40)], '{}')->assertOk()->json('origin');
        $first = $finalize($f['order']['orderId']);
        $second = $this->secondPaidTaxOrder($f, 'cs_test_historyindexsecond', 'pi_historyindexsecond');
        $other = $finalize($second['order']['orderId']);
        $delegate = DB::connection()->getEventDispatcher();
        $response = $this->get('/paid-tax-grants/index')->assertOk();
        // Unlike show(), the owner-only index opens no producer SourceV2, so no original commit observer is installed.
        $this->assertSame($delegate, DB::connection()->getEventDispatcher());
        $this->assertNotInstanceOf(TaxPaidOriginalCommitDispatcher::class, DB::connection()->getEventDispatcher());
        $list = $response->json();
        $rows = DB::table('paid_tax_order_origins')->orderByDesc('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame(['schemaVersion' => 2, 'originLimit' => 20, 'hasMore' => false, 'origins' => array_map(fn (array $row): array => [
            'id' => $row['public_id'], 'orderId' => $row['order_public_id'], 'createdAt' => str_replace(' ', 'T', $row['created_at']).'Z',
            'lineCount' => 1, 'provenance' => 'synthetic_rehearsal'], $rows)], $list);
        $this->assertSame([$other['id'], $first['id']], array_column($list['origins'], 'id'));
        foreach ($rows as $row) {
            foreach (self::PRIVATE_COLUMNS as $column) {
                $response->assertDontSee($row[$column], false);
            }
        }
        foreach (['Declared synthetic buyer', 'token', 'storage_path', 'contracts/'] as $private) {
            $response->assertDontSee($private, false);
        }
        foreach ($list['origins'] as $origin) {
            $shown = $this->get('/paid-tax-grants/origins/'.$origin['id'])->assertOk();
            $this->assertInstanceOf(TaxPaidOriginalCommitDispatcher::class, DB::connection()->getEventDispatcher(), 'show() holds its producer observer until bytes.');
            $shown->assertJsonPath('origin.orderId', $origin['orderId']);
            unset($shown);
        }
    }

    public static function windows(): array
    {
        return ['exactly twenty' => [19, 20, false], 'twenty-one sentinel' => [20, 20, true], 'twenty-two' => [21, 20, true]];
    }

    #[DataProvider('windows')]
    public function test_twenty_rows_fit_and_the_twenty_first_is_only_a_sentinel(int $copies, int $shown, bool $more): void
    {
        $f = $this->finalized();
        $sealed = $this->sealedCopies($copies);
        $list = $this->index($f['buyer']);
        $newest = array_reverse([$f['batch']['id'], ...$sealed]);
        $this->assertSame($more, $list['hasMore']);
        $this->assertCount($shown, $list['origins']);
        $this->assertSame(array_slice($newest, 0, $shown), array_column($list['origins'], 'id'));
        if ($more) {
            $this->assertNotContains($f['batch']['id'], array_column($list['origins'], 'id'), 'The oldest batch is behind the sentinel.');
        }
    }

    public function test_foreign_account_batches_are_excluded_both_ways(): void
    {
        $f = $this->finalized();
        $foreign = $this->enrollThroughLocalSmtp('foreign-tax-index@example.test');
        $g = $this->secondPaidTaxOrder(['catalog' => $f['catalog'], 'buyer' => $foreign], 'cs_test_foreignhistoryindex', 'pi_foreignhistoryindex');
        $theirs = (new PaidTaxGrants)->finalize($foreign['principal'], $foreign['user'], $g['order']['orderId']);
        $this->assertSame([$f['batch']['id']], array_column($this->index($f['buyer'])['origins'], 'id'));
        $this->assertSame([$theirs['id']], array_column($this->index($foreign)['origins'], 'id'));
        $this->login($foreign);
        $this->get('/paid-tax-grants/index')->assertOk()->assertDontSee($f['batch']['id'])->assertDontSee($f['order']['orderId']);
    }

    public static function tampering(): array
    {
        return ['transplanted ciphertext' => ['transplant', 409], 'column disagrees with seal' => ['column', 409],
            'payload seal mismatch' => ['seal', 503], 'other buyer inside seal' => ['buyer', 404], 'tampered sentinel' => ['sentinel', 409]];
    }

    #[DataProvider('tampering')]
    public function test_a_tampered_retained_row_refuses_the_whole_list_without_bytes(string $kind, int $status): void
    {
        $f = $this->finalized();
        $template = (array) DB::table('paid_tax_order_origins')->sole();
        $row = [...$template, 'public_id' => (string) Str::uuid(), 'order_public_id' => (string) Str::uuid(), 'provider_payment_id' => 'pi_tampered_'.$kind];
        unset($row['id']);
        $payload = PaidTaxRecords::decode($template);
        if ($kind === 'sentinel') {
            // Twenty valid newer rows push the tampered row into the sentinel position; it is still verified.
            DB::table('paid_tax_order_origins')->insert($row);
            $this->sealedCopies(20);
        } else {
            match ($kind) {
                'transplant' => null,
                'column' => $row = [...$row, ...PaidTaxRecords::encode([...$payload, 'origin_id' => $row['public_id'], 'order_id' => $row['order_public_id']])],
                'seal' => $row['payload_hash'] = hash('sha256', 'not the sealed payload'),
                'buyer' => $row = [...$row, ...PaidTaxRecords::encode([...$payload, 'origin_id' => $row['public_id'], 'order_id' => $row['order_public_id'],
                    'source_identity' => (new PaidTaxGrants)->batchIdentity($row), 'original_buyer' => [...$payload['original_buyer'], 'origin_id' => 999999]])],
            };
            DB::table('paid_tax_order_origins')->insert($row);
        }
        try {
            $this->index($f['buyer']);
            $this->fail('A tampered retained row was listed.');
        } catch (PaidTaxException $error) {
            $this->assertSame($status, $error->status);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->login($f['buyer']);
        $this->get('/paid-tax-grants/index')->assertStatus($status)->assertJsonPath('code', 'PAID_TAX_UNAVAILABLE')
            ->assertDontSee($f['batch']['id'])->assertDontSee($f['order']['orderId']);
    }

    public function test_owner_withdrawal_after_commit_is_refused_before_list_bytes(): void
    {
        $f = $this->finalized();
        $read = PaidTaxProjectionRead::begin();
        $list = (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
        $this->assertSame($f['batch']['id'], $list['origins'][0]['id']);
        DB::table('customer_accounts')->where('id', $f['buyer']['principal']->accountId)->update(['active' => 0, 'access_version' => DB::raw('access_version + 1')]);
        try {
            $read->proveBeforeBytes();
            $this->fail('A withdrawn owner received list bytes.');
        } catch (PaidTaxException $error) {
            $this->assertSame(403, $error->status);
        }
        $this->expectException(PaidTaxException::class);
        $read->proveBeforeBytes();
    }

    public function test_owner_withdrawal_between_response_and_stream_emits_only_the_fixed_refusal(): void
    {
        $f = $this->finalized();
        $this->login($f['buyer']);
        $response = $this->get('/paid-tax-grants/index')->assertOk();
        DB::table('customer_accounts')->where('id', $f['buyer']['principal']->accountId)->update(['active' => 0, 'access_version' => DB::raw('access_version + 1')]);
        $body = $response->streamedContent();
        $this->assertSame(['error' => 'Paid Tax request unavailable.', 'status' => 403], json_decode($body, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($f['batch']['id'], $body);
        $this->assertStringNotContainsString($f['order']['orderId'], $body);
    }

    /**
     * Withdrawal inside the original index frame (at its first policy resolution or at the final fence) is refused and
     * rolled back with the frame. The frame writes nothing, so it installs no commit admission: a write that a commit
     * callback injects into the same physical transaction is that callback's own and commits; the terminal raw owner
     * proof after commit then refuses. Each listener fires only inside PaidTaxTransaction::run, never during the
     * separate pre-frame ProductionCustomerAccess::current() transaction.
     */
    public static function withdrawalPoints(): array
    {
        return ['frame start' => ['start', false], 'final fence' => ['fence', false], 'commit callback' => ['commit', true]];
    }

    #[DataProvider('withdrawalPoints')]
    public function test_owner_withdrawal_at_each_original_frame_point_refuses_without_list_bytes(string $point, bool $durable): void
    {
        $f = $this->finalized();
        $pdo = DB::getPdo();
        $id = $f['buyer']['principal']->accountId;
        $before = (array) DB::table('customer_accounts')->where('id', $id)->sole();
        $inFrame = static fn (): bool => array_any(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), fn (array $frame): bool => ($frame['class'] ?? null) === PaidTaxTransaction::class);
        $withdraw = static fn () => $pdo->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?')->execute([$id]);
        $calls = 0;
        $resolutions = 0;
        if ($point === 'commit') {
            DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function () use ($inFrame, $withdraw, &$calls): void {
                if ($calls === 0 && $inFrame()) {
                    $calls++;
                    $withdraw();
                }
            });
        } else {
            $this->app->afterResolving(PaidTaxPolicy::class, static function () use ($point, $inFrame, $withdraw, &$calls, &$resolutions): void {
                if ($calls === 0 && $inFrame() && ++$resolutions === ($point === 'start' ? 1 : 2)) {
                    $calls++;
                    $withdraw();
                }
            });
        }
        $read = PaidTaxProjectionRead::begin();
        $listed = null;
        try {
            $listed = (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
            $this->fail('A withdrawn owner was listed.');
        } catch (PaidTaxException $error) {
            $this->assertSame(403, $error->status);
        }
        $this->assertNull($listed);
        $this->assertSame(1, $calls);
        $this->assertSame($durable ? 0 : 1, (int) DB::table('customer_accounts')->where('id', $id)->value('active'));
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, DB::transactionLevel());
        try {
            $read->proveBeforeBytes();
            $this->fail('A refused index read admitted bytes.');
        } catch (PaidTaxException $error) {
            // In-frame refusal never captured the read; a post-commit refusal captured it but it re-proves the owner.
            $this->assertSame($durable ? 403 : 503, $error->status);
        }
        if ($durable) {
            // Identity retention forbids reactivation, so the committed withdrawal stays refused for fresh reads.
            try {
                $this->index($f['buyer']);
                $this->fail('A withdrawn owner was listed by a fresh read.');
            } catch (PaidTaxException $error) {
                $this->assertSame(403, $error->status);
            }

            return;
        }
        $this->assertSame($before, (array) DB::table('customer_accounts')->where('id', $id)->sole());
        $this->assertSame([$f['batch']['id']], array_column($this->index($f['buyer'])['origins'], 'id'));
    }

    /** A replaced policy version keeps retained batches listable (the same retained() rule as show); flips still close bytes. */
    public static function policyFlips(): array
    {
        return ['rehearsal withdrawn' => ['rehearsal', 404], 'delivery policy replaced' => ['policy', 200], 'tax purpose withdrawn' => ['purpose', 503]];
    }

    #[DataProvider('policyFlips')]
    public function test_policy_flip_closes_captured_bytes_and_governs_the_next_request(string $flip, int $next): void
    {
        $f = $this->finalized();
        $read = PaidTaxProjectionRead::begin();
        (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
        $policy = config('paid-tax-grants.delivery_policy');
        $apply = fn () => match ($flip) {
            'rehearsal' => config(['paid-tax-grants.rehearsal_enabled' => false]),
            'policy' => config(['paid-tax-grants.delivery_policy' => [...$policy, 'version' => 'replaced-synthetic-tax-delivery-v2']]),
            'purpose' => config(['production-tax-checkout.enabled' => false]),
        };
        $apply();
        $refused = null;
        try {
            $read->proveBeforeBytes();
        } catch (PaidTaxException|CheckoutException $error) {
            $refused = $error;
        }
        $this->assertNotNull($refused, 'A policy flip admitted list bytes.');
        $this->assertContains($refused->status, [403, 404, 503]);
        $this->login($f['buyer']);
        $response = $this->get('/paid-tax-grants/index')->assertStatus($next);
        if ($next === 200) {
            $this->assertSame([$f['batch']['id']], array_column($response->json('origins'), 'id'));
        } else {
            $response->assertJsonPath('code', 'PAID_TAX_UNAVAILABLE')->assertDontSee($f['batch']['id']);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_index_mode_projection_read_is_one_use_and_never_mixes_with_producer_capture(): void
    {
        $f = $this->finalized();
        $read = PaidTaxProjectionRead::begin();
        try {
            $read->proveBeforeBytes();
            $this->fail('An uncaptured read admitted bytes.');
        } catch (PaidTaxException $error) {
            $this->assertSame(503, $error->status);
        }
        $read = PaidTaxProjectionRead::begin();
        (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
        try {
            (new PaidTaxReads)->index($f['buyer']['principal'], $f['buyer']['user'], $read);
            $this->fail('A captured index read was captured again.');
        } catch (PaidTaxException $error) {
            $this->assertSame(503, $error->status);
        }
        try {
            (new PaidTaxReads)->show($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'], $read);
            $this->fail('An index read accepted a producer capture.');
        } catch (PaidTaxException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_unmounted_index_and_page_are_unregistered_and_runtime_withdrawal_refuses_without_identity_or_sql(): void
    {
        $f = $this->finalized();
        $this->login($f['buyer']);
        $this->assertTrue(Route::has('paid-tax-grants.index'));
        $this->assertTrue(Route::has('paid-tax-grants.page'));
        config(['paid-tax-grants.http_mounted' => false]);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->app->forgetInstance(ProductionCustomerSessions::class);
        $this->app->forgetInstance(PaidTaxPolicy::class);
        $this->app->afterResolving(PaidTaxPolicy::class, fn () => $this->fail('Withdrawn path resolved policy.'));
        $this->app->afterResolving(ProductionCustomerSessions::class, fn () => $this->fail('Withdrawn path resolved identity.'));
        foreach (['/paid-tax-grants/index', '/paid-tax-licenses'] as $path) {
            $this->get($path, ['Accept' => 'text/html'])->assertNotFound()->assertHeader('Content-Type', 'application/json')
                ->assertJsonPath('code', 'PAID_TAX_UNAVAILABLE')->assertDontSee($f['batch']['id'])->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame([], $queries, 'A withdrawn mount performed SQL.');
        $this->taxGrantMount = false;
        $this->refreshApplication();
        $this->assertFalse(Route::has('paid-tax-grants.index'));
        $this->assertFalse(Route::has('paid-tax-grants.page'));
        $this->get('/paid-tax-grants/index', ['Accept' => 'text/html'])->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->get('/paid-tax-licenses')->assertNotFound();
    }

    public function test_query_body_and_wrong_method_refuse_before_identity(): void
    {
        $this->app->afterResolving(ProductionCustomerSessions::class, fn () => $this->fail('Refused intake resolved identity.'));
        $this->get('/paid-tax-grants/index?cursor=PRIVATE-TOKEN')->assertStatus(422)->assertDontSee('PRIVATE-TOKEN');
        $this->call('GET', '/paid-tax-grants/index', [], [], [], [], '{}')->assertStatus(422);
        $this->call('POST', '/paid-tax-grants/index', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(405)->assertJsonPath('code', 'PAID_TAX_UNAVAILABLE');
        $this->call('PUT', '/paid-tax-grants/index', [], [], [], [], '')->assertStatus(405);
        $this->get('/paid-tax-licenses?token=PRIVATE-TOKEN')->assertStatus(422)->assertDontSee('PRIVATE-TOKEN')->assertHeader('Cache-Control', 'no-store, private');
        $this->call('GET', '/paid-tax-licenses', [], [], [], [], 'PRIVATE-BODY')->assertStatus(422)->assertDontSee('PRIVATE-BODY');
        $this->assertFalse($this->app->resolved(ProductionCustomerSessions::class));
    }

    public function test_page_shell_requires_the_current_buyer_and_carries_no_private_props(): void
    {
        $f = $this->finalized();
        $this->get('/paid-tax-licenses')->assertForbidden()->assertJsonPath('code', 'PAID_TAX_UNAVAILABLE');
        $this->login($f['buyer']);
        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/paid-tax-licenses'));
        $page = $this->get('/paid-tax-licenses', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->assertHeader('X-Inertia', 'true');
        $this->assertSame('PaidTaxLicenses', $page->json('component'));
        $this->assertSame(['errors'], array_keys($page->json('props')));
        $this->assertSame([], $page->json('props.errors'));
        $html = $this->get('/paid-tax-licenses')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        foreach ([$page, $html] as $response) {
            foreach ([$f['batch']['id'], $f['order']['orderId'], 'Declared synthetic buyer', $f['batch']['lines'][0]['originHash']] as $private) {
                $response->assertDontSee($private, false);
            }
        }
        $this->assertContains('Cookie', $html->baseResponse->getVary());
        $this->assertDatabaseCount('paid_tax_order_origins', 1);
    }
}
