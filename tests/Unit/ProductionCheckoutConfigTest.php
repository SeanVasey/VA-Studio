<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Environment values reach config as strings; the review lifetime must arrive as the int ExecutionContextV1 admits. */
class ProductionCheckoutConfigTest extends TestCase
{
    private const VARIABLE = 'PRODUCTION_CHECKOUT_REVIEW_LIFETIME_SECONDS';

    protected function tearDown(): void
    {
        $this->setEnvironment(null);
        parent::tearDown();
    }

    #[DataProvider('lifetimes')]
    public function test_review_lifetime_from_environment_is_admitted_only_as_an_exact_integer(?string $supplied, mixed $expected): void
    {
        $this->setEnvironment($supplied);
        $config = require base_path('config/production_checkout.php');
        $this->assertSame($expected, $config['review_lifetime_seconds']);
    }

    public static function lifetimes(): array
    {
        return [
            'unset stays null' => [null, null],
            'numeric string becomes int' => ['600', 600],
            'float string is not coerced' => ['600.5', '600.5'],
            'text is not coerced' => ['ten minutes', 'ten minutes'],
            'leading plus is not coerced' => ['+600', '+600'],
        ];
    }

    private function setEnvironment(?string $value): void
    {
        if ($value === null) {
            putenv(self::VARIABLE);
            unset($_ENV[self::VARIABLE], $_SERVER[self::VARIABLE]);
        } else {
            putenv(self::VARIABLE.'='.$value);
            $_ENV[self::VARIABLE] = $value;
            $_SERVER[self::VARIABLE] = $value;
        }
        Env::disablePutenv();
        Env::enablePutenv();
    }
}
