<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRows;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * F-8: row seals, request-key hashes and payload ciphertext are written under the current `app.key`. A routine
 * rotation that lists the old key in `app.previous_keys` must keep every row readable; a key in neither list must
 * still read as tampered.
 */
final class ProductionFreeGrantKeyRotationTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private string $keyA;

    private array $definition;

    private array $owner;

    private array $origin;

    private array $input;

    private array $authorization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->keyA = (string) config('app.key');
        $this->definition = $this->openDefinition();
        $this->owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($this->definition['id'], 'Declared Synthetic Buyer', $this->owner['principal'], $this->owner['user']);
        $this->input = $this->assentInput($review);
        $this->origin = $grants->accept($this->definition['id'], $this->input, $this->owner['principal'], $this->owner['user']);
        (new ProductionFreeGrantDocuments)->render($this->origin['id']);
        $seal = (new ProductionFreeGrantLibrary)->show($this->origin['id'], $this->owner['principal'], $this->owner['user'])['originSeal'];
        $this->authorization = (new ProductionFreeGrantDownloads)->authorize($this->origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'],
            $this->owner['principal'], $this->owner['user']);
    }

    public function test_rows_written_under_the_old_key_stay_readable_when_it_is_a_previous_key(): void
    {
        $this->rotate(previous: [$this->keyA]);

        $definitions = new ProductionFreeGrantDefinitions;
        $this->assertSame($this->definition['definitionHash'], $definitions->read($this->definition['id'], $this->staff())['definitionHash']);
        $graph = $this->originGraph();
        $this->assertSame($this->origin['id'], $graph['origin']['id']);
        $this->assertNotSame([], $graph['original']);
        $this->assertTrue((new ProductionFreeGrantDocuments)->recover($this->origin['id'])['identical']);
        $this->assertSame($this->authorization['id'], $this->authorizationRow()['id']);

        // The request-key hash stored under the old key is found by the replay lookup, which lists every key.
        $stored = DB::table('production_free_origins')->value('request_key_hash');
        $hashes = (new ReflectionMethod(ProductionFreeGrants::class, 'requestKeyHashes'))->invoke(new ProductionFreeGrants, (int) DB::table('production_free_origins')->value('account_id'), $this->input['requestKey']);
        $this->assertCount(2, $hashes);
        $this->assertNotSame($stored, $hashes[0]);
        $this->assertSame($stored, $hashes[1]);

        // New rows are sealed with the current key; old rows keep the seal they were written with.
        $revoked = (new ProductionFreeGrants)->revoke($this->origin['id'], ['originSeal' => $graph['origin']['seal'], 'reason' => 'Synthetic revocation after rotation.'], $this->staff());
        $this->assertTrue($revoked['revoked']);
        $row = (array) DB::table('production_free_revocations')->first();
        $this->assertSame(ProductionFreeGrantRecords::seal('production_free_revocations', $row), $row['seal']);
        $old = (array) DB::table('production_free_authorizations')->first();
        $this->assertNotSame(ProductionFreeGrantRecords::seal('production_free_authorizations', $old), $old['seal']);
        $this->assertSame($old['seal'], ProductionFreeGrantRecords::seal('production_free_authorizations', $old, $this->keyA));
    }

    public function test_rows_sealed_under_a_key_in_neither_list_are_refused(): void
    {
        foreach ([[], ['base64:'.base64_encode(str_repeat('Z', 32))]] as $previous) {
            $this->rotate(previous: $previous);
            $this->refuses(fn () => (new ProductionFreeGrantDefinitions)->read($this->definition['id'], $this->staff()));
            $this->refuses(fn () => $this->originGraph());
            $this->refuses(fn () => (new ProductionFreeGrantDocuments)->recover($this->origin['id']));
            $this->refuses(fn () => $this->authorizationRow());
            $this->refuses(fn () => (new ProductionFreeGrants)->revoke($this->origin['id'], ['originSeal' => str_repeat('0', 64), 'reason' => 'Synthetic.'], $this->staff()));
        }
        $this->assertSame(0, DB::table('production_free_revocations')->count());
    }

    public function test_only_well_formed_distinct_keys_are_candidates_and_the_current_key_is_first(): void
    {
        $current = 'base64:'.base64_encode(str_repeat('G', 32));
        config(['app.key' => $current, 'app.previous_keys' => ['', 'short', $this->keyA, $current, $this->keyA]]);
        $this->assertSame([$current, $this->keyA], ProductionFreeGrantRecords::keys());
        config(['app.key' => 'short']);
        $this->refuses(fn () => ProductionFreeGrantRecords::keys(), 'key_absent');
    }

    private function originGraph(): array
    {
        return DB::transaction(fn (): array => (new ProductionFreeGrants)->originGraph($this->origin['id'], (int) DB::table('production_free_origins')->value('account_id'), new ProductionFreeGrantRows));
    }

    private function authorizationRow(): array
    {
        return DB::transaction(fn (): array => (new ProductionFreeGrantRows)->one('production_free_authorizations', 'id = ?', [$this->authorization['id']]));
    }

    private function rotate(array $previous): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('G', 32)), 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function refuses(callable $operation, string $reason = 'tampered'): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
