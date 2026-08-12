<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Data;

use Tinylist\Dropshipping\Api\Data\TrackingEventInterface;

/**
 * Immutable {@see TrackingEventInterface}. Built through the generated
 * TrackingEventFactory, which maps the array keys onto the constructor
 * arguments by name.
 */
class TrackingEvent implements TrackingEventInterface
{
    public function __construct(
        private readonly ?string $code = null,
        private readonly ?string $status = null,
        private readonly ?string $label = null,
        private readonly ?string $state = null,
        private readonly ?string $date = null,
        private readonly ?string $county = null,
        private readonly ?string $reason = null,
        private readonly ?string $transitLocation = null,
    ) {
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getDate(): ?string
    {
        return $this->date;
    }

    public function getCounty(): ?string
    {
        return $this->county;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getTransitLocation(): ?string
    {
        return $this->transitLocation;
    }
}
