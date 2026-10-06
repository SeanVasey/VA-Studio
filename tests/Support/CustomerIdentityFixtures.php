<?php

namespace Tests\Support;

use App\Domain\Customers\CustomerIdentityChallenges;
use App\Domain\Customers\Models\CustomerIdentityChallenge;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CustomerIdentityFixtures
{
    public const PASSWORD = 'Synthetic-identity-password-42';

    public const REPLACEMENT = 'Replacement-identity-password-43';

    public const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static function configure(): void
    {
        CustomerFixtures::configure();
        config(['customer.test_identity_enabled' => true, 'customer.identity_transport' => 'private_capture', 'app.url' => 'http://localhost']);
    }

    public static function request(string $purpose = 'enroll', string $email = 'new-customer@example.test', ?string $requestKey = null): array
    {
        $requestKey ??= (string) Str::uuid();
        app(CustomerIdentityChallenges::class)->request($purpose, $email, $requestKey, self::OWNER);
        $requestHash = hash_hmac('sha256', "customer-local-identity-v1\0request\0".self::OWNER."\0".$requestKey, config('app.key'));
        $challenge = CustomerIdentityChallenge::where('request_hash', $requestHash)->sole();
        $message = json_decode(Storage::disk('local')->get('customer-identity-capture/'.$challenge->public_id.'.json'), true, 8, JSON_THROW_ON_ERROR);
        if ($message['purpose'] !== $purpose || $message['to'] !== strtolower(trim($email))) {
            throw new \LogicException('Expected private synthetic message.');
        }
        [$kind, $id, $proof] = explode('.', parse_url($message['url'], PHP_URL_FRAGMENT));

        return ['purpose' => $kind, 'id' => $id, 'proof' => $proof, 'name' => $kind === 'enroll' ? 'Synthetic new customer' : '',
            'password' => self::PASSWORD, 'requestKey' => (string) Str::uuid()];
    }

    public static function complete(array $body): void
    {
        app(CustomerIdentityChallenges::class)->complete(...array_map(fn ($key) => $body[$key], ['purpose', 'id', 'proof', 'name', 'password', 'requestKey']));
    }
}
