<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionTaxCheckout\TaxPaidOriginalCommitDispatcher;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Grants\PaidTax\PaidTaxCommands;
use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxPrepareStream;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxRequestBudget;
use App\Domain\Grants\PaidTax\PaidTaxRows;
use App\Domain\Grants\PaidTax\PaidTaxSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxDeliveryFixtures;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\TestCase;

/** Real retained synthetic paid order and private original; no live provider/host claim. */
class PaidTaxDownloadJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxDeliveryFixtures;
    use PaidTaxGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['paid-tax-grants.downloads_enabled' => true]);
    }

    protected function complete(bool $twoLines = false): array
    {
        $f = $this->paidTaxOrder(twoLines: $twoLines);
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);

        return $f;
    }

    protected function input(array $line, string $kind): array
    {
        return ['requestKey' => (string) Str::uuid(), 'originHash' => $line['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))];
    }

    protected function authorize(array $f, string $kind = 'master_wav'): array
    {
        return (new PaidTaxDownloads)->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $this->input($f['batch']['lines'][0], $kind),
            $f['buyer']['principal'], $f['buyer']['user']);
    }

    protected function refused(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('The invalid delivery operation was accepted.');
        } catch (PaidTaxException $error) {
            $this->assertSame($status, $error->status);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::getPdo()->inTransaction());
    }

    public function test_actual_tax_pdf_and_purchased_master_have_idempotent_hash_only_authorization_and_one_attempt(): void
    {
        $f = $this->complete();
        $downloads = new PaidTaxDownloads;
        $original = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($original)['artifact'];
        $master = $f['catalog']['media']['master_wav'];
        $expected = ['contract' => file_get_contents(Storage::disk('local')->path($artifact['storage_path'])),
            'master_wav' => file_get_contents(Storage::disk('local')->path($master->storage_path))];
        foreach ($expected as $kind => $bytes) {
            $input = $this->input($f['batch']['lines'][0], $kind);
            $read = PaidTaxProjectionRead::begin();
            $a = $downloads->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user'], $read);
            $read->proveBeforeBytes();
            $this->assertSame($a, $downloads->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']));
            $stored = (array) DB::table('paid_tax_authorizations')->where('public_id', $a['id'])->sole();
            $this->assertSame(hash('sha256', $a['token']), $stored['token_hash']);
            $this->assertStringNotContainsString($a['token'], json_encode($stored, JSON_THROW_ON_ERROR));
            $transfer = $downloads->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->assertSame([strlen($bytes), hash('sha256', $bytes)], [$transfer->sizeBytes, $transfer->sha256]);
            $received = '';
            $transfer->writeTo(static function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });
            $this->assertSame($bytes, $received);
            $this->refused(409, fn () => $downloads->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']));
            $status = $downloads->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->assertStringNotContainsString($a['token'], json_encode($status, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($artifact['storage_path'], json_encode($status, JSON_THROW_ON_ERROR));
        }
        $this->assertSame(2, $status['lines'][0]['attemptCount']);
        $this->assertSame(['attempted', 'attempted'], array_column($status['lines'][0]['history'], 'status'));
        $this->assertSame($original, (array) DB::table('paid_tax_originals')->sole());
        $this->assertDatabaseCount('paid_tax_redemptions', 2);
        $this->assertDatabaseCount('paid_authorizations', 0);
        $this->assertDatabaseCount('license_grants', 0);
        PaidTaxSchema::assertInstalled();
    }

    public function test_partial_order_changed_idempotency_target_and_literal_default_off_never_issue_authority(): void
    {
        $f = $this->paidTaxOrder(twoLines: true);
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->refused(409, fn () => $this->authorize($f));
        $this->assertDatabaseCount('paid_tax_authorizations', 0);
        $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $d = new PaidTaxDownloads;
        $input = $this->input($f['batch']['lines'][0], 'master_wav');
        $a = $d->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']);
        $this->refused(409, fn () => $d->authorize($f['batch']['id'], $f['batch']['lines'][1]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']));
        $input['nonce'] = str_repeat('a', 64);
        $this->refused(409, fn () => $d->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $input, $f['buyer']['principal'], $f['buyer']['user']));
        $this->refused(404, fn () => $d->redeem((string) Str::uuid(), $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->refused(403, fn () => $d->redeem($f['batch']['id'], $a['id'], str_repeat('x', 43), $f['buyer']['principal'], $f['buyer']['user']));
        config(['paid-tax-grants.downloads_enabled' => 'true']);
        $this->refused(404, fn () => $this->authorize($f));
        $this->assertDatabaseCount('paid_tax_authorizations', 1);
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
    }

    public function test_three_per_minute_and_retained_attempt_limit_are_enforced_without_revoking_originals(): void
    {
        $f = $this->complete();
        $auths = [$this->authorize($f), $this->authorize($f), $this->authorize($f)];
        $this->refused(429, fn () => $this->authorize($f));
        $d = new PaidTaxDownloads;
        foreach ($auths as $a) {
            $transfer = $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $bytes = 0;
            $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);
            });
            $this->assertSame($transfer->sizeBytes, $bytes);
        }
        $this->refused(409, fn () => $this->authorize($f));
        $this->assertDatabaseCount('paid_tax_redemptions', 3);
        $this->assertDatabaseCount('paid_tax_originals', 1);
        $this->assertSame(3, $d->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['lines'][0]['attemptCount']);
    }

    public function test_missing_exact_private_original_refuses_before_attempt_and_same_token_retries_after_restore(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f, 'contract');
        $original = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($original)['artifact'];
        $path = Storage::disk('local')->path($artifact['storage_path']);
        rename($path, $path.'.retained-away');
        $d = new PaidTaxDownloads;
        try {
            try {
                $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
                $this->fail('Missing original yielded a transfer.');
            } catch (DeliveryException $error) {
                $this->assertSame('target_unavailable', $error->reason);
            }
            $this->assertDatabaseCount('paid_tax_redemptions', 0);
        } finally {
            rename($path.'.retained-away', $path);
        }
        $received = '';
        $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user'])
            ->writeTo(static function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });
        $this->assertSame(file_get_contents($path), $received);
        $this->assertSame($original, (array) DB::table('paid_tax_originals')->sole());
    }

    public function test_request_admitted_before_expiry_can_complete_but_expired_new_request_records_nothing(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f);
        $b = $this->authorize($f);
        $budget = PaidTaxRequestBudget::capture(null);
        $expired = CarbonImmutable::now('UTC')->addSeconds(121);
        CarbonImmutable::setTestNow($expired);
        try {
            $d = new PaidTaxDownloads;
            $this->refused(410, fn () => $d->redeem($f['batch']['id'], $b['id'], $b['token'], $f['buyer']['principal'], $f['buyer']['user']));
            $this->assertDatabaseCount('paid_tax_redemptions', 0);
            $transfer = $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user'], $budget->receivedAt, $budget);
            $bytes = 0;
            $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);
            });
            $this->assertSame($transfer->sizeBytes, $bytes);
            $this->assertDatabaseCount('paid_tax_redemptions', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_late_authorization_key_change_and_source_decryption_owner_withdrawal_roll_back_new_rows(): void
    {
        $f = $this->complete();
        $key = config('app.key');
        $pdo = DB::getPdo();
        $user = (array) DB::table('users')->where('id', $f['buyer']['user']->id)->sole();
        $encrypter = Crypt::getFacadeRoot();
        $mode = 'key';
        $calls = 0;
        $mutations = [];
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function ($event) use ($key, $pdo, $user, $encrypter, &$mode, &$calls, &$mutations): void {
            if ($event->connection->getEventDispatcher()::class !== TaxPaidOriginalCommitDispatcher::class) {
                return;
            }
            if ($mode === 'key') {
                config(['app.key' => $key.'-synthetic-changed']);
            } else {
                Crypt::shouldReceive('decryptString')->andReturnUsing(static function (string $cipher) use ($encrypter, $pdo, $user, &$calls, &$mutations): string {
                    if ($calls++ === 0) {
                        $mutations[] = ['pdo_transaction' => $pdo->inTransaction(), 'framework_depth' => DB::transactionLevel(),
                            'calls' => array_map(static fn (array $frame): string => ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''),
                                array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 16))];
                        $pdo->exec("UPDATE users SET password='synthetic-delivery-withdrawal' WHERE id=".$user['id']);
                    }

                    return $encrypter->decryptString($cipher);
                });
            }
        });
        try {
            // Producer key-metadata withdrawal is a controlled409 before consumer raw admission.
            $this->refused(409, fn () => $this->authorize($f));
            config(['app.key' => $key]);
            $mode = 'owner';
            $this->refused(403, fn () => $this->authorize($f));
        } finally {
            Crypt::swap($encrypter);
            config(['app.key' => $key]);
        }
        $this->assertGreaterThan(0, $calls);
        fwrite(STDOUT, 'Tax withdrawal mutation timing: '.json_encode($mutations, JSON_THROW_ON_ERROR).PHP_EOL);
        $this->assertTrue($mutations[0]['pdo_transaction']);
        $this->assertDatabaseCount('paid_tax_authorizations', 0);
        $this->assertSame($user, (array) DB::table('users')->where('id', $user['id'])->sole());
    }

    public function test_late_owner_change_in_first_chunk_clock_refuses_all_bytes_after_committed_attempt(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f);
        $pdo = DB::getPdo();
        $user = (array) DB::table('users')->where('id', $f['buyer']['user']->id)->sole();
        $calls = 0;
        $d = new PaidTaxDownloads(static function () use ($pdo, $user, &$calls): int {
            if (++$calls === 2) {
                $pdo->exec("UPDATE users SET password='synthetic-first-chunk-withdrawal' WHERE id=".$user['id']);
            }

            return hrtime(true);
        });
        $transfer = $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
        $bytes = '';
        try {
            $this->refused(403, function () use ($transfer, &$bytes): void {
                $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
                    $bytes .= $chunk;
                });
            });
            $this->assertSame('', $bytes);
            $this->assertSame(2, $calls);
        } finally {
            $query = $pdo->prepare('UPDATE users SET password=? WHERE id=?');
            $query->execute([$user['password'], $user['id']]);
        }
        $this->assertSame(1, (new PaidTaxDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['lines'][0]['attemptCount']);
        $this->assertDatabaseCount('paid_tax_originals', 1);
    }

    public function test_pre_first_chunk_failure_and_never_started_close_release_original_observer_after_budget_withdrawal(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f);
        $calls = 0;
        $d = new PaidTaxDownloads(static function () use (&$calls): int {
            if (++$calls === 2) {
                throw new \RuntimeException('synthetic-before-first-callback');
            }

            return hrtime(true);
        });
        $transfer = $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
        try {
            $transfer->writeTo(static function (): void {
                throw new \LogicException('No byte may be handed off.');
            });
            $this->fail('Clock failure yielded bytes.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic-before-first-callback', $error->getMessage());
        }
        $next = $this->authorize($f);
        $budget = PaidTaxRequestBudget::capture(null);
        $unused = (new PaidTaxDownloads)->redeem($f['batch']['id'], $next['id'], $next['token'], $f['buyer']['principal'], $f['buyer']['user'], requestBudget: $budget);
        $budget->shortenTo(0);
        config(['paid-tax-grants.downloads_enabled' => false]);
        // Exercise destructor-only abandonment after both policy and original budget withdrawal.
        unset($unused);
        config(['paid-tax-grants.downloads_enabled' => true]);
        $this->assertSame(2, (new PaidTaxDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['lines'][0]['attemptCount']);
        $this->assertDatabaseCount('paid_tax_redemptions', 2);
    }

    public function test_recent_issuance_set_is_reproved_after_late_valid_commit_append(): void
    {
        $f = $this->complete();
        $this->authorize($f);
        $this->authorize($f);
        $source = (array) DB::table('paid_tax_authorizations')->orderBy('id')->first();
        unset($source['id']);
        $source['public_id'] = (string) Str::uuid();
        $source['request_key'] = (string) Str::uuid();
        $source['token_hash'] = hash('sha256', 'NONBINDING SYNTHETIC LATE ISSUANCE');
        $pdo = DB::getPdo();
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function ($event) use ($pdo, $source, &$calls): void {
            if ($event->connection->getEventDispatcher()::class !== TaxPaidOriginalCommitDispatcher::class) {
                return;
            }
            if ($calls++ === 0) {
                $q = $pdo->prepare('INSERT INTO paid_tax_authorizations ('.implode(', ', array_keys($source)).') VALUES ('.implode(', ', array_fill(0, count($source), '?')).')');
                $q->execute(array_values($source));
            }
        });
        $this->refused(403, fn () => $this->authorize($f));
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('paid_tax_authorizations', 2);
    }

    public function test_live_snapshot_budget_shortening_is_observed_before_any_adapter_copy(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f);
        $budget = PaidTaxRequestBudget::capture(null);
        $copies = 0;
        $stream = new class($copies) extends PaidTaxPrepareStream
        {
            public function __construct(private int &$copies) {}

            protected function copyTarget(array $target, $destination, mixed $deadline): void
            {
                $this->copies++;
                parent::copyTarget($target, $destination, $deadline);
            }
        };
        app()->bind(PaidTaxPrepareStream::class, static fn () => $stream);
        app()->afterResolving(PaidTaxPrepareStream::class, static function () use ($budget): void {
            $budget->shortenTo(0);
        });
        try {
            (new PaidTaxDownloads)->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user'], requestBudget: $budget);
            $this->fail('Spent snapshot budget yielded transfer.');
        } catch (PaidTaxException|DeliveryException) {
            $this->assertSame(0, $budget->value(), 'The resolver must actually shorten this original budget.');
            $this->assertSame(0, $copies, 'The adapter copied after its live request budget was spent.');
        }
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
    }

    public function test_late_second_chunk_clock_cannot_return_old_tick_to_deliver_past_transfer_end(): void
    {
        $f = $this->allRolesTaxOrder();
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $a = $this->authorize($f);
        config(['paid-tax-grants.transfer_base_seconds' => 0, 'paid-tax-grants.transfer_min_bytes_per_second' => 1073741824]);
        $start = hrtime(true);
        $calls = 0;
        $d = new PaidTaxDownloads(static function () use ($start, &$calls): int {
            if (++$calls === 3) {
                usleep(1_100_000);
            }

            return $start;
        });
        $transfer = $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertGreaterThan(1048576, $transfer->sizeBytes);
        $bytes = 0;
        $this->refused(410, function () use ($transfer, &$bytes): void {
            $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
                $bytes += strlen($chunk);
            });
        });
        $this->assertSame(1048576, $bytes, 'The delayed second chunk reached its consumer.');
        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
    }

    public function test_new_delivery_public_reads_refuse_while_schema_admission_is_still_pending(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite grammar callback is the explicit pending-admission probe.');
        }
        $f = $this->complete();
        $connection = DB::connection();
        $original = $connection->getSchemaGrammar();
        $called = false;
        $refusal = false;
        (new PaidTaxCommands)->run($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'],
            function (array $graph, PaidTaxRows $rows) use ($connection, $original, &$called, &$refusal): array {
                $grammar = new class($connection, $rows, $called, $refusal) extends SQLiteGrammar
                {
                    public function __construct($connection, private PaidTaxRows $rows, private bool &$called, private bool &$refusal)
                    {
                        parent::__construct($connection);
                    }

                    public function compileCreate(Blueprint $blueprint, Fluent $command)
                    {
                        if (! $this->called) {
                            $this->called = true;
                            try {
                                $this->rows->one('paid_tax_authorizations', 'id = ?', [0]);
                            } catch (PaidTaxException) {
                                $this->refusal = true;
                            }
                        }

                        return parent::compileCreate($blueprint, $command);
                    }
                };
                $connection->setSchemaGrammar($grammar);
                try {
                    $rows->admitDelivery();
                } finally {
                    $connection->setSchemaGrammar($original);
                }

                return [];
            });
        $this->assertTrue($called);
        $this->assertTrue($refusal, 'Public new-table access succeeded before complete schema admission.');
        $this->assertDatabaseCount('paid_tax_authorizations', 0);
        $this->authorize($f);
        $this->assertDatabaseCount('paid_tax_authorizations', 1);
    }

    public function test_native_new_delivery_public_reads_refuse_while_schema_admission_is_still_pending(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native MySQL grammar callback; the SQLite pending-admission probe is the sibling SQLite-only test.');
        }
        $f = $this->complete();
        $connection = DB::connection();
        $original = $connection->getSchemaGrammar();
        $called = false;
        $refusal = false;
        (new PaidTaxCommands)->run($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'],
            function (array $graph, PaidTaxRows $rows) use ($connection, $original, &$called, &$refusal): array {
                // Admission compiles the native installation plan; the first CREATE compiles mid-admission.
                $grammar = new class($connection, $rows, $called, $refusal) extends MySqlGrammar
                {
                    public function __construct($connection, private PaidTaxRows $rows, private bool &$called, private bool &$refusal)
                    {
                        parent::__construct($connection);
                    }

                    public function compileCreate(Blueprint $blueprint, Fluent $command)
                    {
                        if (! $this->called) {
                            $this->called = true;
                            try {
                                $this->rows->one('paid_tax_authorizations', 'id = ?', [0]);
                            } catch (PaidTaxException) {
                                $this->refusal = true;
                            }
                        }

                        return parent::compileCreate($blueprint, $command);
                    }
                };
                $connection->setSchemaGrammar($grammar);
                try {
                    $rows->admitDelivery();
                } finally {
                    $connection->setSchemaGrammar($original);
                }

                return [];
            });
        $this->assertTrue($called);
        $this->assertTrue($refusal, 'Public new-table access succeeded before complete native schema admission.');
        $this->assertDatabaseCount('paid_tax_authorizations', 0);
        $this->authorize($f);
        $this->assertDatabaseCount('paid_tax_authorizations', 1);
    }

    public function test_purchased_wav_mp3_and_stems_stream_exact_retained_private_bytes(): void
    {
        $f = $this->allRolesTaxOrder();
        $f['batch'] = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['batch'] = (new PaidTaxDocuments)->prepare($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $d = new PaidTaxDownloads;
        $original = (array) DB::table('paid_tax_originals')->sole();
        $artifact = PaidTaxRecords::decode($original)['artifact'];
        $paths = ['contract' => $artifact['storage_path']];
        foreach (['master_wav', 'download_mp3', 'stems_zip'] as $kind) {
            $paths[$kind] = $f['catalog']['media'][$kind]->storage_path;
        }
        foreach ($paths as $kind => $path) {
            if ($kind === 'contract') {
                continue;
            }
            $a = $this->authorize($f, $kind);
            $bytes = '';
            $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user'])
                ->writeTo(static function (string $chunk) use (&$bytes): void {
                    $bytes .= $chunk;
                });
            $this->assertSame(file_get_contents(Storage::disk('local')->path($path)), $bytes);
        }
        $this->assertSame($original, (array) DB::table('paid_tax_originals')->sole());
        $this->assertDatabaseCount('paid_tax_redemptions', 3);
        $this->assertDatabaseCount('paid_redemptions', 0);
        // Contract PDF is proved by the separate actual-PDF/master journey above.
    }

    public function test_second_original_line_is_independently_authorized_and_wrong_current_owner_refuses(): void
    {
        $f = $this->complete(twoLines: true);
        $other = $this->enrollThroughLocalSmtp('other@example.test');
        $d = new PaidTaxDownloads;
        $this->refused(404, fn () => $d->authorize($f['batch']['id'], $f['batch']['lines'][0]['id'], $this->input($f['batch']['lines'][0], 'master_wav'),
            $other['principal'], $other['user']));
        foreach ($f['batch']['lines'] as $line) {
            $a = $d->authorize($f['batch']['id'], $line['id'], $this->input($line, 'master_wav'), $f['buyer']['principal'], $f['buyer']['user']);
            $received = '';
            $transfer = $d->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $transfer->writeTo(static function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });
            $this->assertSame($transfer->sha256, hash('sha256', $received));
        }
        $status = $d->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertSame([1, 1], array_column($status['lines'], 'attemptCount'));
        $this->assertDatabaseCount('paid_tax_originals', 2);
    }

    public function test_throwing_redemption_after_commit_refuses_transfer_preserves_spent_attempt_and_allows_fresh_command(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f);
        $pdo = DB::getPdo();
        $registered = false;
        $executed = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function ($event) use ($pdo, &$registered, &$executed): void {
            if (! $registered && $event->connection->getEventDispatcher()::class === TaxPaidOriginalCommitDispatcher::class
                && (int) $pdo->query('SELECT COUNT(*) FROM paid_tax_redemptions')->fetchColumn() === 1) {
                $registered = true;
                DB::afterCommit(static function () use (&$executed): void {
                    $executed++;
                    throw new \RuntimeException('Synthetic durable redemption callback failure.');
                });
            }
        });
        try {
            (new PaidTaxDownloads)->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
            $this->fail('An unobserved managed commit returned a transfer.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic durable redemption callback failure.', $error->getMessage());
        }
        $this->assertSame(1, $executed);
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(1, (new PaidTaxDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user'])['lines'][0]['attemptCount']);
        $next = $this->authorize($f);
        $transfer = (new PaidTaxDownloads)->redeem($f['batch']['id'], $next['id'], $next['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $transfer->close();
        $this->assertDatabaseCount('paid_tax_redemptions', 2);
    }

    public static function historySizes(): array
    {
        return ['empty history' => [0], 'latest twenty of twenty one' => [20]];
    }

    #[DataProvider('historySizes')]
    public function test_latest_history_selector_catches_late_append_even_when_empty_or_full(int $size): void
    {
        $f = $this->complete();
        $line = $f['batch']['lines'][0];
        if ($size > 0) {
            $a = $this->authorize($f);
            $template = (array) DB::table('paid_tax_authorizations')->where('public_id', $a['id'])->sole();
            unset($template['id']);
        } else {
            // A valid SQL boundary row, deliberately not a issued token or paid authority.
            $template = ['public_id' => (string) Str::uuid(), 'account_id' => $f['buyer']['principal']->accountId, 'actor_id' => $f['buyer']['user']->id,
                'batch_public_id' => $f['batch']['id'], 'origin_public_id' => $line['id'], 'origin_hash' => $line['originHash'],
                'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'token_hash' => str_repeat('b', 64), 'target' => 'master_wav',
                'payload' => 'SYNTHETIC-LATE-SQL-HISTORY-ONLY', 'payload_hash' => str_repeat('c', 64), 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
                'expires_at' => now()->utc()->addSeconds(120)->format('Y-m-d H:i:s')];
        }
        for ($index = 0; $index < $size; $index++) {
            $row = $template;
            $row['public_id'] = (string) Str::uuid();
            $row['request_key'] = (string) Str::uuid();
            $row['token_hash'] = hash('sha256', 'SYNTHETIC-HISTORY-BOUNDARY-'.$index);
            DB::table('paid_tax_authorizations')->insert($row);
        }
        $pdo = DB::getPdo();
        $appends = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, static function ($event) use ($pdo, $template, &$appends): void {
            if ($appends === 0 && $event->connection->getEventDispatcher()::class === TaxPaidOriginalCommitDispatcher::class) {
                $appends++;
                $row = $template;
                $row['public_id'] = (string) Str::uuid();
                $row['request_key'] = (string) Str::uuid();
                $row['token_hash'] = hash('sha256', 'SYNTHETIC-LATE-HISTORY-APPEND');
                $q = $pdo->prepare('INSERT INTO paid_tax_authorizations ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
                $q->execute(array_values($row));
            }
        });
        $this->refused(403, fn () => (new PaidTaxDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']));
        $this->assertSame(1, $appends);
        $this->assertDatabaseCount('paid_tax_authorizations', $size === 0 ? 0 : 21);
        $status = (new PaidTaxDownloads)->status($f['batch']['id'], $f['buyer']['principal'], $f['buyer']['user']);
        $this->assertCount($size, $status['lines'][0]['history']);
        $this->assertSame(0, $status['lines'][0]['attemptCount']);
    }

    public function test_budget_spent_inside_snapshot_flush_closes_slot_before_fresh_exact_retry(): void
    {
        $f = $this->complete();
        $a = $this->authorize($f);
        $budget = PaidTaxRequestBudget::capture(null);
        $flushed = false;
        $adapter = new class($budget, $flushed) extends PaidTaxPrepareStream
        {
            public function __construct(private PaidTaxRequestBudget $budget, private bool &$flushed) {}

            protected function flush($handle): bool
            {
                $result = parent::flush($handle);
                $this->flushed = true;
                $this->budget->shortenTo(0);

                return $result;
            }
        };
        app()->bind(PaidTaxPrepareStream::class, static fn () => $adapter);
        try {
            (new PaidTaxDownloads)->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user'], requestBudget: $budget);
            $this->fail('Snapshot succeeded after the same live budget was spent in flush.');
        } catch (DeliveryException $error) {
            $this->assertSame('target_unavailable', $error->reason);
        }
        $this->assertTrue($flushed);
        $this->assertDatabaseCount('paid_tax_redemptions', 0);
        app()->bind(PaidTaxPrepareStream::class, PaidTaxPrepareStream::class);
        $transfer = (new PaidTaxDownloads)->redeem($f['batch']['id'], $a['id'], $a['token'], $f['buyer']['principal'], $f['buyer']['user']);
        $bytes = 0;
        $transfer->writeTo(static function (string $chunk) use (&$bytes): void {
            $bytes += strlen($chunk);
        });
        $this->assertSame($transfer->sizeBytes, $bytes);
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
    }
}
