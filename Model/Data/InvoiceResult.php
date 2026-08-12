<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Data;

use Tinylist\Dropshipping\Api\Data\InvoiceResultInterface;

/**
 * Immutable {@see InvoiceResultInterface}. Built through the generated
 * InvoiceResultFactory.
 */
class InvoiceResult implements InvoiceResultInterface
{
    public function __construct(
        private readonly bool $available = false,
        private readonly ?string $pdfBase64 = null,
        private readonly ?string $invoiceNumber = null,
        private readonly ?string $issuedAt = null,
        private readonly ?string $currency = null,
        private readonly ?string $source = null,
    ) {
    }

    public function getAvailable(): bool
    {
        return $this->available;
    }

    public function getPdfBase64(): ?string
    {
        return $this->pdfBase64;
    }

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function getIssuedAt(): ?string
    {
        return $this->issuedAt;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}
