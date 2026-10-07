<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/**
 * Test-only, read-only observer of the producer's original commit observer.
 *
 * It never calls a producer method that changes state. Each sample evaluates the three conjuncts of
 * OriginalCommitDispatcher::requireCurrent separately: phase !== 'invalid', hrtime < deadlineNs and
 * "connection dispatcher === observer". Samples come from (a) transaction events dispatched through the
 * observer and (b) an asynchronous SIGUSR1 sampler, because the producer mint itself runs only raw PDO.
 */
final class PaidGrantCommitFrameProbe
{
    /** @var list<array<string, mixed>> */
    public array $samples = [];

    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var list<array<string, mixed>> */
    public array $marks = [];

    private ?Process $ticker = null;

    private ?int $thread = null;

    private bool $active = false;

    public function start(): void
    {
        $this->active = true;
        foreach ([TransactionBeginning::class, TransactionCommitting::class, TransactionCommitted::class, TransactionRolledBack::class] as $class) {
            Event::listen($class, function (object $event) use ($class): void {
                if ($this->active) {
                    $this->events[] = ['event' => class_basename($class), 'ns' => hrtime(true), 'observers' => $this->observe('event')];
                }
            });
        }
        if (DB::getDriverName() === 'mysql') {
            $thread = DB::connection()->getPdo()->query('SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID()')->fetchColumn();
            $this->thread = $thread === false ? null : (int) $thread;
        }
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && function_exists('posix_kill')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGUSR1, function (): void {
                if ($this->active && count($this->samples) < 200000) {
                    foreach ($this->observe('signal') as $sample) {
                        $this->samples[] = $sample;
                    }
                }
            });
            $this->ticker = new Process(['bash', '-c', 'while kill -USR1 '.getmypid().' 2>/dev/null; do sleep 0.002; done']);
            $this->ticker->start();
        }
    }

    public function stop(): void
    {
        $this->active = false;
        $this->ticker?->stop(0);
        $this->ticker = null;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }

    /** Wall/monotonic mark plus, natively, this connection's executed prepared-statement count. */
    public function mark(string $label): void
    {
        $mark = ['label' => $label, 'ns' => hrtime(true), 'utc' => gmdate('Y-m-d H:i:s')];
        if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            $pdo = DB::connection()->getRawPdo();
            if ($pdo instanceof PDO && ! $pdo->inTransaction()) {
                $status = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_stmt_execute','Questions')")->fetchAll(PDO::FETCH_KEY_PAIR);
                $mark['stmt_execute'] = (int) ($status['Com_stmt_execute'] ?? 0);
                $mark['questions'] = (int) ($status['Questions'] ?? 0);
                $mark['thread'] = $this->thread;
            }
        }
        $this->marks[] = $mark;
    }

    /** @return list<array<string, mixed>> */
    public function observerSamples(): array
    {
        $all = $this->samples;
        foreach ($this->events as $event) {
            foreach ($event['observers'] as $sample) {
                $all[] = $sample + ['event' => $event['event']];
            }
        }
        usort($all, fn (array $a, array $b): int => $a['ns'] <=> $b['ns']);

        return $all;
    }

    /** @return list<array<string, mixed>> */
    private function observe(string $via): array
    {
        $now = hrtime(true);
        $found = [];
        $frames = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);
        $stack = array_map(fn (array $frame): string => ($frame['class'] ?? '').'::'.($frame['function'] ?? ''), array_slice($frames, 0, 12));
        foreach ($frames as $frame) {
            $object = $frame['object'] ?? null;
            if (! $object instanceof OriginalCommitDispatcher || isset($found[spl_object_id($object)])) {
                continue;
            }
            $read = fn (string $name): mixed => (new ReflectionProperty(OriginalCommitDispatcher::class, $name))->getValue($object);
            $deadline = $read('deadlineNs');
            $current = $read('context')->connection()->getEventDispatcher();
            $found[spl_object_id($object)] = ['via' => $via, 'ns' => $now, 'observer' => spl_object_id($object), 'frame' => $frame['function'] ?? '',
                'phase' => $read('phase'), 'receipts' => count($read('receipts')), 'deadline_ns' => $deadline, 'remaining_ns' => $deadline - $now,
                'phase_ok' => $read('phase') !== 'invalid', 'deadline_ok' => $now < $deadline, 'dispatcher_ok' => $current === $object,
                'current_dispatcher' => $current instanceof OriginalCommitDispatcher ? 'observer#'.spl_object_id($current) : get_debug_type($current),
                'stack' => $stack];
        }

        return array_values($found);
    }
}
