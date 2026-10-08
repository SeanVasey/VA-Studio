<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Entitlement refusal matrix for one-use short-lived owner authorizations. No HTTP route is mounted here. */
final class ProductionFreeGrantDeliveryTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private ProductionFreeGrantDownloads $downloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->downloads = new ProductionFreeGrantDownloads;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_authorization_retains_only_the_token_hash_and_redemption_is_one_use(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $authorization = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'stems_zip'], $owner['principal'], $owner['user']);
        $row = (array) DB::table('production_free_authorizations')->first();
        $this->assertSame(hash('sha256', $authorization['token']), $row['token_hash']);
        $this->assertStringNotContainsString($authorization['token'], json_encode(DB::table('production_free_authorizations')->get()));
        $this->assertSame('application/zip', $authorization['mimeType']);
        $transfer = $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']);
        $this->assertSame($this->sources->bytes['synthetic-stems_zip'], $this->bytes($transfer));
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'already_redeemed');
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
    }

    public function test_original_must_exist_before_any_artifact_is_authorized(): void
    {
        [$owner, $origin] = $this->granted();
        $seal = DB::table('production_free_origins')->value('seal');
        foreach (['contract', 'master_wav'] as $role) {
            $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $owner['user']), 'original_pending');
        }
        $this->assertSame(0, DB::table('production_free_authorizations')->count());
    }

    public function test_unapproved_role_stale_seal_and_loose_input_are_refused(): void
    {
        [$owner, $origin, $seal] = $this->delivered(['assets' => null]);
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'stems_zip'], $owner['principal'], $owner['user']), 'not_entitled');
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => str_repeat('0', 64), 'role' => 'master_wav'], $owner['principal'], $owner['user']), 'stale_origin');
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'preview_mp3'], $owner['principal'], $owner['user']), 'invalid_input');
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav', 'extra' => 1], $owner['principal'], $owner['user']), 'invalid_input');
        $this->assertSame(0, DB::table('production_free_authorizations')->count());
    }

    public function test_foreign_owner_wrong_token_and_expired_authorization_are_refused(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $authorization = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
        $other = $this->customer('other@example.test');
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $other['principal'], $other['user']), 'not_found');
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $other['principal'], $other['user']), 'not_found');
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], strrev($authorization['token']), $owner['principal'], $owner['user']), 'token_refused');
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], 'short', $owner['principal'], $owner['user']), 'token_refused');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(301));
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'expired');
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
    }

    public function test_revocation_stops_new_and_outstanding_authorizations_but_keeps_history(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $authorization = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']);
        $this->bytes($this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']));
        $outstanding = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'download_mp3'], $owner['principal'], $owner['user']);
        (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic rehearsal revocation.'], $this->staff());
        $this->refuses(fn () => $this->downloads->redeem($outstanding['id'], $outstanding['token'], $owner['principal'], $owner['user']), 'revoked');
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']), 'revoked');
        $this->assertSame(1, DB::table('production_free_redemptions')->count());
        $this->assertSame(1, DB::table('production_free_originals')->count());
    }

    public function test_drifted_private_bytes_are_refused_before_the_first_byte_and_record_no_attempt(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        $authorization = $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'master_wav'], $owner['principal'], $owner['user']);
        $good = $this->sources->bytes['synthetic-master_wav'];
        $this->sources->bytes['synthetic-master_wav'] = strrev($good);
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'artifact_drift');
        $this->sources->bytes['synthetic-master_wav'] = $good.'x';
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'artifact_unavailable');
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
        $this->sources->bytes['synthetic-master_wav'] = $good;
        $this->assertSame($good, $this->bytes($this->downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user'])));
    }

    public function test_disabled_policy_and_burst_rate_are_refused(): void
    {
        [$owner, $origin, $seal] = $this->delivered();
        for ($i = 0; $i < 5; $i++) {
            $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']);
        }
        $this->refuses(fn () => $this->downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => 'contract'], $owner['principal'], $owner['user']), 'rate_limited');
        $authorization = (array) DB::table('production_free_authorizations')->first();
        config(['production-free-grants.enabled' => false]);
        $this->refuses(fn () => $this->downloads->redeem($authorization['id'], str_repeat('A', 43), $owner['principal'], $owner['user']), 'disabled');
    }

    public function test_the_original_authorization_deadline_bounds_the_stream(): void
    {
        $file = fopen('php://temp', 'w+b');
        fwrite($file, 'synthetic');
        $transfer = new ProductionFreeGrantTransfer(new PreparedDeliveryStream($file, hash('sha256', 'synthetic'), 9), 'x.bin', 'application/octet-stream', hrtime(true) - 1);
        $received = '';
        $this->refuses(function () use ($transfer, &$received): void {
            $transfer->writeTo(function (string $chunk) use (&$received): void {
                $received .= $chunk;
            });
        }, 'expired');
        $this->assertSame('', $received);
        $this->assertFalse(is_resource($file));
    }

    private function granted(array $overrides = []): array
    {
        $definition = $this->openDefinition(overrides: $overrides);
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return [$owner, $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user'])];
    }

    private function delivered(array $overrides = []): array
    {
        if (array_key_exists('assets', $overrides) && $overrides['assets'] === null) {
            $overrides['assets'] = [$this->definitionInput()['assets'][0]];
        }
        [$owner, $origin] = $this->granted($overrides);
        (new ProductionFreeGrantDocuments)->render($origin['id']);

        return [$owner, $origin, (new ProductionFreeGrantLibrary)->show($origin['id'], $owner['principal'], $owner['user'])['originSeal']];
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
