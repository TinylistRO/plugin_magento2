<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A caller-facing failure while resolving a carrier tracking status.
 *
 * {@see StatusResolver} turns this into a success=false envelope rather than
 * an HTTP error, because every case it covers is an expected outcome for a
 * polling caller — not a broken request.
 */
class StatusException extends LocalizedException
{
    public function __construct(Phrase $phrase, private readonly string $errorCode = 'error')
    {
        parent::__construct($phrase);
    }

    /**
     * Machine-readable code, e.g. "carrier_not_configured".
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
