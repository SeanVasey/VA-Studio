<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentState;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use ArrayObject;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionFeatureFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;
use Throwable;

class ProductionFeaturePlainConfigurationTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public static function phases(): array
    {
        return [['ordinary'], ['committed']];
    }

    #[DataProvider('phases')]
    public function test_late_app_arrayaccess_parent_cannot_withdraw_purpose_inside_final_identity_proof(string $phase): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $original = config('app');
        $fired = false;
        $install = function () use ($phase, $original, &$fired): void {
            config(['app' => new class($original, function () use ($phase, &$fired): void {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (($frame['class'] ?? null) === ProductionAccountFeatureAccess::class
                        && ($frame['function'] ?? null) === ($phase === 'ordinary' ? 'proveCurrent' : 'proveCommitted')) {
                        $fired = true;
                        config(['production-customer-preferences.grants_enabled' => false]);
                    }
                }
            }) extends ArrayObject
            {

                public function __construct(array $values, private readonly Closure $callback)
                {
                    parent::__construct($values);
                }

                public function offsetExists(mixed $key): bool
                {
                    ($this->callback)();

                    return parent::offsetExists($key);
                }

                public function offsetGet(mixed $key): mixed
                {
                    ($this->callback)();

                    return parent::offsetGet($key);
                }
            }]);
        };
        if ($phase === 'ordinary') {
            ProductionConsentState::saved($install);
        } else {
            Event::listen(TransactionCommitted::class, $install);
        }
        $projection = null;
        $refusal = null;
        try {
            try {
                $projection = $preferences->change($owner, $command);
            } catch (Throwable $error) {
                $refusal = $error;
            }
            $this->assertNull($projection, 'Do not release a grant projection after the final callback withdraws its purpose.');
            $this->assertInstanceOf(ProductionFeatureException::class, $refusal);
            $this->assertSame(503, $refusal->status);
            $this->assertFalse($fired, 'Raw parent admission must refuse before invoking ArrayAccess at all.');
            $this->assertTrue(config('production-customer-preferences.grants_enabled'));
            $this->assertSame($phase === 'ordinary' ? 0 : 1, DB::table('production_consent_events')->count());
            $this->assertSame($phase === 'ordinary' ? 0 : 1, DB::table('production_consent_states')->count());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        } finally {
            config(['app' => $original]);
            ProductionConsentState::flushEventListeners();
        }
    }

    public function test_late_custom_statement_class_refuses_before_any_terminal_statement_callback(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $primary = DB::connection()->getRawPdo();
        $original = $primary->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $installed = false;
        $fired = false;
        ProductionFeatureCallbackStatement::$callback = function () use (&$fired): void {
            $fired = true;
            config(['production-customer-preferences.grants_enabled' => false]);
        };
        ProductionConsentState::saved(function () use ($primary, &$installed): void {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProductionFeatureCallbackStatement::class]);
            $installed = true;
        });
        $refusal = null;
        try {
            try {
                $preferences->change($owner, $command);
            } catch (Throwable $error) {
                $refusal = $error;
            }
        } finally {
            $primary->setAttribute(PDO::ATTR_STATEMENT_CLASS, $original);
            ProductionConsentState::flushEventListeners();
            ProductionFeatureCallbackStatement::$callback = null;
        }
        $this->assertTrue($installed, 'The real Saved hook must install the callback-bearing statement class.');
        $this->assertFalse($fired);
        $this->assertInstanceOf(ProductionFeatureException::class, $refusal);
        $this->assertSame(503, $refusal->status);
        $this->assertSame(0, DB::table('production_consent_events')->count());
        $this->assertSame(0, DB::table('production_consent_states')->count());
        $this->assertFalse($primary->inTransaction());
    }

    public function test_plain_scalar_reference_cannot_mutate_the_captured_purpose_snapshot_in_place(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $notice = config('production-customer-preferences.email_marketing');
        $enabled = true;
        config(['production-customer-preferences' => ['grants_enabled' => &$enabled, 'email_marketing' => $notice]]);
        $fired = false;
        ProductionConsentState::saved(function () use (&$enabled, &$fired): void {
            $enabled = false;
            $fired = true;
        });
        $projection = null;
        $refusal = null;
        try {
            try {
                $projection = $preferences->change($owner, $command);
            } catch (Throwable $error) {
                $refusal = $error;
            }
            $this->assertTrue($fired);
            $this->assertFalse(config('production-customer-preferences.grants_enabled'));
            $this->assertNull($projection, 'A scalar reference must not update the historical captured configuration along with current policy.');
            $this->assertInstanceOf(ConsentException::class, $refusal);
            $this->assertSame(503, $refusal->status);
            $this->assertSame(0, DB::table('production_consent_events')->count());
            $this->assertSame(0, DB::table('production_consent_states')->count());
        } finally {
            ProductionConsentState::flushEventListeners();
        }
    }

    public function test_live_rollout_reference_withdrawal_cannot_commit_a_v1_promotion(): void
    {
        $this->fakePrivateMediaStorage();
        $owner = $this->featureIdentity();
        $track = QuoteFixtures::selection();
        $id = (string) $track['track']->id;
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $library->change($owner, ['action' => 'save-track', 'version' => 0, 'trackId' => $id]);
        $before = (array) DB::table('production_listening_libraries')->sole();
        $enabled = true;
        config(['production-customer-listening' => ['v2_promotion_enabled' => &$enabled,
            'v2_rollout_review_reference' => 'SYNTHETIC stopped rollout, backup and reader review']]);
        $fired = false;
        LibraryRow::saved(function () use (&$enabled, &$fired): void {
            $enabled = false;
            $fired = true;
        });
        try {
            try {
                $library->change($owner, ['action' => 'set-track-note', 'version' => 1, 'trackId' => $id, 'body' => 'SYNTHETIC private note']);
                $this->fail('A changed rollout reference must not preserve the historical approval.');
            } catch (ListeningException $error) {
                $this->assertSame(503, $error->status);
            }
            $this->assertTrue($fired);
            $this->assertFalse($enabled);
            $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
        } finally {
            LibraryRow::flushEventListeners();
        }
    }

    public static function resolutions(): array
    {
        return [['env'], ['config']];
    }

    #[DataProvider('resolutions')]
    public function test_late_configuration_or_environment_resolver_refuses_without_invoking_its_callback(string $target): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $fired = false;
        $application = app();
        $repository = app('config');
        $callbacks = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $originalCallbacks = $callbacks->getValue($application);
        ProductionConsentState::saved(function () use (&$fired, $target, $repository): void {
            app()->beforeResolving($target, function () use (&$fired, $repository): void {
                $fired = true;
                $repository->set('production-customer-preferences.grants_enabled', false);
            });
        });
        try {
            try {
                $preferences->change($owner, $command);
                $this->fail('Late environment resolution must be refused before terminal callbacks.');
            } catch (ProductionFeatureException $error) {
                $this->assertSame(503, $error->status);
            }
            $this->assertFalse($fired);
            $this->assertSame(0, DB::table('production_consent_events')->count());
            $this->assertSame(0, DB::table('production_consent_states')->count());
        } finally {
            $callbacks->setValue($application, $originalCallbacks);
            ProductionConsentState::flushEventListeners();
        }
    }
}

class ProductionFeatureCallbackStatement extends PDOStatement
{
    public static ?Closure $callback = null;

    protected function __construct() {}

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        (self::$callback)?->__invoke();

        return $rows;
    }
}
