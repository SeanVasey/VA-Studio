<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use Illuminate\Support\Facades\DB;

/** Synthetic provider evidence only, permanently restricted to testing/test funds. */
final class ProductionCheckoutGatewayFixture implements ProviderGateway
{
    public array $creates = [];

    public array $params = [];

    public bool $paid = false;

    public bool $loseFirstResponse = false;

    public ?\Closure $afterPaymentRead = null;

    public function provenance(ExecutionContextV1 $context): string
    {
        CheckoutException::require(app()->environment('testing') && $context->fundsMode === 'test' && DB::transactionLevel() === 0);

        return 'synthetic_rehearsal';
    }

    public function account(ExecutionContextV1 $context): array
    {
        $this->provenance($context);

        return ['object' => 'account', 'id' => $context->accountId];
    }

    public function create(ExecutionContextV1 $context, array $params, string $key): array
    {
        $this->provenance($context);
        if ($this->params !== []) {
            Evidence::same($this->params, $params);
            CheckoutException::require($this->creates[0]['key'] === $key);
        }
        $this->params = $params;
        $this->creates[] = compact('params', 'key');
        if ($this->loseFirstResponse && count($this->creates) === 1) {
            throw new \RuntimeException('SYNTHETIC LOST RESPONSE: no actual network payment');
        }

        return ProductionCheckoutProviderFixtures::session($this->params);
    }

    public function retrieve(ExecutionContextV1 $context, string $sessionId): array
    {
        $this->provenance($context);
        CheckoutException::require($sessionId === 'cs_test_SYNTHETIC' && $this->params !== []);

        return ProductionCheckoutProviderFixtures::session($this->params, $this->paid ? 'complete' : 'open', $this->paid);
    }

    public function paymentIntent(ExecutionContextV1 $context, string $id): array
    {
        $this->provenance($context);
        CheckoutException::require($id === 'pi_SYNTHETIC' && $this->paid);
        $payment = ProductionCheckoutProviderFixtures::payment($this->params);
        ($this->afterPaymentRead ?? static function (): void {})();

        return $payment;
    }
}
