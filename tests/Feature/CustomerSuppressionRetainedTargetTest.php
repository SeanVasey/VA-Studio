<?php

namespace Tests\Feature;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\Models\SuppressionTarget;
use App\Domain\Customers\Preferences\Suppression\SuppressionDelivery;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\SuppressionFixtures;
use Tests\TestCase;

/**
 * Independent review C-A1 regression for bf6e5937 (PR39 r4206829225 follow-up).
 * Property: an older target row whose captured recipient does not HMAC to its own
 * recipient_hmac (not authentic; insertable past the retention triggers) must not
 * SILENTLY block delivery of the account's genuine pending target. Acceptable outcomes:
 * the genuine target is delivered once, or the call fails closed with 503.
 */
final class CustomerSuppressionRetainedTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_unauthentic_older_target_never_silently_blocks_genuine_pending_target(): void
    {
        $c = CustomerFixtures::account();
        $id = (string) Str::uuid();
        DB::table('customer_suppression_targets')->insert(['public_id' => $id, 'customer_account_id' => $c['account']->id, 'purpose' => 'email_marketing',
            'recipient_hmac' => str_repeat('ab', 32), 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
            'recipient_ciphertext' => Crypt::encryptString(CanonicalJson::encode(['schema' => 1, 'targetId' => $id, 'accountId' => $c['account']->id,
                'purpose' => 'email_marketing', 'email' => 'synthetic-rogue@example.test']))]);
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw());
        $this->assertSame(2, SuppressionTarget::count());
        $a = new SuppressionFixtures;
        $s = new SuppressionDelivery($a);
        $outcomes = [];
        foreach ([1, 2] as $call) {
            try {
                $outcomes[] = $s->process($c['principal'], $c['user'], 1)['status'];
            } catch (ConsentException $e) {
                $outcomes[] = 'refused:'.$e->status;
            }
        }
        $delivered = $a->sent === 1 && $outcomes[0] === 'confirmed';
        $refused = $a->sent === 0 && str_starts_with($outcomes[0], 'refused');
        $this->assertTrue($delivered || $refused, 'Genuine pending withdrawal target silently not delivered: '.json_encode($outcomes));
    }

    public function test_same_connection_temp_attempts_shadow_refuses_instead_of_silently_skipping_retained_target(): void
    {
        $c = CustomerFixtures::account();
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw());
        User::whereKey($c['user']->id)->update(['email' => 'synthetic-changed@example.test']);
        $a = new SuppressionFixtures;
        DB::statement('CREATE TEMPORARY TABLE customer_suppression_attempts (target_id bigint)');
        try {
            DB::table('customer_suppression_attempts')->insert(['target_id' => SuppressionTarget::sole()->id]);
            try {
                $status = (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1)['status'];
                $this->fail('A temp shadow must not silently skip the retained target: '.$status);
            } catch (ConsentException $e) {
                $this->assertSame(503, $e->status);
            }
        } finally {
            DB::statement(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.customer_suppression_attempts' : 'DROP TEMPORARY TABLE customer_suppression_attempts');
        }
        $this->assertSame(0, $a->sent);
        $this->assertSame(['status' => 'confirmed'], (new SuppressionDelivery($a))->process($c['principal'], $c['user'], 1));
        $this->assertSame(1, $a->sent);
    }
}
