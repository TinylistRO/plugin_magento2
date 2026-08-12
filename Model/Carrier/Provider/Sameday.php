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
 * Sameday Courier tracking-status provider.
 *
 * The official SamedayCourier_Shipping module exposes no status method, so this
 * provider reuses that module's stored credentials and calls the Sameday eAWB
 * status endpoint directly:
 *
 *   POST {host}/api/authenticate               X-AUTH-USERNAME / X-AUTH-PASSWORD -> token
 *   GET  {host}/api/client/awb/{awb}/status    X-AUTH-TOKEN
 *
 * The Sameday module itself is left untouched.
 *
 * Two things differ from the Magento 1 Blugento module this was ported from:
 * the carrier code is `samedaycourier` (not `bgsamedaycourier`/`bgsamedayeasybox`
 * — lockers are a shipping method within the one carrier here), and the password
 * is stored encrypted, so it goes through the Encryptor.
 *
 * Sameday also runs per-country stacks (RO/HU/BG). The host map and the country
 * are di.xml arguments; RO is the default.
 */
class Sameday extends AbstractProvider
{
    /** Sameday's own module stores the AWB here, keyed by order entity id. */
    private const AWB_TABLE = 'samedaycourier_shipping_awb';

    /**
     * @param array<string, array{0: string, 1: string}> $hosts country => [production, demo]
     * @param string[] $carrierCodes
     */
    public function __construct(
        Context $context,
        string $label,
        array $carrierCodes,
        string $moduleName,
        private readonly string $usernamePath,
        private readonly string $passwordPath,
        private readonly string $testModePath,
        private readonly array $hosts,
        private readonly string $hostCountry = 'RO',
    ) {
        parent::__construct($context, $label, $carrierCodes, $moduleName);
    }

    /**
     * Sameday's module writes a Magento shipment track *and* keeps its own AWB
     * row; which one exists depends on how the label was created, so check both.
     */
    public function match(OrderInterface $order, string $trackNumber): ?string
    {
        $matched = parent::match($order, $trackNumber);
        if ($matched !== null) {
            return $matched;
        }

        $found = $this->existsInCarrierTable(
            self::AWB_TABLE,
            'awb_number',
            'order_id',
            (int) $order->getEntityId(),
            $trackNumber
        );

        return $found ? $this->defaultCarrierCode() : null;
    }

    public function fetch(string $trackNumber): Status
    {
        $this->assertModuleEnabled();

        $token = $this->authenticate();

        $response = $this->decodeJson($this->request(
            'GET',
            $this->host() . '/api/client/awb/' . rawurlencode($trackNumber) . '/status',
            ['X-AUTH-TOKEN' => $token]
        ));

        // Sameday answers 4xx-with-body rather than a transport error when it has
        // no scans for an AWB yet.
        if (isset($response['error']) || (isset($response['status']) && (int) $response['status'] >= 400)) {
            $this->fail(
                new Phrase('Sameday Courier has no status for AWB "%1" yet.', [$trackNumber]),
                'carrier_status_unavailable'
            );
        }

        return $this->normalize($response);
    }

    private function authenticate(): string
    {
        $username = $this->getConfig($this->usernamePath);
        $password = $this->getEncryptedConfig($this->passwordPath);

        if ($username === null || $password === null) {
            $this->fail(
                new Phrase('Sameday Courier is not configured yet — the API username or password is missing.'),
                'carrier_not_configured'
            );
        }

        $response = $this->decodeJson($this->request(
            'POST',
            $this->host() . '/api/authenticate',
            [
                'X-AUTH-USERNAME' => $username,
                'X-AUTH-PASSWORD' => $password,
            ],
            ''
        ));

        if (empty($response['token']) || !is_string($response['token'])) {
            $this->fail(
                new Phrase('Could not authenticate with Sameday Courier.%1', [$this->authDetail($response)]),
                'carrier_auth_failed'
            );
        }

        return $response['token'];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function authDetail(array $response): string
    {
        if (!isset($response['error'])) {
            return '';
        }

        $error = $response['error'];

        return ' (' . (is_array($error) ? implode('; ', array_map('strval', $error)) : (string) $error) . ')';
    }

    /**
     * @param array<string, mixed> $response
     */
    private function normalize(array $response): Status
    {
        $expedition = is_array($response['expeditionStatus'] ?? null) ? $response['expeditionStatus'] : [];
        $summary = is_array($response['expeditionSummary'] ?? null) ? $response['expeditionSummary'] : [];

        $history = [];
        foreach ($response['expeditionHistory'] ?? [] as $entry) {
            if (is_array($entry)) {
                $history[] = $this->mapStatus($entry);
            }
        }

        return new Status(
            current: $this->mapStatus($expedition),
            delivered: !empty($summary['delivered']),
            canceled: !empty($summary['canceled']),
            deliveryAttempts: isset($summary['deliveryAttempts']) ? (int) $summary['deliveryAttempts'] : null,
            deliveredAt: $this->toStringOrNull($summary['deliveredAt'] ?? null),
            history: $history,
        );
    }

    /**
     * @param array<string, mixed> $status
     */
    private function mapStatus(array $status): TrackingEventInterface
    {
        return $this->makeEvent([
            'code' => $this->toStringOrNull($status['statusId'] ?? null),
            'status' => $this->toStringOrNull($status['status'] ?? null),
            'label' => $this->toStringOrNull($status['statusLabel'] ?? null),
            'state' => $this->toStringOrNull($status['statusState'] ?? null),
            'date' => $this->toStringOrNull($status['statusDate'] ?? null),
            'county' => $this->toStringOrNull($status['county'] ?? null),
            'reason' => $this->toStringOrNull($status['reason'] ?? null),
            'transitLocation' => $this->toStringOrNull($status['transitLocation'] ?? null),
        ]);
    }

    /**
     * Production or demo host for the configured country. Falls back to RO, then
     * to the first configured entry, so a misconfigured country never yields an
     * empty URL.
     */
    private function host(): string
    {
        $entry = $this->hosts[$this->hostCountry] ?? $this->hosts['RO'] ?? reset($this->hosts);

        if (!is_array($entry) || $entry === []) {
            $this->fail(
                new Phrase('No Sameday Courier API host is configured for country "%1".', [$this->hostCountry]),
                'carrier_not_configured'
            );
        }

        $isDemo = (int) ($this->getConfig($this->testModePath) ?? '0') === 1;

        return rtrim((string) ($isDemo ? ($entry[1] ?? $entry[0]) : $entry[0]), '/');
    }

    private function defaultCarrierCode(): string
    {
        return $this->ownedCarrierCodes()[0] ?? 'samedaycourier';
    }
}
