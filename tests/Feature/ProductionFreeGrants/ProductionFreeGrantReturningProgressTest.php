<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Delivery\PreparedDeliveryStream;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use Tests\TestCase;

/** Returning-consumer controls only; no blocked SAPI/write or database proof. */
final class ProductionFreeGrantReturningProgressTest extends TestCase
{
    private int $tick = 0;

    private mixed $input = null;

    private function transfer(string $bytes, int $deadlineSeconds = 200): ProductionFreeGrantTransfer
    {
        $this->input = fopen('php://temp', 'w+b');
        fwrite($this->input, $bytes);
        rewind($this->input);
        $snapshot = new PreparedDeliveryStream($this->input, hash('sha256', $bytes), strlen($bytes));

        return new ProductionFreeGrantTransfer($snapshot, 'synthetic.bin', 'application/octet-stream',
            $deadlineSeconds * 1_000_000_000, fn (): int => $this->tick,
            0, 16384, 30);
    }

    private function expired(callable $run): void
    {
        try {
            $run();
            $this->fail('The original deadline/progress allowance must refuse late delivery.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('expired', $error->reason);
        }
        $this->assertFalse(is_resource($this->input), 'Every refused returning-write path closes its snapshot.');
    }

    public function test_waiting_before_the_first_write_does_not_renew_grace(): void
    {
        $transfer = $this->transfer(str_repeat('p', 1048576));
        $this->tick = 31_000_000_000;
        $sent = 0;
        $this->expired(fn () => $transfer->writeTo(function (string $chunk) use (&$sent): void {
            $sent += strlen($chunk);
        }));
        $this->assertSame(0, $sent);
    }

    public function test_a_returning_consumer_below_the_original_rate_is_refused(): void
    {
        $transfer = $this->transfer(str_repeat('p', 2097152));
        $sent = 0;
        $this->expired(fn () => $transfer->writeTo(function (string $chunk) use (&$sent): void {
            $sent += strlen($chunk);
            $this->tick = 100_000_000_000; // 1MiB needs <=30+64 seconds at16KiB/s.
        }));
        $this->assertSame(1048576, $sent, 'No second chunk leaves after the returning consumer misses progress.');
    }

    public function test_the_final_returning_callback_cannot_outlast_the_original_deadline(): void
    {
        $transfer = $this->transfer('private synthetic bytes');
        $this->expired(fn () => $transfer->writeTo(function (): void {
            $this->tick = 201_000_000_000;
        }));
    }

    public function test_the_final_returning_callback_also_obeys_minimum_progress(): void
    {
        $transfer = $this->transfer('small');
        $this->expired(fn () => $transfer->writeTo(function (): void {
            $this->tick = 31_000_000_000;
        }));
    }

    public function test_exact_progress_boundary_and_healthy_delivery_complete(): void
    {
        $bytes = str_repeat('p', 2097152);
        $transfer = $this->transfer($bytes);
        $sent = '';
        $transfer->writeTo(function (string $chunk) use (&$sent): void {
            $sent .= $chunk;
            $this->tick = (30 + intdiv(strlen($sent), 16384)) * 1_000_000_000;
        });
        $this->assertSame($bytes, $sent);
        $this->assertFalse(is_resource($this->input));
    }

    public function test_a_consumer_throw_closes_without_claiming_acceptance(): void
    {
        $transfer = $this->transfer('private synthetic bytes');
        try {
            $transfer->writeTo(static function (): never {
                throw new \RuntimeException('Synthetic returning consumer failure.');
            });
            $this->fail('The consumer failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic returning consumer failure.', $error->getMessage());
        }
        $this->assertFalse(is_resource($this->input));
    }
}
