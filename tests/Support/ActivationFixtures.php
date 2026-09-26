<?php

namespace Tests\Support;

use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use App\Domain\Delivery\DeliveryAssets;
use Illuminate\Support\Facades\DB;

/** Synthetic paid orders and orchestration PDFs only; this never establishes production delivery authority. */
final class ActivationFixtures
{
    public static function policy(): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_fulfillment_activation', 'version' => 'test-fulfillment-activation-v1',
            'scope' => 'complete_paid_order', 'storage' => 'private_local', 'verification' => 'fresh_sha256',
            'max_verification_age_seconds' => 300, 'pending_entitlements' => 'preserve',
            'buyer_identity' => 'unverified_guest', 'download_access' => 'disabled'];
    }

    public static function configure(): void
    {
        ContractFixtures::configure();
        config(['delivery.test_activation_enabled' => true,
            'delivery.test_activation_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR)]);
    }

    public static function issued(object $gateway, bool $mixed = false): array
    {
        return self::issue(ContractFixtures::paid($gateway, $mixed));
    }

    public static function issue(array $fixture): array
    {
        foreach ($fixture['grants'] as $grant) {
            $request = app(RequestTestContract::class)->handle($grant->id);
            if (app(RenderTestContract::class)->handle($request->id) !== 'ready') {
                throw new \LogicException('Synthetic contract did not issue.');
            }
        }

        return $fixture;
    }

    public static function observingAssets(): DeliveryAssets
    {
        return new class extends DeliveryAssets {
            public array $transactionLevels = [];
            public mixed $afterVerify = null;

            public function verify(array $evidence): void
            {
                $this->transactionLevels[] = DB::transactionLevel();
                parent::verify($evidence);
                if ($this->afterVerify !== null) { ($this->afterVerify)($evidence); }
            }
        };
    }
}
