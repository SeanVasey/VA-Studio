<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerIdentityChallenges;
use App\Domain\Customers\Models\CustomerIdentityChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CustomerIdentityFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** The test-only private-capture identity keeps its exact-request resend and fence across APP_KEY rotation. */
class CustomerIdentityKeyRotationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->useKeys(self::key('1'));
        F::configure();
        Mail::fake();
    }

    public function test_exact_request_after_rotation_resends_the_original_proof_on_the_original_fence(): void
    {
        $key = (string) Str::uuid();
        $body = F::request(requestKey: $key);
        $before = CustomerIdentityChallenge::sole()->getAttributes();
        $fence = DB::table('customer_identity_addresses')->sole();
        $this->useKeys(self::key('2'), [self::key('1')]);
        $this->resend($key);
        $this->assertSame($before, CustomerIdentityChallenge::sole()->getAttributes());
        $this->assertSame([(array) $fence], DB::table('customer_identity_addresses')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($body['proof'], $this->captured($before['public_id']));
        $other = (string) Str::uuid();
        $this->resend($other);
        $this->assertDatabaseCount('customer_identity_challenges', 2);
        $this->assertDatabaseCount('customer_identity_addresses', 1);
        $new = CustomerIdentityChallenge::where('public_id', '!=', $before['public_id'])->sole();
        $this->assertSame($fence->address_key, $new->address_key);
        $this->assertSame(hash_hmac('sha256', "customer-local-identity-v1\0request\0".F::OWNER."\0".$other, self::key('2')), $new->request_hash);
        $this->assertSame(hash('sha256', $this->captured($new->public_id)), $new->proof_hash);
        F::complete($body);
        $this->assertSame('completed', CustomerIdentityChallenge::where('public_id', $before['public_id'])->value('state'));
        $this->assertSame(1, User::count());
    }

    public function test_removed_previous_key_never_adopts_or_resends_an_old_request(): void
    {
        $key = (string) Str::uuid();
        $body = F::request(requestKey: $key);
        $this->useKeys(self::key('2'), [self::key('3')]);
        $this->resend($key);
        $this->assertDatabaseCount('customer_identity_challenges', 2);
        $this->assertDatabaseCount('customer_identity_addresses', 2);
        $new = CustomerIdentityChallenge::where('public_id', '!=', $body['id'])->sole();
        $this->assertNotSame($body['proof'], $this->captured($new->public_id));
        $this->assertSame($body['proof'], $this->captured($body['id']));
    }

    private function resend(string $requestKey): void
    {
        app(CustomerIdentityChallenges::class)->request('enroll', 'new-customer@example.test', $requestKey, F::OWNER);
    }

    private function captured(string $publicId): string
    {
        $message = json_decode(Storage::disk('local')->get('customer-identity-capture/'.$publicId.'.json'), true, 8, JSON_THROW_ON_ERROR);

        return explode('.', parse_url($message['url'], PHP_URL_FRAGMENT))[2];
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
