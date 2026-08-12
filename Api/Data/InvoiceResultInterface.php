<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Api\Data;

/**
 * Invoice document envelope.
 *
 * Getter names drive the serialized field names: available, pdf_base64,
 * invoice_number, issued_at, currency, source.
 */
interface InvoiceResultInterface
{
    /**
     * Whether a rendered invoice is attached. False means the order exists but
     * has no invoice yet — poll again later.
     *
     * @return bool
     */
    public function getAvailable();

    /**
     * The invoice PDF, base64 encoded. Null when available=false.
     *
     * All of the order's invoices are rendered into this single document.
     *
     * @return string|null
     */
    public function getPdfBase64();

    /**
     * Increment id of the most recent invoice on the order, e.g. "100000045".
     *
     * @return string|null
     */
    public function getInvoiceNumber();

    /**
     * Creation timestamp of the most recent invoice, as stored by Magento
     * (UTC, "Y-m-d H:i:s").
     *
     * @return string|null
     */
    public function getIssuedAt();

    /**
     * Order currency code, e.g. "RON".
     *
     * @return string|null
     */
    public function getCurrency();

    /**
     * What produced the document. Always "magento" here; the field exists so a
     * store that later fronts an external fiscal invoicing provider can say so.
     *
     * @return string|null
     */
    public function getSource();
}
