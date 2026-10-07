<?php

namespace Tests\Unit;

use App\Domain\Services\Projects\ServiceProjectInput;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

class ServiceProjectInputTest extends TestCase
{
    public static function invalidQuotes(): array
    {
        return ['float total' => ['totalMinor', 175.5], 'implicit deposit' => ['depositMinor', null], 'excess deposit' => ['depositMinor', 20000],
            'negative total' => ['totalMinor', -1], 'numeric string' => ['totalMinor', '17500'], 'implicit currency' => ['currency', null],
            'lowercase currency' => ['currency', 'usd'], 'excess revisions' => ['revisionAllowance', 21], 'missing scope' => ['scope', ''],
            'unsupported paid authority' => ['paid', true], 'missing milestones' => ['milestones', []],
            'duplicate milestone' => ['milestones', [['id' => 'same', 'label' => 'One', 'scope' => 'One scope'], ['id' => 'same', 'label' => 'Two', 'scope' => 'Two scope']]]];
    }

    #[DataProvider('invalidQuotes')]
    public function test_quote_requires_only_explicit_exact_bounded_authored_terms(string $field, mixed $value): void
    {
        $this->expectException(ValidationException::class);
        ServiceProjectInput::quote(F::quote([$field => $value]));
    }

    public function test_staff_and_customer_commands_cannot_borrow_one_anothers_authority(): void
    {
        $body = F::command(['version' => 0], 'author_quote', ['quote' => F::quote()]);
        $this->expectException(ValidationException::class);
        ServiceProjectInput::command($body, false);
    }
}
