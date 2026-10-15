<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSpool;
use Tests\TestCase;

/** Native temporary-file/lease controls; holder strings are synthetic server-call inputs, not customer authority. */
final class ProductionFreeGrantAccountSpoolTest extends TestCase
{
    private string $directory;

    /** @var list<PreparedDeliveryStream> */
    private array $held = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/free-account-spool-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->held as $stream) {
            $stream->close();
        }
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function prepare(?string $holder, ?callable $beforeFill = null, int $slots = 3): PreparedDeliveryStream
    {
        $bytes = str_repeat('s', 4096);
        $stream = (new ProductionFreeGrantSpool)->prepare($this->directory, $slots, 0, hash('sha256', $bytes),
            strlen($bytes), hrtime(true) + 10_000_000_000, static function ($output) use ($bytes, $beforeFill): void {
                if ($beforeFill !== null) {
                    $beforeFill();
                }
                fwrite($output, $bytes);
            }, $holder);
        $this->held[] = $stream;

        return $stream;
    }

    private function refused(callable $run, string $reason = 'spool_busy'): void
    {
        try {
            $run();
            $this->fail('Scoped spool admission must refuse before copying bytes.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    public function test_one_account_cannot_hold_a_second_slot_and_other_accounts_can(): void
    {
        $account = hash('sha256', 'synthetic-account-a');
        $first = $this->prepare($account);
        $filled = false;
        $this->refused(function () use ($account, &$filled): void {
            $this->prepare($account, static function () use (&$filled): void {
                $filled = true;
            });
        });
        $this->assertFalse($filled);
        $second = $this->prepare(hash('sha256', 'synthetic-account-b'));
        $this->assertSame(str_repeat('s', 4096), stream_get_contents($second->stream()));
        $first->close();
        $this->assertSame(str_repeat('s', 4096), stream_get_contents($this->prepare($account)->stream()));
    }

    public function test_a_held_unscoped_legacy_slot_does_not_claim_a_known_account(): void
    {
        $this->prepare(null);
        $this->refused(fn () => $this->prepare(hash('sha256', 'synthetic-account-a')));
    }

    public function test_a_missing_held_holder_refuses_without_recreating_it(): void
    {
        $this->prepare(hash('sha256', 'synthetic-account-a'));
        $path = $this->directory.'/slot-0.holder';
        if (is_file($path)) {
            unlink($path);
        }
        $this->refused(fn () => $this->prepare(hash('sha256', 'synthetic-account-b')));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_malformed_held_holder_refuses_without_overwriting_it(): void
    {
        $this->prepare(hash('sha256', 'synthetic-account-a'));
        $path = $this->directory.'/slot-0.holder';
        file_put_contents($path, str_repeat('?', 64));
        chmod($path, 0600);
        $this->refused(fn () => $this->prepare(hash('sha256', 'synthetic-account-b')));
        $this->assertSame(str_repeat('?', 64), file_get_contents($path));
    }

    public function test_invalid_and_reserved_holder_values_are_refused_before_fill(): void
    {
        foreach (['bad', str_repeat('0', 64)] as $holder) {
            $filled = false;
            $this->refused(function () use ($holder, &$filled): void {
                $this->prepare($holder, static function () use (&$filled): void {
                    $filled = true;
                });
            }, 'artifact_unavailable');
            $this->assertFalse($filled);
        }
    }

    public function test_different_accounts_still_obey_the_global_slot_cap(): void
    {
        foreach (['a', 'b', 'c'] as $account) {
            $this->prepare(hash('sha256', 'synthetic-account-'.$account));
        }
        $this->refused(fn () => $this->prepare(hash('sha256', 'synthetic-account-d')));
    }

    public function test_reducing_slot_count_still_detects_a_live_account_in_a_higher_slot(): void
    {
        $low = $this->prepare(hash('sha256', 'synthetic-account-b'));
        $middle = $this->prepare(hash('sha256', 'synthetic-account-c'));
        $account = hash('sha256', 'synthetic-account-a');
        $this->prepare($account); // Heldslot2 survives the operator's reduction to one allocatable slot.
        $low->close();
        $middle->close();
        $this->refused(fn () => $this->prepare($account, null, 1));
        $other = $this->prepare(hash('sha256', 'synthetic-account-d'), null, 1);
        $this->assertSame(str_repeat('s', 4096), stream_get_contents($other->stream()));
    }

    public function test_an_unknown_holder_in_a_high_live_slot_refuses_after_slot_count_shrinks(): void
    {
        $low = $this->prepare(hash('sha256', 'synthetic-account-b'));
        $middle = $this->prepare(hash('sha256', 'synthetic-account-c'));
        $this->prepare(hash('sha256', 'synthetic-account-a'));
        $low->close();
        $middle->close();
        unlink($this->directory.'/slot-2.holder');
        $filled = false;
        $this->refused(function () use (&$filled): void {
            $this->prepare(hash('sha256', 'synthetic-account-d'), function () use (&$filled): void {
                $filled = true;
            }, 1);
        });
        $this->assertFalse($filled);
        $this->assertFileDoesNotExist($this->directory.'/slot-2.holder');
    }

    public function test_a_failed_fill_releases_the_account_and_its_owned_slot(): void
    {
        $account = hash('sha256', 'synthetic-account-a');
        $this->refused(fn () => $this->prepare($account, static function (): never {
            throw new \RuntimeException('synthetic fill failure');
        }), 'artifact_unavailable');
        $this->assertSame([], glob($this->directory.'/*.snapshot'));
        $this->assertSame(str_repeat('s', 4096), stream_get_contents($this->prepare($account)->stream()));
    }
}
