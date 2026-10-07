<?php

namespace Tests\Feature;

use App\Domain\Contracts\ContractFiles;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Domain\Grants\Free\FreeGrantDocuments;
use App\Domain\Grants\Free\FreeGrantDownloads;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantRecords;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FreeGrantFixtures;
use Tests\TestCase;

final class FreeGrantDownloadsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function ready(): array
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $f = FreeGrantFixtures::source();
        $d = FreeGrantFixtures::publish($f);
        $origin = FreeGrantFixtures::accept($f, $d)['origin'];
        $origin = (new FreeGrantDocuments)->issue($origin['id'], $origin['originHash'], $f['customer']['principal'], $f['customer']['user']);

        return $f + compact('origin');
    }

    private function authorize(array $f, string $kind = 'contract'): array
    {
        return (new FreeGrantDownloads)->authorize($f['origin']['id'], ['requestKey' => (string) Str::uuid(), 'originHash' => $f['origin']['originHash'], 'kind' => $kind, 'nonce' => bin2hex(random_bytes(32))], $f['customer']['principal'], $f['customer']['user']);
    }

    private function refuse(callable $command, int $status): void
    {
        try {
            $command();
            $this->fail('Free delivery authority must refuse.');
        } catch (FreeGrantException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    public function test_exact_authorization_expiry_cross_owner_replay_and_total_attempt_cap_preserve_same_original(): void
    {
        $f = $this->ready();
        $downloads = new FreeGrantDownloads;
        $originBefore = (array) DB::table('free_origins')->sole();
        $originalBefore = (array) DB::table('free_originals')->sole();
        $expired = $this->authorize($f);
        $this->travel(60)->seconds();
        $this->refuse(fn () => $downloads->redeem($expired['id'], $expired['token'], $f['customer']['principal'], $f['customer']['user']), 410);
        $this->assertDatabaseCount('free_redemptions', 0);
        $other = CustomerFixtures::account();
        $auth = $this->authorize($f, 'master_wav');
        $this->refuse(fn () => $downloads->redeem($auth['id'], $auth['token'], $other['principal'], $other['user']), 404);
        $this->refuse(fn () => $downloads->redeem($auth['id'], str_repeat('z', 43), $f['customer']['principal'], $f['customer']['user']), 403);
        $transfer = $downloads->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user']);
        $bytes = '';
        $transfer->stream->writeTo(function ($chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });
        $this->assertSame($f['assets']['master_wav']->sha256, hash('sha256', $bytes));
        $this->assertSame('audio/wav', $transfer->mimeType);
        $this->refuse(fn () => $downloads->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user']), 409);
        foreach (['contract', 'contract'] as $kind) {
            $next = $this->authorize($f, $kind);
            $downloads->redeem($next['id'], $next['token'], $f['customer']['principal'], $f['customer']['user'])->stream->close();
        }
        $this->refuse(fn () => $this->authorize($f), 409);
        $this->assertDatabaseCount('free_redemptions', 3);
        $this->assertSame($originBefore, (array) DB::table('free_origins')->sole());
        $this->assertSame($originalBefore, (array) DB::table('free_originals')->sole());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_actual_descriptor_preparation_credential_withdrawal_closes_held_stream_without_committed_attempt(): void
    {
        $f = $this->ready();
        $auth = $this->authorize($f);
        $before = DB::table('free_origins')->get()->toJson();
        $streams = new class($f['customer']['user']->id) extends PrepareTestDeliveryStream
        {
            public ?PreparedDeliveryStream $held = null;

            public function __construct(private int $userId) {}

            public function handle(array $target): PreparedDeliveryStream
            {
                $this->held = parent::handle($target);
                DB::table('users')->where('id', $this->userId)->update(['password' => 'ACTUAL-OUTSIDE-IO-WITHDRAWAL']);

                return $this->held;
            }
        };
        app()->instance(PrepareTestDeliveryStream::class, $streams);
        try {
            (new FreeGrantDownloads)->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user']);
            $this->fail('Withdrawn original credential cannot consume a stream.');
        } catch (CustomerAccessException|FreeGrantException) {
            $this->assertNotNull($streams->held);
            $this->expectException(DeliveryException::class);
            try {
                $this->assertDatabaseCount('free_redemptions', 0);
                $this->assertSame($before, DB::table('free_origins')->get()->toJson());
            } finally {
                $streams->held->stream();
            }
        }
    }

    public function test_missing_completed_original_is_restore_only_never_recreated_or_overwritten(): void
    {
        $f = $this->ready();
        $record = (array) DB::table('free_originals')->sole();
        $manifest = FreeGrantRecords::decode($record);
        $bytes = (new ContractFiles)->verify($manifest['artifact']);
        $path = Storage::disk('local')->path($manifest['artifact']['storage_path']);
        unlink($path);
        $auth = $this->authorize($f);
        try {
            (new FreeGrantDownloads)->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user']);
            $this->fail('Missing original cannot be streamed.');
        } catch (DeliveryException) {
            $this->assertDatabaseCount('free_redemptions', 0);
        }
        $this->assertSame('complete', (new FreeGrantDocuments)->issue($f['origin']['id'], $f['origin']['originHash'], $f['customer']['principal'], $f['customer']['user'])['documentStatus']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame($record, (array) DB::table('free_originals')->sole());
        file_put_contents($path, $bytes);
        chmod($path, 0400);
        $transfer = (new FreeGrantDownloads)->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user']);
        $restored = '';
        $transfer->stream->writeTo(function ($chunk) use (&$restored): void {
            $restored .= $chunk;
        });
        $this->assertSame($bytes, $restored);
        $this->assertSame($record, (array) DB::table('free_originals')->sole());
    }

    public function test_every_original_sql_row_refuses_update_delete_ignore_and_replace_without_losing_originals(): void
    {
        $f = $this->ready();
        $auth = $this->authorize($f);
        (new FreeGrantDownloads)->redeem($auth['id'], $auth['token'], $f['customer']['principal'], $f['customer']['user'])->stream->close();
        $tables = ['free_definitions', 'free_reviews', 'free_availability', 'free_origins', 'free_document_work', 'free_originals', 'free_authorizations', 'free_redemptions'];
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson(), $tables);
        $before = $snapshot();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers = OFF');
        }
        foreach ($tables as $table) {
            $row = (array) DB::table($table)->first();
            $grammar = DB::connection()->getQueryGrammar();
            foreach ([fn () => DB::table($table)->where('id', $row['id'])->update(['created_at' => '2099-01-01 00:00:00']),
                fn () => DB::table($table)->where('id', $row['id'])->delete(), fn () => DB::table($table)->insertOrIgnore($row),
                fn () => DB::insert('REPLACE INTO '.$grammar->wrapTable($table).' ('.implode(',', array_map($grammar->wrap(...), array_keys($row))).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row))] as $mutation) {
                try {
                    $mutation();
                    $this->fail('SQL cannot erase or replace a retained original.');
                } catch (QueryException) {
                    $this->assertSame($before, $snapshot());
                }
            }
        }
    }
}
