<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Server-located immutable buyer/order locator. This object carries no grant or payment authority. */
final readonly class ProductionPaidOrderLocatorV1 implements \JsonSerializable
{
    private function __construct(public string $orderId, public string $orderHash, private array $buyer) {}

    public static function locate(string $orderId): self
    {
        CheckoutException::require(preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $orderId) === 1);
        $connection = DB::connection();
        CheckoutException::require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction());
        PrimaryBoundary::prove($connection->getPdo(), $connection->getDriverName());
        // A locator read intentionally takes no order lock: the consumer locks original identity first.
        $statement = $connection->getPdo()->prepare('SELECT * FROM '.CheckoutSchema::TABLES['order'].' WHERE public_id = ? ORDER BY id LIMIT 2');
        $statement->execute([$orderId]);
        $records = $statement->fetchAll(\PDO::FETCH_ASSOC);
        CheckoutException::require(count($records) === 1);
        $body = Evidence::open($records[0], 'production_checkout_order');
        CheckoutException::require($body['buyer']['origin_id'] === $records[0]['buyer_origin_id']);

        return new self($orderId, $records[0]['payload_hash'], $body['buyer']);
    }

    public function historicalBuyerBinding(): array
    {
        return $this->buyer;
    }

    public function __serialize(): array
    {
        throw new LogicException('Production paid-source locators cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Production paid-source locators are internal server objects.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('Production paid-source locators cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['schema_version' => 1, 'authority' => 'none'];
    }
}
