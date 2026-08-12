<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model;

use Tinylist\Dropshipping\Api\Data\TrackingResultInterface;
use Tinylist\Dropshipping\Api\OrderTrackingInterface;
use Tinylist\Dropshipping\Model\Carrier\StatusResolver;

/**
 * Web API entry point for carrier tracking status. All of the work is the
 * resolver's; this exists so the Web API surface stays a thin, stable contract
 * over it.
 */
class OrderTracking implements OrderTrackingInterface
{
    public function __construct(private readonly StatusResolver $resolver)
    {
    }

    /**
     * @param string $orderIncrementId
     * @param string $trackNumber
     * @return TrackingResultInterface
     */
    public function getStatus($orderIncrementId, $trackNumber)
    {
        return $this->resolver->resolve((string) $orderIncrementId, (string) $trackNumber);
    }
}
