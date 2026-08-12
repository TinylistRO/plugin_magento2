<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * A carrier tracking-status provider.
 *
 * A provider decides whether an (order, tracking number) pair belongs to it,
 * then fetches and normalizes that shipment's status from the carrier's API.
 *
 * Add a carrier by implementing this interface — usually by extending
 * {@see Provider\AbstractProvider} — and registering it in the
 * Tinylist\Dropshipping\Model\Carrier\ProviderPool `providers` argument in
 * etc/di.xml. It then shows up in the Enabled Carriers switch automatically;
 * no changes to the resolver, the service, or the Web API are needed.
 *
 * Implementations must be stateless: providers are DI singletons shared across
 * every lookup in a request, which is why match() returns the resolved carrier
 * code instead of stashing it on the instance.
 */
interface ProviderInterface
{
    /**
     * Label for the Enabled Carriers admin switch.
     */
    public function getLabel(): string;

    /**
     * Does this (order, trackNumber) pair belong to this carrier?
     *
     * @return string|null The matched Magento carrier code, or null if not ours.
     */
    public function match(OrderInterface $order, string $trackNumber): ?string;

    /**
     * Fetch and normalize the live status for a tracking number.
     *
     * @throws StatusException When the carrier module is missing, disabled or
     *         unconfigured, or the carrier API cannot answer.
     */
    public function fetch(string $trackNumber): Status;
}
