<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawal;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawalReader;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;

/** Consent recipient digests stay verifiable after APP_KEY rotation while the writing key is still configured. */
class ProductionConsentKeyRotationTest extends TestCase
{
    use ProductionFeatureFixtures;

    private const EMAIL = 'feature-owner@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
        $this->useKeys(self::key('1'));
    }

    public function test_consent_status_withdrawal_capture_and_new_events_survive_rotation(): void
    {
        $owner = $this->featureIdentity('consent_preferences', self::EMAIL);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $preferences->change($owner, $this->productionGrant(1));
        $events = $this->events();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $rotated = $this->signIn();
        $read = $preferences->read($rotated)['preferences']['purposes'][0];
        $this->assertSame(['granted', 'pending', 2], [$read['status'], $read['suppression']['status'], $read['version']]);
        $withdrawal = $this->capture($rotated, 2)->serverSnapshot();
        $this->assertSame(self::EMAIL, $withdrawal['recipient']);
        $this->assertSame($events[0]['recipient_hmac'], $withdrawal['recipientHmac']);
        $this->assertSame($events, $this->events());
        $changed = $preferences->change($rotated, $this->productionWithdraw(2))['preferences']['purposes'][0];
        $this->assertSame(['withdrawn', 'pending', 3], [$changed['status'], $changed['suppression']['status'], $changed['version']]);
        $new = $this->events()[2];
        $this->assertNotSame($events[0]['recipient_hmac'], $new['recipient_hmac']);
        $this->assertSame($new['recipient_hmac'], $this->capture($rotated, 3)->serverSnapshot()['recipientHmac']);
    }

    public function test_consent_digest_under_a_removed_key_is_refused_while_identity_still_verifies(): void
    {
        $owner = $this->featureIdentity('consent_preferences', self::EMAIL);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $this->useKeys(self::key('2'), [self::key('1')]);
        $preferences->change($this->signIn(), $this->productionWithdraw());
        $events = $this->events();
        $this->useKeys(self::key('3'), [self::key('1')]);
        $identity = $this->signIn();
        foreach ([fn () => $preferences->read($identity), fn () => $this->capture($identity, 1)] as $call) {
            try {
                $call();
                $this->fail('A consent recipient digest under a removed key must not verify.');
            } catch (ProductionFeatureException) {
                $this->assertSame($events, $this->events());
            }
        }
        $this->useKeys(self::key('3'), [self::key('2'), self::key('1')]);
        $this->assertSame('withdrawn', $preferences->read($this->signIn())['preferences']['purposes'][0]['status']);
    }

    private function signIn(): ProductionAccountFeatureIdentity
    {
        $verified = (new ProductionCustomerSessions)->authenticate(self::EMAIL, 'MailboxPassword123');
        $this->assertNotNull($verified);

        return $this->featureFor($verified, 'consent_preferences');
    }

    private function capture(ProductionAccountFeatureIdentity $identity, int $version): ?ProductionConsentWithdrawal
    {
        return (new ProductionFeatureOperation)->run($identity, fn (ProductionFeatureContext $context): array => ['withdrawal' => (new ProductionConsentWithdrawalReader)->read($context, $version)])['withdrawal'];
    }

    private function events(): array
    {
        return DB::table('production_consent_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function useKeys(string $current, array $previous = []): void
    {
        config(['app.key' => $current, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private static function key(string $fill): string
    {
        return 'base64:'.base64_encode(str_repeat($fill, 32));
    }
}
