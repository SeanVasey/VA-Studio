<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionIntents;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\Support\RecordingSuppressionProvider;
use Tests\TestCase;

/**
 * Independent review of PR #57: suppression across an APP_KEY rotation. A retained withdrawal keeps its target and
 * is never resent; a new withdrawal written under the new key gets its own target (schema equality rule), so the
 * same address is submitted to the provider a second time. Never fewer suppressions, possibly one more per rotation.
 */
class ReviewSuppressionKeyRotationTest extends TestCase
{
    use ProductionSuppressionFixtures;

    private const EMAIL = 'suppression-rotation@example.test';

    private RecordingSuppressionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->suppressionSetup();
        $this->useKeys(self::key('1'));
    }

    public function test_retained_withdrawal_keeps_its_target_and_a_new_withdrawal_after_rotation_adds_one_more_target(): void
    {
        $owner = $this->featureIdentity('consent_preferences', self::EMAIL);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $intents = new ProductionSuppressionIntents($this->provider);
        $this->assertSame(['status' => 'unknown'], $intents->request($owner, 1));
        $this->assertCount(1, $this->provider->suppressed);

        $this->useKeys(self::key('2'), [self::key('1')]);
        $rotated = $this->signIn();
        $before = $this->suppressionRows();
        $this->assertSame(['status' => 'unknown'], $intents->request($rotated, 1));
        $this->assertSame($before, $this->suppressionRows(), 'The retained withdrawal selects its original target; nothing new.');
        $this->assertCount(1, $this->provider->suppressed, 'No resend after rotation.');

        $preferences->change($rotated, $this->productionGrant(1));
        $preferences->change($rotated, $this->productionWithdraw(2));
        $this->assertSame(['status' => 'unknown'], $intents->request($rotated, 3));
        $targets = DB::table('production_suppression_targets')->orderBy('id')->get();
        $this->assertCount(2, $targets);
        $this->assertNotSame($targets[0]->recipient_hmac, $targets[1]->recipient_hmac);
        $this->assertCount(2, $this->provider->suppressed, 'The same address is submitted once more after rotation.');
        $this->assertSame([self::EMAIL, self::EMAIL], array_column($this->provider->suppressed, 'recipient'));
        $this->assertSame('withdrawn', $preferences->read($rotated)['preferences']['purposes'][0]['status']);

        // Retiring the old key: the consent read refuses rather than reporting a grant (fail closed, never send).
        $this->useKeys(self::key('2'));
        $this->assertNull((new ProductionCustomerSessions)->authenticate(self::EMAIL, 'MailboxPassword123'));
    }

    private function signIn(): ProductionAccountFeatureIdentity
    {
        $verified = (new ProductionCustomerSessions)->authenticate(self::EMAIL, 'MailboxPassword123');
        $this->assertNotNull($verified);

        return $this->featureFor($verified, 'consent_preferences');
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
