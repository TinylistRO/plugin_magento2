<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Api;

/**
 * Web API service contract: an order's invoice as a PDF.
 *
 * Exposed at GET /rest/V1/tinylist/orders/:orderIncrementId/invoice (and on the
 * generated SOAP WSDL) — see etc/webapi.xml.
 */
interface OrderInvoiceInterface
{
    /**
     * Retrieve the invoice document for an order.
     *
     * "No invoice issued yet" is reported in-band as available=false rather than
     * as an HTTP error, because it is an expected state the caller polls through.
     * An unknown order is a genuine 404.
     *
     * @param string $orderIncrementId Order increment id, e.g. "100000123".
     * @return \Tinylist\Dropshipping\Api\Data\InvoiceResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException When no order has that increment id.
     */
    public function get($orderIncrementId);
}
