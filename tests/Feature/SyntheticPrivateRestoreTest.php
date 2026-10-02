<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\Contracts\ContractFiles;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\Models\ContractRenderRequest;
use App\Domain\Contracts\Models\GrantContract;
use App\Domain\Contracts\ReadGrantContract;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryAssets;
use App\Domain\Delivery\DeliveryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ActivationFixtures;
use Tests\Support\ContractFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Offline synthetic SQLite rehearsal, even in the MySQL test job. It accepts no
 * database/path input and never opens the configured application database.
 * Production MySQL/object storage, backup encryption and retention are separate gates.
 */
class SyntheticPrivateRestoreTest extends TestCase
{
    private string $workspace;
    private ContractRenderer $renderer;
    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing')) { throw new \LogicException('Restore rehearsal is test-only.'); }

        $this->workspace = storage_path('framework/testing/restore-'.Str::uuid());
        $this->assertTrue(mkdir($this->workspace, 0700, true));
        $previousConnection = DB::getDefaultConnection();
        $this->beforeApplicationDestroyed(function () use ($previousConnection): void {
            DB::purge('rehearsal_source');
            DB::purge('rehearsal_restored');
            DB::setDefaultConnection($previousConnection);
            (new Filesystem)->deleteDirectory($this->workspace);
        });

        // Ignore DB_URL and every ambient database credential. Never migrate:fresh.
        $source = $this->workspace.'/source.sqlite';
        $this->assertTrue(touch($source));
        $this->assertTrue(chmod($source, 0600));
        $this->connection('rehearsal_source', $source);
        $this->artisan('migrate', ['--database' => 'rehearsal_source', '--force' => true])->assertSuccessful();
        $this->privateRoot($this->workspace.'/source-private');
        // The synthetic backup keeps its test key in this process only, never in a receipt/file.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        ContractFixtures::configure();
        $this->gateway = PaymentFixtures::gateway();
        $this->renderer = ContractFixtures::renderer();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
        $this->app->instance(ContractRenderer::class, $this->renderer);
    }

    public function test_snapshot_and_separate_private_copies_reopen_exact_purchased_evidence_and_ownership(): void
    {
        $rehearsal = $this->restoreSyntheticPurchase();
        $this->assertRestoredEvidence($rehearsal);
        $this->assertSame($rehearsal['presentation'], app(ReadOrder::class)->handle($rehearsal['order_id'], $rehearsal['owner']));
        try {
            app(ReadOrder::class)->handle($rehearsal['order_id'], str_repeat('b', 64));
            $this->fail('A different owner read the restored purchase.');
        } catch (QuoteException $error) {
            $this->assertSame('ORDER_NOT_FOUND', $error->errorCode);
            $this->assertSame(404, $error->status);
        }
        $this->assertNoNewEffects($rehearsal);
    }

    public static function damagedCopies(): array
    {
        return ['missing original' => ['original', false], 'same-size corrupt original' => ['original', true],
            'missing purchased revision' => ['asset', false], 'same-size corrupt purchased revision' => ['asset', true]];
    }

    #[DataProvider('damagedCopies')]
    public function test_missing_or_corrupt_restored_bytes_fail_without_regeneration_or_new_rights(string $kind, bool $corrupt): void
    {
        $rehearsal = $this->restoreSyntheticPurchase();
        $this->assertRestoredEvidence($rehearsal);
        $record = $kind === 'original' ? $rehearsal['contracts'][0] : $rehearsal['assets'][0];
        $path = $this->workspace.'/restored-private/'.$record['storage_path'];
        if ($corrupt) {
            $bytes = file_get_contents($path);
            $bytes[12] = $bytes[12] === 'X' ? 'Y' : 'X';
            $this->assertTrue(chmod($path, 0600));
            $this->assertSame($record['size_bytes'], file_put_contents($path, $bytes));
            $this->assertTrue(chmod($path, 0400));
        } else {
            $this->assertTrue(unlink($path));
        }
        try {
            if ($kind === 'original') { app(ContractFiles::class)->verify($record); }
            else { app(DeliveryAssets::class)->verify($rehearsal['assets']); }
            $this->fail('Missing or corrupt restored bytes passed verification.');
        } catch (ContractIssuanceException|DeliveryException $error) {
            $this->assertSame($kind === 'original' ? 'original_unavailable' : 'asset_unavailable', $error->reason);
        }
        $this->assertNoNewEffects($rehearsal);

        // Recover only by copying the archived original, without invoking the renderer.
        if ($corrupt) { $this->assertTrue(unlink($path)); }
        $this->copySealed($this->workspace.'/backup-private/'.$record['storage_path'], $path);
        $this->assertRestoredEvidence($rehearsal);
        $this->assertNoNewEffects($rehearsal);
    }

    private function restoreSyntheticPurchase(): array
    {
        // Paid exclusive + nonexclusive fixtures; issuing originals does not activate delivery.
        $fixture = ActivationFixtures::issued($this->gateway, true);
        $order = $fixture['order'];
        $original = app(ReadOrder::class)->verify($order);
        $assets = app(DeliveryAssets::class)->inspect($original);
        $contracts = GrantContract::orderBy('id')->get()->map->getAttributes()->all();
        $this->assertCount(2, $contracts);
        $this->assertCount(2, $assets);
        $presentation = app(ReadOrder::class)->handle($order->public_id, $order->owner_key);
        $inventory = $this->databaseInventory();
        $providerCalls = $this->gateway->calls;
        $renderCalls = $this->renderer->calls;
        $this->assertCount(2, $renderCalls);
        $this->assertSame(0, DB::transactionLevel());

        // A real SQLite snapshot, not a serialized model array or an in-place file repair.
        $snapshot = $this->workspace.'/backup.sqlite';
        $pdo = DB::connection()->getPdo();
        $this->assertNotFalse($pdo->exec('VACUUM INTO '.$pdo->quote($snapshot)));
        $this->assertTrue(chmod($snapshot, 0600));
        $backupHash = hash_file('sha256', $snapshot);
        foreach ($contracts as $contract) {
            $bytes = app(ContractFiles::class)->verify($contract);
            $target = $this->workspace.'/backup-private/'.$contract['storage_path'];
            $output = $this->newPrivateFile($target);
            try { $this->assertSame(strlen($bytes), fwrite($output, $bytes)); }
            finally { fclose($output); }
            $this->assertTrue(chmod($target, 0400));
            $this->assertSame($contract['pdf_hash'], hash_file('sha256', $target));
        }
        foreach ($assets as $asset) {
            $target = $this->workspace.'/backup-private/'.$asset['storage_path'];
            $output = $this->newPrivateFile($target);
            try { app(DeliveryAssetFiles::class)->copyVerified($asset, $output); }
            finally { fclose($output); }
            $this->assertTrue(chmod($target, 0400));
            $this->assertSame($asset['sha256'], hash_file('sha256', $target));
        }

        $restoredDatabase = $this->workspace.'/restored.sqlite';
        $this->assertTrue(copy($snapshot, $restoredDatabase));
        $this->assertTrue(chmod($restoredDatabase, 0600));
        $this->assertSame($backupHash, hash_file('sha256', $restoredDatabase));
        foreach ([...$contracts, ...$assets] as $record) {
            $this->copySealed($this->workspace.'/backup-private/'.$record['storage_path'],
                $this->workspace.'/restored-private/'.$record['storage_path']);
        }

        // No live source connection/file/object may satisfy a post-restore read.
        unset($pdo, $fixture, $order);
        DB::purge('rehearsal_source');
        $this->assertTrue(unlink($this->workspace.'/source.sqlite'));
        $this->assertTrue((new Filesystem)->deleteDirectory($this->workspace.'/source-private'));
        $this->connection('rehearsal_restored', $restoredDatabase);
        DB::statement('PRAGMA query_only = ON');
        $this->privateRoot($this->workspace.'/restored-private');
        $this->assertSame($restoredDatabase, DB::selectOne('PRAGMA database_list')->file);
        $this->assertSame(1, DB::selectOne('PRAGMA query_only')->query_only);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame($inventory, $this->databaseInventory());

        return ['order_id' => $original['order_id'], 'owner' => $original['owner_key'],
            'original' => $original, 'presentation' => $presentation, 'contracts' => $contracts, 'assets' => $assets,
            'inventory' => $inventory, 'provider_calls' => $providerCalls, 'render_calls' => $renderCalls,
            'backup_hash' => $backupHash];
    }

    private function assertRestoredEvidence(array $rehearsal): void
    {
        $order = Order::where('public_id', $rehearsal['order_id'])->sole();
        $original = app(ReadOrder::class)->verify($order);
        $this->assertSame($rehearsal['original'], $original);
        $assets = app(DeliveryAssets::class)->inspect($original);
        $this->assertSame($rehearsal['assets'], $assets);
        app(DeliveryAssets::class)->verify($assets);
        foreach ($rehearsal['contracts'] as $expected) {
            $request = ContractRenderRequest::findOrFail($expected['contract_render_request_id']);
            $restored = app(ReadGrantContract::class)->forRequest($request);
            $this->assertNotNull($restored);
            $this->assertSame($expected, $restored->getAttributes());
            $bytes = app(ContractFiles::class)->verify($restored);
            $this->assertSame($expected['pdf_hash'], hash('sha256', $bytes));
            $this->assertSame($expected['size_bytes'], strlen($bytes));
        }
    }

    private function assertNoNewEffects(array $rehearsal): void
    {
        $this->assertSame($rehearsal['inventory'], $this->databaseInventory());
        $this->assertSame($rehearsal['provider_calls'], $this->gateway->calls);
        $this->assertSame($rehearsal['render_calls'], $this->renderer->calls);
        $this->assertSame(['pending'], DB::table('pending_entitlements')->distinct()->pluck('state')->all());
        foreach (['test_fulfillment_activations', 'test_delivery_controls', 'test_delivery_authorizations', 'test_delivery_redemptions'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame($rehearsal['backup_hash'], hash_file('sha256', $this->workspace.'/backup.sqlite'));
        $this->assertSame($rehearsal['backup_hash'], hash_file('sha256', $this->workspace.'/restored.sqlite'));
    }

    private function connection(string $name, string $database): void
    {
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'url' => null, 'database' => $database,
            'prefix' => '', 'foreign_key_constraints' => true, 'journal_mode' => 'DELETE', 'transaction_mode' => 'DEFERRED']]);
        DB::setDefaultConnection($name);
    }

    private function privateRoot(string $root): void
    {
        if (! is_dir($root)) { $this->assertTrue(mkdir($root, 0700, true)); }
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
    }

    /** Whole schema plus exact row counts/digests, including SQLite sequence state. */
    private function databaseInventory(): array
    {
        $pdo = DB::connection()->getPdo();
        $schema = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC);
        $tables = [];
        foreach ($schema as $entry) {
            if ($entry['type'] !== 'table') { continue; }
            $rows = $pdo->query('SELECT * FROM "'.str_replace('"', '""', $entry['name']).'"')->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(fn (array $row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded, SORT_STRING);
            $tables[$entry['name']] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR))];
        }

        return ['schema_sha256' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)), 'tables' => $tables];
    }

    private function newPrivateFile(string $path)
    {
        if (! is_dir(dirname($path))) { $this->assertTrue(mkdir(dirname($path), 0700, true)); }
        $mask = umask(0077);
        try { $output = fopen($path, 'x+b'); }
        finally { umask($mask); }
        $this->assertIsResource($output);

        return $output;
    }

    private function copySealed(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        $this->assertIsResource($input);
        $output = $this->newPrivateFile($target);
        try { $this->assertSame(filesize($source), stream_copy_to_stream($input, $output)); }
        finally { fclose($input); fclose($output); }
        $this->assertTrue(chmod($target, 0400));
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $target));
    }
}
