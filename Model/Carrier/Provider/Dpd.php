<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier\Provider;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Tinylist\Dropshipping\Api\Data\TrackingEventInterface;
use Tinylist\Dropshipping\Model\Carrier\Status;

/**
 * DPD Romania tracking-status provider.
 *
 * DpdRo_Shipping keeps its shipment id in its own `dpdro_order_shipment` table
 * keyed by Magento order entity id, and its credentials as name/value rows in
 * `dpdro_settings` — neither in store config. Both are read directly rather
 * than through the module's own model, so this module has no hard class
 * dependency on an extension that may not be installed.
 *
 * DPD takes the credentials in the request body; there is no token:
 *   POST https://api.dpd.ro/v1/track/  { userName, password, parcels: [{id}] }
 *
 * The DPD module itself is left untouched.
 */
class Dpd extends AbstractProvider
{
    private const SHIPMENT_TABLE = 'dpdro_order_shipment';
    private const SETTINGS_TABLE = 'dpdro_settings';

    /** Operation code for "Delivered" in the DPD/Speedy code set. */
    private const CODE_DELIVERED = '-14';

    /** 124 back to sender, 125 destroyed, 127 theft, 128 canceled, 129 admin closure. */
    private const CODES_CANCELED = ['124', '125', '127', '128', '129'];

    /**
     * @param string[] $carrierCodes
     */
    public function __construct(
        Context $context,
        string $label,
        array $carrierCodes,
        string $moduleName,
        private readonly string $apiUrl,
    ) {
        parent::__construct($context, $label, $carrierCodes, $moduleName);
    }

    /**
     * DPD writes its shipment id to its own table; older setups may also have a
     * Magento shipment track, so both are checked.
     */
    public function match(OrderInterface $order, string $trackNumber): ?string
    {
        $matched = parent::match($order, $trackNumber);
        if ($matched !== null) {
            return $matched;
        }

        $found = $this->existsInCarrierTable(
            self::SHIPMENT_TABLE,
            'shipmentID',
            'orderID',
            (int) $order->getEntityId(),
            $trackNumber
        );

        return $found ? $this->defaultCarrierCode() : null;
    }

    public function fetch(string $trackNumber): Status
    {
        $this->assertModuleEnabled();

        [$username, $password] = $this->credentials();

        $payload = json_encode([
            'userName' => $username,
            'password' => $password,
            'language' => 'EN',
            'parcels' => [['id' => $trackNumber]],
            'lastOperationOnly' => false,
        ], JSON_THROW_ON_ERROR);

        $response = $this->decodeJson($this->request(
            'POST',
            rtrim($this->apiUrl, '/') . '/track/',
            ['Content-Type' => 'application/json'],
            $payload
        ));

        if (isset($response['error'])) {
            $this->fail(
                new Phrase('DPD could not return a status: %1', [$this->errorMessage($response['error'])]),
                'carrier_status_unavailable'
            );
        }

        return $this->normalize($trackNumber, $response);
    }

    /**
     * Credentials live as name/value rows in the DPD module's own table.
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        $connection = $this->context->resourceConnection->getConnection();
        $table = $this->context->resourceConnection->getTableName(self::SETTINGS_TABLE);

        $settings = $connection->isTableExists($table)
            ? $connection->fetchPairs(
                $connection->select()
                    ->from($table, ['name', 'value'])
                    ->where('name IN (?)', ['username', 'password'])
            )
            : [];

        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->fail(
                new Phrase('DPD Romania is not configured yet — the API username or password is missing.'),
                'carrier_not_configured'
            );
        }

        return [$username, $password];
    }

    private function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            return isset($error['message']) ? (string) $error['message'] : (string) json_encode($error);
        }

        return is_scalar($error) ? (string) $error : 'unknown error';
    }

    /**
     * @param array<string, mixed> $response
     */
    private function normalize(string $trackNumber, array $response): Status
    {
        $parcel = $this->pickParcel($trackNumber, is_array($response['parcels'] ?? null) ? $response['parcels'] : []);

        $history = [];
        foreach ($parcel['operations'] ?? [] as $operation) {
            if (is_array($operation)) {
                $history[] = $this->mapOperation($operation);
            }
        }

        $current = $history === [] ? $this->mapOperation([]) : $history[count($history) - 1];
        $lastCode = (string) $current->getCode();
        $delivered = $lastCode === self::CODE_DELIVERED;

        return new Status(
            current: $current,
            delivered: $delivered,
            canceled: in_array($lastCode, self::CODES_CANCELED, true),
            deliveryAttempts: null,
            deliveredAt: $delivered ? $current->getDate() : null,
            history: $history,
        );
    }

    /**
     * @param array<int, mixed> $parcels
     * @return array<string, mixed>
     */
    private function pickParcel(string $trackNumber, array $parcels): array
    {
        foreach ($parcels as $candidate) {
            if (is_array($candidate) && isset($candidate['id']) && (string) $candidate['id'] === $trackNumber) {
                return $candidate;
            }
        }

        $first = reset($parcels);
        if (is_array($first)) {
            return $first;
        }

        $this->fail(
            new Phrase('DPD has no tracking data for "%1" yet.', [$trackNumber]),
            'carrier_status_unavailable'
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function mapOperation(array $operation): TrackingEventInterface
    {
        $description = $this->toStringOrNull($operation['description'] ?? null)
            ?? $this->toStringOrNull($operation['name'] ?? null);

        return $this->makeEvent([
            'code' => $this->toStringOrNull($operation['operationCode'] ?? null)
                ?? $this->toStringOrNull($operation['code'] ?? null),
            'status' => $description,
            'label' => $description,
            'state' => null,
            'date' => $this->toStringOrNull($operation['date'] ?? null),
            'county' => null,
            'reason' => null,
            'transitLocation' => null,
        ]);
    }

    private function defaultCarrierCode(): string
    {
        return $this->ownedCarrierCodes()[0] ?? 'dpdro_shipping';
    }
}
