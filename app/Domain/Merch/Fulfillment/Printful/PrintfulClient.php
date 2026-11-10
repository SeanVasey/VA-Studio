<?php

namespace App\Domain\Merch\Fulfillment\Printful;

/**
 * The only door to Printful. Anything that talks to Printful goes through this interface so that tests bind a fake and no
 * test or code path outside the HTTP implementation can reach the real API.
 *
 * `$recipient` is Printful's recipient object (name, address1, city, state_code, country_code, zip, ...); `$items` are
 * Printful v2 order items. Neither is interpreted here.
 */
interface PrintfulClient
{
    /**
     * Creates a DRAFT order carrying `$externalId`. A draft is not charged and not picked up for production.
     *
     * @param  array<string, mixed>  $recipient
     * @param  list<array<string, mixed>>  $items
     *
     * @throws PrintfulException
     */
    public function createDraftOrder(string $externalId, array $recipient, array $items, string $shipping): PrintfulOrder;

    /**
     * Confirms a draft for fulfillment (this charges the Printful account).
     *
     * @throws PrintfulException
     */
    public function confirmOrder(string $printfulOrderId): PrintfulOrder;

    /** Looks an order up by our external id; null only when Printful answers that it does not exist. */
    public function findOrderByExternalId(string $externalId): ?PrintfulOrder;

    /**
     * Quotes shipping for a destination and items. Rates are Printful's, never the store's.
     *
     * @param  array<string, mixed>  $recipient
     * @param  list<array<string, mixed>>  $items
     * @return list<PrintfulShippingRate>
     *
     * @throws PrintfulException
     */
    public function shippingRates(array $recipient, array $items, string $currency): array;
}
