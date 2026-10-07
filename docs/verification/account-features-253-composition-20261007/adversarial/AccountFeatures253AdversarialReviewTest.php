<?php

namespace Tests\Feature\ProductionFeaturesReview;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentEvent;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Config\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Independent reviewer's throwaway adversarial cases for the composed 253 consumer.
 * Not part of the owned suite; run explicitly by path from the evidence directory.
 */
final class AccountFeatures253AdversarialReviewTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    protected function tearDown(): void
    {
        ProductionConsentEvent::flushEventListeners();
        parent::tearDown();
    }

    public static function staleGrantVectors(): array
    {
        return [
            'committed listener disables purpose' => ['committed-config'],
            'afterCommit callback rewrites notice text under same version' => ['aftercommit-notice'],
            'committed listener runs a nested module withdrawal' => ['nested-withdrawal'],
        ];
    }

    /** Attempt to obtain a granted/canGrant projection that is stale against the post-commit purpose or durable state. */
    #[DataProvider('staleGrantVectors')]
    public function test_review_cannot_obtain_a_stale_grant_projection(string $vector): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $fired = false;
        if ($vector === 'committed-config') {
            Event::listen(TransactionCommitted::class, function () use (&$fired): void {
                if (! $fired) {
                    $fired = true;
                    config(['production-customer-preferences' => ['grants_enabled' => false, 'email_marketing' => null]]);
                }
            });
        } elseif ($vector === 'aftercommit-notice') {
            ProductionConsentEvent::saved(function () use (&$fired): void {
                DB::connection()->afterCommit(function () use (&$fired): void {
                    $fired = true;
                    config(['production-customer-preferences.email_marketing.notice' => 'SYNTHETIC reviewer changed notice text after durable grant']);
                });
            });
        } else {
            Event::listen(TransactionCommitted::class, function () use (&$fired, $preferences, $owner): void {
                if (! $fired) {
                    $fired = true;
                    $preferences->change($owner, $this->productionWithdraw(1));
                }
            });
        }
        $projection = null;
        $refusal = null;
        try {
            $projection = $preferences->change($owner, $command);
        } catch (Throwable $error) {
            $refusal = $error;
        }
        $this->assertTrue($fired, 'The adversarial vector must actually run.');
        $this->assertNull($projection, 'A grant projection must never be released after its postcommit evidence moved.');
        $this->assertInstanceOf(ProductionFeatureException::class, $refusal);
        $this->assertSame(503, $refusal->status);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        ProductionConsentEvent::flushEventListeners();
        Event::forget(TransactionCommitted::class);

        // The write is durable (unknown outcome), and the fresh GET reflects current authority only.
        $fresh = $preferences->read($owner)['preferences']['purposes'][0];
        if ($vector === 'nested-withdrawal') {
            $this->assertSame(2, DB::table('production_consent_events')->count());
            $this->assertSame('withdrawn', $fresh['status']);
            $this->assertSame(2, $fresh['version']);
        } else {
            $this->assertSame(1, DB::table('production_consent_events')->count());
            $this->assertSame('unknown', $fresh['status'], 'A grant under a moved purpose must not project as granted.');
            $this->assertFalse($fresh['canGrant']);
            $this->assertSame(1, $fresh['version']);
        }
    }

    public static function withdrawnPurposeVectors(): array
    {
        return [
            'consent: purpose disabled by a query listener after the event INSERT' => ['consent-query'],
            'consent: config repository instance swapped from a Saved hook' => ['consent-repository'],
            'listening: account feature withdrawn by a query listener after the UPDATE' => ['listening-query'],
        ];
    }

    /** Attempt to make a feature write durable after its purpose/feature authority was withdrawn mid-operation. */
    #[DataProvider('withdrawnPurposeVectors')]
    public function test_review_cannot_commit_a_feature_write_under_a_withdrawn_purpose(string $vector): void
    {
        $listening = $vector === 'listening-query';
        $owner = $this->featureIdentity($listening ? 'listening_library' : 'consent_preferences');
        $service = $listening ? new ProductionListeningLibrary : new ProductionConsentPreferences;
        $service->initialize($owner);
        $fired = false;
        $original = app('config');
        if ($vector === 'consent-query') {
            DB::listen(function (QueryExecuted $query) use (&$fired): void {
                if (! $fired && str_contains($query->sql, 'production_consent_events') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                    $fired = true;
                    config(['production-customer-preferences.grants_enabled' => false]);
                }
            });
        } elseif ($vector === 'consent-repository') {
            ProductionConsentEvent::saved(function () use (&$fired, $original): void {
                $fired = true;
                $items = $original->all();
                $items['production-customer-preferences'] = ['grants_enabled' => false, 'email_marketing' => null];
                app()->instance('config', new Repository($items));
            });
        } else {
            DB::listen(function (QueryExecuted $query) use (&$fired): void {
                if (! $fired && str_contains($query->sql, 'production_listening_libraries') && str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
                    $fired = true;
                    config(['production-account-features.enabled' => false]);
                }
            });
        }
        $before = $listening ? (array) DB::table('production_listening_libraries')->sole() : null;
        $projection = null;
        $refusal = null;
        try {
            $projection = $listening
                ? $service->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC reviewer withdrawn feature'])
                : $service->change($owner, $this->productionGrant());
        } catch (Throwable $error) {
            $refusal = $error;
        } finally {
            app()->instance('config', $original);
        }
        $this->assertTrue($fired, 'The adversarial vector must actually run.');
        $this->assertNull($projection);
        $this->assertTrue($refusal instanceof ProductionFeatureException || $refusal instanceof ConsentException
            || $refusal instanceof IdentityException || $refusal instanceof ListeningException, $refusal ? get_class($refusal) : 'none');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getRawPdo()->inTransaction());
        if ($listening) {
            $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole(), 'Withdrawn feature write must roll back byte-for-byte.');
        } else {
            $this->assertSame(0, DB::table('production_consent_events')->count(), 'Withdrawn purpose must leave no durable grant.');
            $this->assertSame(0, DB::table('production_consent_states')->count());
            $this->assertSame(0, DB::table('production_consent_policies')->count());
        }
    }
}
