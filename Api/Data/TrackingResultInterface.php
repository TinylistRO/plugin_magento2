<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Api\Data;

/**
 * Carrier tracking status envelope.
 *
 * Field names mirror the Magento 1 connector's JSON envelope, so a consumer can
 * parse both with the same code. The difference is that M2 returns a real
 * object rather than a JSON string, so REST callers get it inline and SOAP
 * callers get a typed struct.
 *
 * On success the error fields are null; on failure everything but
 * order_increment_id / track_number / error / error_code is null or empty.
 */
interface TrackingResultInterface
{
    /**
     * Whether a carrier status was resolved.
     *
     * @return bool
     */
    public function getSuccess();

    /**
     * The requested order increment id, echoed back.
     *
     * @return string|null
     */
    public function getOrderIncrementId();

    /**
     * The requested tracking number, echoed back.
     *
     * @return string|null
     */
    public function getTrackNumber();

    /**
     * Magento carrier code the tracking number was matched on, e.g. "samedaycourier".
     *
     * @return string|null
     */
    public function getCarrierCode();

    /**
     * Tinylist provider key that answered, e.g. "sameday".
     *
     * @return string|null
     */
    public function getCarrier();

    /**
     * The shipment's current status.
     *
     * @return \Tinylist\Dropshipping\Api\Data\TrackingEventInterface|null
     */
    public function getStatus();

    /**
     * Whether the carrier reports the parcel as delivered. This flag — not the
     * history text — is the authoritative delivery signal.
     *
     * @return bool
     */
    public function getDelivered();

    /**
     * Whether the carrier reports the shipment as canceled or returned.
     *
     * @return bool
     */
    public function getCanceled();

    /**
     * Delivery attempts reported by the carrier, when it reports any.
     *
     * @return int|null
     */
    public function getDeliveryAttempts();

    /**
     * Carrier's delivery timestamp, when delivered.
     *
     * @return string|null
     */
    public function getDeliveredAt();

    /**
     * Chronological status history, oldest first. Empty when the carrier has no
     * scans yet, or on failure.
     *
     * @return \Tinylist\Dropshipping\Api\Data\TrackingEventInterface[]
     */
    public function getHistory();

    /**
     * Human-readable failure reason. Null on success.
     *
     * @return string|null
     */
    public function getError();

    /**
     * Machine-readable failure code. Null on success. One of:
     * missing_parameters, order_not_found, tracking_not_found,
     * carrier_not_enabled, carrier_module_unavailable, carrier_not_configured,
     * carrier_auth_failed, carrier_unreachable, carrier_bad_response,
     * carrier_status_unavailable, unexpected_error.
     *
     * @return string|null
     */
    public function getErrorCode();
}
