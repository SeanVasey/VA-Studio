<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionPolicy\CapabilityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The three cross-schema qualifier matchers refuse a selected database name that carries an
 * identifier delimiter instead of guessing which spelling MySQL stored for it. Any engine:
 * the matchers are pure functions of the stored body and the configured name.
 */
class SchemaQualifierDelimiterTest extends TestCase
{
    private const MIGRATION = '2026_10_07_243000_inquiry_notification_intents.php';

    /** @return array<string, array{string}> */
    public static function delimitedNames(): array
    {
        return ['backtick' => ['a`b'], 'double quote' => ['a"b'], 'doubled backtick' => ['a``b'], 'leading quote' => ['"a']];
    }

    /** @return array<string, array{string, string}> */
    public static function matchers(): array
    {
        return [
            'capability' => [CapabilityMigrationOwnership::class, 'qualifies'],
            'identity' => [IdentityMigrationOwnership::class, 'qualifies'],
            'inquiry' => ['migration', 'qualifiesDatabase'],
        ];
    }

    #[DataProvider('delimitedNames')]
    public function test_each_matcher_refuses_a_delimited_database_name_before_matching(string $database): void
    {
        foreach (self::matchers() as $guard => [$class, $method]) {
            $sql = 'SELECT COUNT(*) FROM `'.str_replace('`', '``', $database).'`.`owned`';
            try {
                $this->invoke($class, $method, $sql, $database);
                $this->fail("{$guard} admitted the delimited database name {$database}");
            } catch (LogicException $exception) {
                // The identity guard's refusal message is deliberately generic.
                if ($guard !== 'identity') {
                    $this->assertStringContainsString('identifier-delimited database name', $exception->getMessage(), $guard);
                }
                $this->assertTrue(true, $guard);
            }
        }
    }

    public function test_each_matcher_still_decides_plain_names_on_the_stored_body(): void
    {
        foreach (self::matchers() as $guard => [$class, $method]) {
            $this->assertTrue($this->invoke($class, $method, 'SELECT COUNT(*) FROM `va-sey`.`owned`', 'va-sey'), $guard);
            $this->assertFalse($this->invoke($class, $method, 'SELECT COUNT(*) FROM `va-sey-2`.`owned`', 'va-sey'), $guard);
        }
    }

    private function invoke(string $class, string $method, string $sql, string $database): bool
    {
        $object = $class === 'migration' ? require database_path('migrations/'.self::MIGRATION) : new $class;

        return (new ReflectionMethod($object, $method))->invoke($object, $sql, $database);
    }
}
