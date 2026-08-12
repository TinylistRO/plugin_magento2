<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Data;

use Tinylist\Dropshipping\Api\Data\TrackingEventInterface;
use Tinylist\Dropshipping\Api\Data\TrackingResultInterface;

/**
 * Immutable {@see TrackingResultInterface}. Built through the generated
 * TrackingResultFactory.
 */
class TrackingResult implements TrackingResultInterface
{
    /**
     * @param TrackingEventInterface[] $history
     */
    public function __construct(
        private readonly bool $success = false,
        private readonly ?string $orderIncrementId = null,
        private readonly ?string $trackNumber = null,
        private readonly ?string $carrierCode = null,
        private readonly ?string $carrier = null,
        private readonly ?TrackingEventInterface $status = null,
        private readonly bool $delivered = false,
        private readonly bool $canceled = false,
        private readonly ?int $deliveryAttempts = null,
        private readonly ?string $deliveredAt = null,
        private readonly array $history = [],
        private readonly ?string $error = null,
        private readonly ?string $errorCode = null,
    ) {
    }

    public function getSuccess(): bool
    {
        return $this->success;
    }

    public function getOrderIncrementId(): ?string
    {
        return $this->orderIncrementId;
    }

    public function getTrackNumber(): ?string
    {
        return $this->trackNumber;
    }

    public function getCarrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function getCarrier(): ?string
    {
        return $this->carrier;
    }

    public function getStatus(): ?TrackingEventInterface
    {
        return $this->status;
    }

    public function getDelivered(): bool
    {
        return $this->delivered;
    }

    public function getCanceled(): bool
    {
        return $this->canceled;
    }

    public function getDeliveryAttempts(): ?int
    {
        return $this->deliveryAttempts;
    }

    public function getDeliveredAt(): ?string
    {
        return $this->deliveredAt;
    }

    /**
     * @return TrackingEventInterface[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
