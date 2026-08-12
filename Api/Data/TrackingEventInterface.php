<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Api\Data;

/**
 * One carrier status entry — either the shipment's current status or a single
 * point in its history.
 *
 * Field names match the Magento 1 connector's history entries exactly, so a
 * consumer can parse both with the same code.
 *
 * Every carrier's native status code is passed through untranslated, so `code`
 * is only meaningful within one carrier. Classify on `state`/`status`/`label`
 * text, or on the envelope's own delivered/canceled flags.
 */
interface TrackingEventInterface
{
    /**
     * Carrier's native status code, stringified (carriers disagree on int vs
     * string, and SOAP needs one type).
     *
     * @return string|null
     */
    public function getCode();

    /**
     * Carrier's machine-ish status name, e.g. "in_transit".
     *
     * @return string|null
     */
    public function getStatus();

    /**
     * Carrier's human-readable label, usually localised by the carrier.
     *
     * @return string|null
     */
    public function getLabel();

    /**
     * Coarser lifecycle state, when the carrier reports one.
     *
     * @return string|null
     */
    public function getState();

    /**
     * When this status was recorded, in the carrier's own format.
     *
     * @return string|null
     */
    public function getDate();

    /**
     * County / region reported for this scan.
     *
     * @return string|null
     */
    public function getCounty();

    /**
     * Carrier-supplied reason, typically only set on failures.
     *
     * @return string|null
     */
    public function getReason();

    /**
     * Transit hub or delivery location for this scan.
     *
     * @return string|null
     */
    public function getTransitLocation();
}
