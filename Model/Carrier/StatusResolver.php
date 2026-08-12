<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Tinylist\Dropshipping\Api\Data\TrackingResultInterface;
use Tinylist\Dropshipping\Model\Data\TrackingResultFactory;
use Tinylist\Dropshipping\Model\OrderLocator;

/**
 * Carrier tracking-status dispatcher.
 *
 * Given an (order number, tracking number) pair it:
 *   1. loads the order,
 *   2. asks each *enabled* provider whether the pair belongs to it — via
 *      Magento shipment tracks, or the carrier's own storage,
 *   3. asks the matching provider for the live status,
 *   4. returns an envelope. It never throws for an expected outcome: unknown
 *      order or tracking number, a switched-off carrier, a carrier module that
 *      is missing or unconfigured, an unreachable carrier API — all come back
 *      as success=false with an error_code.
 *
 * Which carriers are queried is the tinylist_dropshipping/carrier_status/
 * enabled_carriers switch, over the provider set declared in etc/di.xml.
 */
class StatusResolver
{
    public const ENABLED_CARRIERS_PATH = 'tinylist_dropshipping/carrier_status/enabled_carriers';

    public function __construct(
        private readonly ProviderPool $providerPool,
        private readonly OrderLocator $orderLocator,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TrackingResultFactory $resultFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(string $orderIncrementId, string $trackNumber): TrackingResultInterface
    {
        $orderIncrementId = trim($orderIncrementId);
        $trackNumber = trim($trackNumber);

        try {
            if ($orderIncrementId === '' || $trackNumber === '') {
                throw new StatusException(
                    new Phrase('Both an order number and a tracking number are required.'),
                    'missing_parameters'
                );
            }

            $order = $this->orderLocator->getByIncrementId($orderIncrementId);
            if ($order === null) {
                throw new StatusException(
                    new Phrase('Order "%1" was not found.', [$orderIncrementId]),
                    'order_not_found'
                );
            }

            [$key, $provider, $carrierCode] = $this->resolveProvider($order, $trackNumber, $orderIncrementId);

            $status = $provider->fetch($trackNumber);

            return $this->resultFactory->create([
                'success' => true,
                'orderIncrementId' => $orderIncrementId,
                'trackNumber' => $trackNumber,
                'carrierCode' => $carrierCode,
                'carrier' => $key,
                'status' => $status->current,
                'delivered' => $status->delivered,
                'canceled' => $status->canceled,
                'deliveryAttempts' => $status->deliveryAttempts,
                'deliveredAt' => $status->deliveredAt,
                'history' => $status->history,
            ]);
        } catch (StatusException $e) {
            return $this->error($orderIncrementId, $trackNumber, $e->getMessage(), $e->getErrorCode());
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf(
                    'Tinylist tracking status failed for order %s / track %s: %s',
                    $orderIncrementId,
                    $trackNumber,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );

            return $this->error(
                $orderIncrementId,
                $trackNumber,
                (string) new Phrase('An unexpected error occurred while fetching the tracking status.'),
                'unexpected_error'
            );
        }
    }

    /**
     * First enabled provider that claims the pair.
     *
     * When none does, the disabled providers get a look too — purely so a
     * shipment on a carrier the merchant has switched off reports
     * carrier_not_enabled rather than the misleading tracking_not_found.
     *
     * @return array{0: string, 1: ProviderInterface, 2: string}
     * @throws StatusException
     */
    private function resolveProvider(OrderInterface $order, string $trackNumber, string $orderIncrementId): array
    {
        $enabled = $this->getEnabledCarrierKeys();

        foreach ($this->providerPool->getAll() as $key => $provider) {
            if (!in_array($key, $enabled, true)) {
                continue;
            }
            $carrierCode = $provider->match($order, $trackNumber);
            if ($carrierCode !== null) {
                return [$key, $provider, $carrierCode];
            }
        }

        foreach ($this->providerPool->getAll() as $key => $provider) {
            if (in_array($key, $enabled, true)) {
                continue;
            }
            if ($provider->match($order, $trackNumber) !== null) {
                throw new StatusException(
                    new Phrase('%1 is not switched on for tracking lookups on this store.', [$provider->getLabel()]),
                    'carrier_not_enabled'
                );
            }
        }

        throw new StatusException(
            new Phrase('Tracking number "%1" was not found on order "%2".', [$trackNumber, $orderIncrementId]),
            'tracking_not_found'
        );
    }

    /**
     * @return string[]
     */
    private function getEnabledCarrierKeys(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::ENABLED_CARRIERS_PATH);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    private function error(
        string $orderIncrementId,
        string $trackNumber,
        string $message,
        string $errorCode
    ): TrackingResultInterface {
        return $this->resultFactory->create([
            'success' => false,
            'orderIncrementId' => $orderIncrementId,
            'trackNumber' => $trackNumber,
            'error' => $message,
            'errorCode' => $errorCode,
        ]);
    }
}
