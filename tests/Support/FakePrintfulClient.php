<?php

namespace Tests\Support;

use App\Domain\Merch\Fulfillment\Printful\PrintfulClient;
use App\Domain\Merch\Fulfillment\Printful\PrintfulException;
use App\Domain\Merch\Fulfillment\Printful\PrintfulOrder;
use App\Domain\Merch\Fulfillment\Printful\PrintfulShippingRate;

/** In-memory Printful. Records every call; nothing here can reach the network. */
final class FakePrintfulClient implements PrintfulClient
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, PrintfulOrder> by external id */
    public array $orders = [];

    /** Failures to raise on the next create call(s), consumed in order; an entry may let the order exist (a lost response). */
    public array $createFailures = [];

    public ?PrintfulException $confirmFailure = null;

    /** Runs inside confirmOrder, before it answers: lets a test interleave a second caller or a late hold. */
    public ?\Closure $duringConfirm = null;

    private int $sequence = 9000;

    public function createDraftOrder(string $externalId, array $recipient, array $items, string $shipping): PrintfulOrder
    {
        $this->calls[] = ['create', compact('externalId', 'recipient', 'items', 'shipping')];
        if ($this->createFailures !== []) {
            $failure = array_shift($this->createFailures);
            if ($failure['created'] ?? false) {
                $this->orders[$externalId] = new PrintfulOrder((string) ++$this->sequence, $externalId, 'draft');
            }
            throw $failure['exception'];
        }
        if (isset($this->orders[$externalId])) {
            // Not a documented Printful behavior: a duplicate create is made loud so a test cannot pass by accident.
            throw new PrintfulException('FAKE_DUPLICATE_CREATE');
        }

        return $this->orders[$externalId] = new PrintfulOrder((string) ++$this->sequence, $externalId, 'draft');
    }

    public function confirmOrder(string $printfulOrderId): PrintfulOrder
    {
        $this->calls[] = ['confirm', ['id' => $printfulOrderId]];
        if ($this->duringConfirm) {
            ($this->duringConfirm)();
        }
        if ($this->confirmFailure) {
            throw $this->confirmFailure;
        }
        foreach ($this->orders as $externalId => $order) {
            if ($order->id === $printfulOrderId) {
                return $this->orders[$externalId] = new PrintfulOrder($order->id, $externalId, 'pending');
            }
        }
        throw new PrintfulException('PRINTFUL_REJECTED', false, 404);
    }

    /** Runs once inside the next lookup, to simulate a second worker acting while the first waits on Printful. */
    public ?\Closure $onFind = null;

    public function findOrderByExternalId(string $externalId): ?PrintfulOrder
    {
        $this->calls[] = ['find', ['externalId' => $externalId]];
        if ($this->onFind !== null) {
            $hook = $this->onFind;
            $this->onFind = null;
            $hook();
        }

        return $this->orders[$externalId] ?? null;
    }

    public function shippingRates(array $recipient, array $items, string $currency): array
    {
        $this->calls[] = ['rates', compact('recipient', 'items', 'currency')];

        return [new PrintfulShippingRate('STANDARD', 'Fake standard', '1.00', $currency)];
    }

    public function count(string $kind): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call[0] === $kind));
    }
}
