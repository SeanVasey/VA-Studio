<?php

namespace App\Domain\Commerce\ProductionFinancialRetrieval;

use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialCandidate;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialExpectation;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialInspectionException;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialObservation;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxPaidOrderSourceV2;
use Illuminate\Container\Container;
use JsonSerializable;
use LogicException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Throwable;

/** Two original closures surround one controlled test SDK path. Output is still unattested input only. */
final class FinancialReadPlan implements JsonSerializable
{
    private ?ReadOwnership $ownership = null;

    private function __construct(private readonly FinancialExpectation $expectation, private readonly RetrievalPolicy $policy,
        ReadOwnership $ownership)
    {
        $this->ownership = $ownership;
    }

    public static function fromV1(ProductionPaidOrderSourceV1 $source, CurrentRows $reader, int $originalDeadlineNs): self
    {
        return self::capture($source, $reader, $originalDeadlineNs);
    }

    public static function fromTaxV2(ProductionTaxPaidOrderSourceV2 $source, CurrentRows $reader, int $originalDeadlineNs): self
    {
        return self::capture($source, $reader, $originalDeadlineNs);
    }

    private static function capture(ProductionPaidOrderSourceV1|ProductionTaxPaidOrderSourceV2 $source,
        CurrentRows $reader, int $originalDeadlineNs): self
    {
        $policy = RetrievalPolicy::capture();
        $policy->requireUnobserved($reader);
        $deadline = min($originalDeadlineNs, hrtime(true) + 30_000_000_000);
        FinancialRetrievalException::require(hrtime(true) < $deadline);
        $ownership = new ReadOwnership($source, $reader, $deadline);
        try {
            $expectation = $source instanceof ProductionPaidOrderSourceV1
                ? FinancialExpectation::fromV1($source, $reader) : FinancialExpectation::fromTaxV2($source, $reader);
            $policy->prove();
            $policy->bindAccount($expectation->identity()['provider_account']);
            $policy->requireUnobserved($reader);
            for ($index = 0; $index < 2; $index++) {
                $receipt = $source->committedReadReceipt($reader, $deadline);
                $ownership->attach($receipt);
                $policy->prove();
            }
            $source->proveRetainedCurrent($reader);
            $policy->prove();

            return new self($expectation, $policy, $ownership);
        } catch (Throwable) {
            $ownership->close();
            throw new FinancialRetrievalException;
        }
    }

    public function read(): FinancialCandidate
    {
        return $this->consume(null);
    }

    /** Explicit synthetic transport seam, admitted only in the exact literal testing environment. */
    public function readSynthetic(ClientInterface $transport): FinancialCandidate
    {
        return $this->consume($transport);
    }

    private function consume(?ClientInterface $synthetic): FinancialCandidate
    {
        $ownership = $this->ownership;
        FinancialRetrievalException::require($ownership !== null, 'used');
        $ownership->claim();
        $refused = true;
        try {
            $this->policy->prove();
            $this->policy->requireOutsideTransactions();
            $ownership->proveActive();
            $ownership->proveReceipt(0);
            $this->policy->prove();
            $ownership->proveActive();
            if ($synthetic !== null) {
                // Capture uses the existing pure literal environment admission; this
                // narrower seam also requires a literal loaded testing instance.
                $app = Container::getInstance();
                $instances = RetrievalPolicy::raw($app, Container::class, 'instances');
                FinancialRetrievalException::require(is_array($instances) && ($instances['env'] ?? null) === 'testing');
            }
            $transport = $synthetic ?? (new CurlClient)->setConnectTimeout(3)->setTimeout(5);
            $identity = $this->expectation->identity();
            $guard = new GuardedSdkTransport($transport, $this->policy, $ownership, $identity['provider_payment_id']);
            $raw = (new StripeSdkCheckoutGateway($guard))->financialState($identity['provider_payment_id']);
            $guard->proveComplete();
            // The gateway has already restored the original global transport slot.
            $this->policy->prove();
            $ownership->proveActive();
            $candidate = FinancialObservation::capture($this->expectation, $raw);
            $ownership->proveReceipt(1);
            $this->policy->prove();
            $ownership->proveActive();
            $refused = false;

            return $candidate;
        } catch (FinancialInspectionException $error) {
            throw new FinancialRetrievalException($error->reason);
        } catch (Throwable) {
            throw new FinancialRetrievalException;
        } finally {
            $ownership->close($refused);
        }
    }

    public function close(): void
    {
        $this->ownership?->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __clone(): never
    {
        $this->ownership = null;
        throw new LogicException('Original financial read plans cannot be cloned.');
    }

    public function __serialize(): never
    {
        throw new LogicException('Original financial read plans cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Original financial read plans cannot be reconstructed.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Original financial read plans cannot be JSON serialized.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'test_sdk_read_only', 'providerAuthenticated' => false];
    }
}
