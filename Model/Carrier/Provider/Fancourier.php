<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier\Provider;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Phrase;
use Tinylist\Dropshipping\Api\Data\TrackingEventInterface;
use Tinylist\Dropshipping\Model\Carrier\Status;

/**
 * FAN Courier tracking-status provider.
 *
 * FAN's Magento 2 module exposes no status method, so this provider reuses its
 * stored credentials and calls the FAN Courier v2 API directly:
 *   POST /login?username=..&password=..              -> data.token
 *   GET  /reports/awb/tracking?clientId=..&awb[]=..   Authorization: Bearer <token>
 *
 * The FAN module itself is left untouched.
 *
 * Unlike Sameday and DPD, FAN Courier's Magento 2 module is distributed as a
 * closed-source archive (FANCourier_Customshipping) rather than a public
 * repository, so its module name and config paths could not be verified against
 * source. They are all di.xml arguments — see the note in README.md; correcting
 * them against a real store is an XML change, no code involved.
 *
 * The Magento 1 provider reused the FAN module's own 20h token cache; there is
 * no such shared helper here, so the token is cached in Magento's own cache
 * under a short TTL.
 */
class Fancourier extends AbstractProvider
{
    private const CACHE_KEY = 'tinylist_dropshipping_fancourier_token';
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @param string[] $carrierCodes
     */
    public function __construct(
        Context $context,
        string $label,
        array $carrierCodes,
        string $moduleName,
        private readonly CacheInterface $cache,
        private readonly string $apiUrl,
        private readonly string $clientIdPath,
        private readonly string $usernamePath,
        private readonly string $passwordPath,
    ) {
        parent::__construct($context, $label, $carrierCodes, $moduleName);
    }

    public function fetch(string $trackNumber): Status
    {
        $this->assertModuleEnabled();

        $clientId = $this->getConfig($this->clientIdPath);
        if ($clientId === null) {
            $this->fail(
                new Phrase('FAN Courier is not configured yet — the client id is missing.'),
                'carrier_not_configured'
            );
        }

        $url = rtrim($this->apiUrl, '/') . '/reports/awb/tracking'
            . '?clientId=' . rawurlencode($clientId)
            . '&awb[]=' . rawurlencode($trackNumber);

        $response = $this->decodeJson($this->request('GET', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token(),
        ]));

        if (($response['status'] ?? null) !== 'success') {
            $this->fail(
                new Phrase(
                    'FAN Courier could not return a status: %1',
                    [$this->toStringOrNull($response['message'] ?? null) ?? 'no status available']
                ),
                'carrier_status_unavailable'
            );
        }

        $entry = $this->pickAwb($trackNumber, is_array($response['data'] ?? null) ? $response['data'] : []);

        return $this->normalize($entry);
    }

    /**
     * Cached bearer token; logs in when absent or expired.
     */
    private function token(): string
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $username = $this->getConfig($this->usernamePath);
        $password = $this->getEncryptedConfig($this->passwordPath) ?? $this->getConfig($this->passwordPath);

        if ($username === null || $password === null) {
            $this->fail(
                new Phrase('FAN Courier is not configured yet — the API username or password is missing.'),
                'carrier_not_configured'
            );
        }

        $url = rtrim($this->apiUrl, '/') . '/login'
            . '?username=' . rawurlencode($username)
            . '&password=' . rawurlencode($password);

        $body = $this->decodeJson($this->request('POST', $url, ['Content-Type' => 'application/json'], ''));
        $token = $this->toStringOrNull($body['data']['token'] ?? null);

        if ($token === null) {
            $this->fail(new Phrase('Could not authenticate with FAN Courier.'), 'carrier_auth_failed');
        }

        $this->cache->save($token, self::CACHE_KEY, [], self::CACHE_TTL_SECONDS);

        return $token;
    }

    /**
     * @param array<int, mixed> $data Per-AWB tracking objects.
     * @return array<string, mixed>
     */
    private function pickAwb(string $trackNumber, array $data): array
    {
        foreach ($data as $entry) {
            if (is_array($entry) && isset($entry['awbNumber']) && (string) $entry['awbNumber'] === $trackNumber) {
                return $entry;
            }
        }

        $first = reset($data);
        if (is_array($first)) {
            return $first;
        }

        $this->fail(
            new Phrase('FAN Courier has no status for AWB "%1" yet.', [$trackNumber]),
            'carrier_status_unavailable'
        );
    }

    /**
     * FAN reports no delivery flag, so delivery is inferred from the latest
     * event's Romanian status wording — the same heuristic the Magento 1
     * provider used.
     *
     * @param array<string, mixed> $entry
     */
    private function normalize(array $entry): Status
    {
        $history = [];
        foreach ($entry['events'] ?? [] as $event) {
            if (is_array($event)) {
                $history[] = $this->mapEvent($event);
            }
        }

        $current = $history === []
            ? $this->mapEvent(['name' => $entry['message'] ?? null])
            : $history[count($history) - 1];

        $name = (string) $current->getStatus();
        $delivered = (bool) preg_match('/livrat/iu', $name);

        return new Status(
            current: $current,
            delivered: $delivered,
            canceled: (bool) preg_match('/retur|refuz|anulat/iu', $name),
            deliveryAttempts: null,
            deliveredAt: $delivered ? $current->getDate() : null,
            history: $history,
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function mapEvent(array $event): TrackingEventInterface
    {
        $name = $this->toStringOrNull($event['name'] ?? null);

        return $this->makeEvent([
            'code' => $this->toStringOrNull($event['id'] ?? null),
            'status' => $name,
            'label' => $name,
            'state' => null,
            'date' => $this->toStringOrNull($event['date'] ?? null),
            'county' => null,
            'reason' => null,
            'transitLocation' => $this->toStringOrNull($event['location'] ?? null),
        ]);
    }
}
