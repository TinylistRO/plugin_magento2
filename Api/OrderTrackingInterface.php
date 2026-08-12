<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Api;

/**
 * Web API service contract: live carrier status for an (order, tracking) pair.
 *
 * Exposed at GET /rest/V1/tinylist/orders/:orderIncrementId/tracking/:trackNumber
 * (and on the generated SOAP WSDL) — see etc/webapi.xml.
 */
interface OrderTrackingInterface
{
    /**
     * Resolve a tracking number to its carrier's current status.
     *
     * Never throws for an expected outcome. Unknown order or tracking number, a
     * carrier that is switched off, a carrier module that is missing or
     * unconfigured, an unreachable carrier API — all come back as
     * success=false with an error/error_code, so a polling caller can tell
     * "nothing yet" from "the request itself failed".
     *
     * @param string $orderIncrementId Order increment id, e.g. "100000123".
     * @param string $trackNumber Tracking / AWB number.
     * @return \Tinylist\Dropshipping\Api\Data\TrackingResultInterface
     */
    public function getStatus($orderIncrementId, $trackNumber);
}
