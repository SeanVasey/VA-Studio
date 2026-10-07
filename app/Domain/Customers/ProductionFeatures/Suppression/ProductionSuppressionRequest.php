<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use JsonSerializable;
use LogicException;
use WeakMap;

/** Private provider transport input built only from durable target/attempt rows; never HTTP output or log context.
 * Values live outside the instance, so dumps, casts and instance Reflection never expose the recipient.
 */
final class ProductionSuppressionRequest implements JsonSerializable
{
    /** @var WeakMap<self, array>|null */
    private static ?WeakMap $sealed = null;

    private function __construct(array $values)
    {
        self::$sealed ??= new WeakMap;
        self::$sealed[$this] = $values;
    }

    /** @internal Minted by ProductionSuppressionRecords from re-validated durable rows only. */
    public static function fromRecords(string $operationId, string $targetId, string $recipient, string $recipientHmac, string $providerHash, string $requestHash): self
    {
        return new self(compact('operationId', 'targetId', 'recipient', 'recipientHmac', 'providerHash', 'requestHash'));
    }

    public function operationId(): string
    {
        return $this->value('operationId');
    }

    public function targetId(): string
    {
        return $this->value('targetId');
    }

    /** The authentic captured target, never the account's current address. */
    public function recipient(): string
    {
        return $this->value('recipient');
    }

    public function recipientHmac(): string
    {
        return $this->value('recipientHmac');
    }

    public function providerHash(): string
    {
        return $this->value('providerHash');
    }

    public function requestHash(): string
    {
        return $this->value('requestHash');
    }

    public function __debugInfo(): array
    {
        return ['production_suppression_request' => true];
    }

    public function __serialize(): never
    {
        throw new LogicException('Suppression request must not be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Suppression request must not be unserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Suppression request is not an HTTP projection.');
    }

    private function value(string $key): string
    {
        if (self::$sealed === null || ! isset(self::$sealed[$this])) {
            throw new LogicException('Suppression request is not a minted server request.');
        }

        return self::$sealed[$this][$key];
    }
}
