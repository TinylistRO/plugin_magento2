<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier;

use Tinylist\Dropshipping\Api\Data\TrackingEventInterface;

/**
 * A provider's normalized answer, before {@see StatusResolver} wraps it in the
 * public envelope. Internal to the module — not part of the Web API contract.
 */
final class Status
{
    /**
     * @param TrackingEventInterface[] $history Oldest first.
     */
    public function __construct(
        public readonly TrackingEventInterface $current,
        public readonly bool $delivered = false,
        public readonly bool $canceled = false,
        public readonly ?int $deliveryAttempts = null,
        public readonly ?string $deliveredAt = null,
        public readonly array $history = [],
    ) {
    }
}
