<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\CatalogSocketBinding;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Process\Process;

final class CatalogHostileFileWrapper
{
    public $context;

    public static int $calls = 0;

    public function __construct()
    {
        self::$calls++;
    }

    public function stream_open(): bool
    {
        self::$calls++;

        return false;
    }

    public function url_stat(): false
    {
        self::$calls++;

        return false;
    }

    public function dir_opendir(): bool
    {
        self::$calls++;

        return false;
    }
}

/** Genuine isolated CLI executions; no DB, filesystem reads through the wrapper, or network connection. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CatalogNativeProviderTest extends TestCase
{
    public function test_native_noop_gate_restores_exact_previous_handler_without_invoking_it(): void
    {
        $gate = new ReflectionMethod(CatalogSocketBinding::class, 'nativeProvider');
        self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        $calls = 0;
        $hostile = static function () use (&$calls): bool {
            $calls++;

            return true;
        };
        set_error_handler($hostile);
        try {
            $gate->invoke(null);
            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
            self::assertSame($hostile, $previous);
            self::assertSame(0, $calls);
            self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        } finally {
            restore_error_handler();
        }
    }

    public function test_altered_provider_is_restored_but_refused_and_lifetime_taint_blocks_repair_retry(): void
    {
        $gate = new ReflectionMethod(CatalogSocketBinding::class, 'nativeProvider');
        self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        $calls = 0;
        $hostile = static function () use (&$calls): bool {
            $calls++;

            return true;
        };
        set_error_handler($hostile);
        try {
            $unregistered = stream_wrapper_unregister('file');
            $registered = stream_wrapper_register('file', CatalogHostileFileWrapper::class);
            $refused = false;
            try {
                $gate->invoke(null);
            } catch (LogicException) {
                $refused = true;
            }
            self::assertTrue($unregistered);
            self::assertTrue($registered);
            self::assertTrue($refused);
            self::assertSame(0, CatalogHostileFileWrapper::$calls);
            self::assertSame(0, $calls);
            $events = [];
            set_error_handler(static function (int $type, string $message) use (&$events): bool {
                $events[] = [$type, $message];

                return true;
            });
            try {
                self::assertTrue(stream_wrapper_restore('file'));
            } finally {
                restore_error_handler();
            }
            // Refusal ALREADY restored native provider; explicitly prove/document that global side effect.
            self::assertSame([[E_NOTICE, 'stream_wrapper_restore(): file:// was never changed, nothing to restore']], $events);
            $again = false;
            try {
                $gate->invoke(null);
            } catch (LogicException) {
                $again = true;
            }
            self::assertTrue($again, 'Repair then retry cannot turn the earlier unsupported provider into acceptance.');
            $existing = (new ReflectionClass(CurrentCatalog::class))->newInstanceWithoutConstructor();
            try {
                (new ReflectionMethod(CurrentCatalog::class, 'requireCurrent'))->invoke($existing);
                self::fail('Existing catalog boundary ignored lifetime taint.');
            } catch (LogicException $error) {
                self::assertSame('Provable owned direct TCP metadata target required.', $error->getMessage());
                self::assertTrue((new ReflectionProperty($existing, 'failed'))->getValue($existing));
            }

            $previous = set_error_handler(static fn (): bool => true);
            restore_error_handler();
            self::assertSame($hostile, $previous);
            self::assertSame(0, $calls);
            self::assertSame(0, CatalogHostileFileWrapper::$calls);
            self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        } finally {
            restore_error_handler();
        }
    }

    public function test_missing_provider_refuses_after_restoration_without_calling_ambient_handler(): void
    {
        $gate = new ReflectionMethod(CatalogSocketBinding::class, 'nativeProvider');
        self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        $calls = 0;
        set_error_handler(static function () use (&$calls): bool {
            $calls++;

            return true;
        });
        try {
            $unregistered = stream_wrapper_unregister('file');
            $refused = false;
            try {
                $gate->invoke(null);
            } catch (LogicException) {
                $refused = true;
            }
            self::assertTrue($unregistered);
            self::assertTrue($refused);
            self::assertContains('file', stream_get_wrappers());
            self::assertSame(0, $calls);
            self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        } finally {
            restore_error_handler();
        }
    }

    public function test_disabled_globals_redeclared_in_php_are_refused_without_calling_replacements(): void
    {
        foreach (['stream_wrapper_restore', 'set_error_handler', 'fopen', 'opendir'] as $function) {
            $child = new Process([PHP_BINARY, '-d', 'disable_functions='.$function, __DIR__.'/fixtures/disabled-catalog-function.php', $function], null, null, null, 5);
            $child->run();
            self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            $result = json_decode($child->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame(['refused' => true, 'tainted_retry_refused' => true, 'replacement_calls' => 0, 'constructor_attempts' => 0, 'database_connections' => 0, 'network_connections' => 0], $result);
        }
    }

    public static function unknownNotices(): array
    {
        $message = 'stream_wrapper_restore(): file:// was never changed, nothing to restore';

        return [[E_USER_NOTICE, $message, false], [E_NOTICE, $message.' extra', false], [E_NOTICE, $message, true], [E_WARNING, $message, false]];
    }

    #[DataProvider('unknownNotices')]
    public function test_unknown_or_duplicate_notice_cannot_authorize_or_be_repaired(int $severity, string $message, bool $seen): void
    {
        try {
            $arguments = [$severity, $message, &$seen];
            (new ReflectionMethod(CatalogSocketBinding::class, 'providerNotice'))->invokeArgs(null, $arguments);
            self::fail('Unknown native notice authorized.');
        } catch (LogicException) {
            self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        }
        $this->expectException(LogicException::class);
        CatalogSocketBinding::requireUntainted();
    }

    public static function unknownOutcomes(): array
    {
        return [[false, true], [true, false], [null, true], [1, true]];
    }

    #[DataProvider('unknownOutcomes')]
    public function test_nonexact_result_or_missing_notice_permanently_refuses(mixed $result, bool $seen): void
    {
        try {
            (new ReflectionMethod(CatalogSocketBinding::class, 'providerOutcome'))->invoke(null, $result, $seen);
            self::fail('Nonexact native outcome authorized.');
        } catch (LogicException) {
            self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        }
        $this->expectException(LogicException::class);
        CatalogSocketBinding::requireUntainted();
    }

    public function test_fresh_native_context_has_no_ambient_options_or_notification_callback(): void
    {
        $calls = 0;
        $default = stream_context_set_default(['file' => ['synthetic_option' => true]]);
        stream_context_set_params($default, ['notification' => static function () use (&$calls): void {
            $calls++;
        }]);
        $context = (new ReflectionMethod(CatalogSocketBinding::class, 'freshContext'))->invoke(null);
        self::assertSame([], stream_context_get_options($context));
        self::assertSame(['options' => []], stream_context_get_params($context));
        self::assertSame(0, $calls);
        self::assertSame(0, CurrentCatalog::accounting()['connection_attempts']);
        // Default-context mutation is isolated to this child process, never inherited by the PHPUnit parent.
    }
}
