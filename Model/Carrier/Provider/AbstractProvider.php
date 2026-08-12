<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier\Provider;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Throwable;
use Tinylist\Dropshipping\Api\Data\TrackingEventInterface;
use Tinylist\Dropshipping\Model\Carrier\ProviderInterface;
use Tinylist\Dropshipping\Model\Carrier\StatusException;

/**
 * Shared behaviour for carrier tracking-status providers: matching a tracking
 * number against Magento's shipment tracks, HTTP, store config reads, and the
 * guards that turn a missing or unconfigured carrier module into a friendly,
 * caller-facing error.
 *
 * Every module name, config path and carrier code is a constructor argument set
 * in etc/di.xml, so retargeting a provider at a differently-configured carrier
 * module is an XML change, never a code change.
 */
abstract class AbstractProvider implements ProviderInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TIMEOUT_SECONDS = 20;

    /**
     * @param string[] $carrierCodes Magento carrier codes this provider owns.
     */
    public function __construct(
        protected readonly Context $context,
        private readonly string $label,
        private readonly array $carrierCodes = [],
        private readonly string $moduleName = '',
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The Magento carrier codes this provider owns, in di.xml order. The first
     * is the canonical one, used when a match came from the carrier's own table
     * rather than from a Magento shipment track.
     *
     * @return string[]
     */
    protected function ownedCarrierCodes(): array
    {
        return array_values($this->carrierCodes);
    }

    /**
     * Default: the pair matches when the order has a Magento shipment track with
     * this tracking number on one of our carrier codes.
     *
     * Carriers that keep their AWB in their own table (Sameday, DPD) extend this
     * rather than replace it — a store may well have both.
     */
    public function match(OrderInterface $order, string $trackNumber): ?string
    {
        return $this->matchShipmentTrack($order, $trackNumber);
    }

    /**
     * @return string|null The matched carrier code.
     */
    protected function matchShipmentTrack(OrderInterface $order, string $trackNumber): ?string
    {
        $orderId = (int) $order->getEntityId();
        if ($orderId === 0 || $this->carrierCodes === []) {
            return null;
        }

        $criteria = $this->context->searchCriteriaBuilder
            ->addFilter('order_id', $orderId)
            ->addFilter('track_number', $trackNumber)
            ->create();

        $owned = array_map('strtolower', $this->carrierCodes);

        foreach ($this->context->trackRepository->getList($criteria)->getItems() as $track) {
            $code = (string) $track->getCarrierCode();
            if (in_array(strtolower($code), $owned, true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Is this tracking number recorded against the order in a carrier module's
     * own table?
     *
     * Matched in SQL rather than by loading "the order's AWB" and comparing,
     * so a multi-parcel order — several rows for one order — matches on any of
     * its numbers, not just the newest.
     *
     * Returns false when the table is absent, so a store that does not run the
     * carrier module never hits a SQL error.
     *
     * @param string $table Unprefixed table name.
     * @param string $valueColumn Column holding the AWB / shipment id.
     * @param string $orderColumn Column holding the Magento order entity id.
     */
    protected function existsInCarrierTable(
        string $table,
        string $valueColumn,
        string $orderColumn,
        int $orderId,
        string $trackNumber
    ): bool {
        if ($orderId === 0 || $trackNumber === '') {
            return false;
        }

        $connection = $this->context->resourceConnection->getConnection();
        $tableName = $this->context->resourceConnection->getTableName($table);

        if (!$connection->isTableExists($tableName)) {
            return false;
        }

        $select = $connection->select()
            ->from($tableName, [$valueColumn])
            ->where($connection->quoteIdentifier($orderColumn) . ' = ?', $orderId)
            ->where($connection->quoteIdentifier($valueColumn) . ' = ?', $trackNumber)
            ->limit(1);

        return $connection->fetchOne($select) !== false;
    }

    /**
     * Guard: the carrier's own shipping module is installed and enabled.
     *
     * @throws StatusException
     */
    protected function assertModuleEnabled(): void
    {
        if ($this->moduleName === '') {
            return;
        }

        if (!$this->context->moduleManager->isEnabled($this->moduleName)) {
            $this->fail(
                new Phrase(
                    'The %1 shipping module is not installed or is disabled on this store.',
                    [$this->getLabel()]
                ),
                'carrier_module_unavailable'
            );
        }
    }

    protected function getConfig(string $path): ?string
    {
        $value = $this->context->scopeConfig->getValue($path);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * A config value stored through Magento's "obscure" backend model, i.e.
     * encrypted at rest. Returns null when unset so callers can report
     * carrier_not_configured rather than authenticating with an empty string.
     */
    protected function getEncryptedConfig(string $path): ?string
    {
        $raw = $this->getConfig($path);
        if ($raw === null) {
            return null;
        }

        $decrypted = $this->context->encryptor->decrypt($raw);

        return $decrypted === '' ? null : $decrypted;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body Form array or raw body; null for GET.
     * @return string Raw response body.
     * @throws StatusException On any transport failure.
     */
    protected function request(string $method, string $url, array $headers = [], array|string|null $body = null): string
    {
        $curl = $this->context->curlFactory->create();
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($headers !== []) {
            $curl->setHeaders($headers);
        }

        try {
            if (strtoupper($method) === 'POST') {
                $curl->post($url, $body ?? '');
            } else {
                $curl->get($url);
            }

            return (string) $curl->getBody();
        } catch (Throwable $e) {
            $this->fail(
                new Phrase('Could not reach %1: %2', [$this->getLabel(), $e->getMessage()]),
                'carrier_unreachable'
            );
        }
    }

    /**
     * @return array<string, mixed>
     * @throws StatusException When the body is not a JSON object.
     */
    protected function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            $this->fail(
                new Phrase('%1 returned an unexpected response.', [$this->getLabel()]),
                'carrier_bad_response'
            );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function makeEvent(array $data): TrackingEventInterface
    {
        return $this->context->eventFactory->create($data);
    }

    protected function toStringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @throws StatusException
     */
    protected function fail(Phrase $message, string $errorCode): never
    {
        throw new StatusException($message, $errorCode);
    }
}
