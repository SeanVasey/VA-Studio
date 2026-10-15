<?php

namespace Tests\Unit;

use App\Domain\Grants\PaidTax\PaidTaxDeadline;
use App\Domain\Grants\PaidTax\PaidTaxException;
use LogicException;
use PHPUnit\Framework\TestCase;

class PaidTaxCapabilityTest extends TestCase
{
    public function test_serialized_private_fields_cannot_construct_a_budget_outside_the_original_factory_cap(): void
    {
        $class = PaidTaxDeadline::class;
        $farEnd = hrtime(true) + 3600_000_000_000;
        $fields = serialize("\0".$class."\0original").serialize($farEnd)
            .serialize("\0".$class."\0deadline").serialize($farEnd);
        $serialized = 'O:'.strlen($class).':"'.$class.'":2:{'.$fields.'}';
        $this->expectException(LogicException::class);
        unserialize($serialized, ['allowed_classes' => [$class]]);
    }

    public function test_original_deadline_cannot_be_extended_after_shortening(): void
    {
        $budget = PaidTaxDeadline::start();
        $end = hrtime(true) - 1;
        $budget->shortenTo($end);
        $budget->shortenTo(hrtime(true) + 300_000_000_000);
        $this->assertSame($end, $budget->value());
        try {
            $budget->proveCurrent();
            $this->fail('A shortened expired original budget was renewed.');
        } catch (PaidTaxException $error) {
            $this->assertSame(410, $error->status);
        }
    }

    public function test_an_explicit_budget_cannot_exceed_300_seconds(): void
    {
        $this->expectException(PaidTaxException::class);
        PaidTaxDeadline::start(301);
    }
}
