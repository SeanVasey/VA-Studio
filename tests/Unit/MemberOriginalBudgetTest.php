<?php

namespace Tests\Unit;

use App\Domain\Grants\Member\MemberGrantException;
use App\Domain\Grants\Member\MemberOriginalBudget;
use PHPUnit\Framework\TestCase;

class MemberOriginalBudgetTest extends TestCase
{
    public function test_one_native_monotonic_workflow_cap_can_only_shorten(): void
    {
        $before = hrtime(true);
        $budget = MemberOriginalBudget::start();
        $after = hrtime(true);
        $this->assertGreaterThanOrEqual($before + 300_000_000_000, $budget->deadlineNs());
        $this->assertLessThanOrEqual($after + 300_000_000_000, $budget->deadlineNs());
        $original = $budget->deadlineNs();
        $budget->shortenTo(PHP_INT_MAX);
        $this->assertSame($original, $budget->deadlineNs());
        $budget->shortenTo($original - 60_000_000_000);
        $budget->assertCurrent();
        $this->assertSame($original - 60_000_000_000, $budget->deadlineNs());
        $budget->shortenTo($before);
        $budget->shortenTo($original);
        $this->assertSame($before, $budget->deadlineNs());
        $this->expectExceptionObject(new MemberGrantException('workflow_deadline'));
        $budget->assertCurrent();
    }

    public function test_budget_cannot_be_copied_or_serialized_into_another_workflow(): void
    {
        $budget = MemberOriginalBudget::start();
        foreach ([fn () => clone $budget, fn () => serialize($budget), fn () => json_encode($budget)] as $copy) {
            try {
                $copy();
                $this->fail('A copied budget must not be admitted.');
            } catch (MemberGrantException $error) {
                $this->assertSame('private_budget', $error->reason);
            }
        }
    }

    public function test_budget_cannot_be_restored_with_a_caller_selected_deadline(): void
    {
        $name = MemberOriginalBudget::class;
        $private = "\0".$name."\0deadline";
        $payload = 'O:'.strlen($name).':"'.$name.'":1:{s:'.strlen($private).':"'.$private.'";i:'.PHP_INT_MAX.';}';
        $this->expectExceptionObject(new MemberGrantException('private_budget'));
        unserialize($payload);
    }
}
