<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\PrimaryBoundary;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Server-located immutable V2 buyer/order locator. It carries no grant, payment or tax authority. */
final readonly class ProductionTaxPaidOrderLocatorV2 implements \JsonSerializable
{
    private function __construct(public string $orderId, public string $orderHash, private array $buyer) {}

    public static function locate(string $orderId): self
    {
        CheckoutException::require(preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $orderId) === 1);
        $connection = DB::connection();
        CheckoutException::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction());
        PrimaryBoundary::prove($connection->getPdo(), $connection->getDriverName());
        // No lock here: the consumer locks the original identity first, then reads under its own transaction.
        $statement = $connection->getPdo()->prepare('SELECT * FROM '.TaxCheckoutSchema::TABLES['order'].' WHERE public_id = ? ORDER BY id LIMIT 2');
        $statement->execute([$orderId]);
        $records = $statement->fetchAll(\PDO::FETCH_ASSOC);
        CheckoutException::require(count($records) === 1);
        $body = Evidence::open($records[0], 'production_tax_checkout_order');
        CheckoutException::require($body['buyer']['origin_id'] === $records[0]['buyer_origin_id']);

        return new self($orderId, $records[0]['payload_hash'], $body['buyer']);
    }

    public function historicalBuyerBinding(): array
    {
        return $this->buyer;
    }

    public function __serialize(): array
    {
        throw new LogicException('Production tax paid-source locators cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Production tax paid-source locators are internal server objects.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Production tax paid-source locators cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['schema_version' => 2, 'authority' => 'none'];
    }
}
