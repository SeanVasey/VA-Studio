<?php

namespace Tests\Feature;

use App\Domain\Grants\PaidTax\PaidTaxDocuments;
use App\Domain\Grants\PaidTax\PaidTaxDownloads;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use App\Domain\Grants\PaidTax\PaidTaxSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\TestCase;

/** Real retained synthetic paid order and private original; no live provider/host claim. */
class PaidTaxDownloadJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
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
}
