<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier;

use Magento\Framework\Exception\ConfigurationMismatchException;
use Magento\Framework\Phrase;

/**
 * The fixed set of tracking-status providers, keyed by provider key
 * ("sameday", "dpd", …). Populated from etc/di.xml.
 *
 * This replaces the Magento 1 module's global/tinylist_dropshipping/
 * carrier_status_providers config node.
 */
class ProviderPool
{
    /**
     * @param array<string, ProviderInterface> $providers
     * @throws ConfigurationMismatchException
     */
    public function __construct(private readonly array $providers = [])
    {
        foreach ($providers as $key => $provider) {
            if (!$provider instanceof ProviderInterface) {
                throw new ConfigurationMismatchException(
                    new Phrase(
                        'Carrier status provider "%1" must implement %2.',
                        [$key, ProviderInterface::class]
                    )
                );
            }
        }
    }

    public function get(string $key): ?ProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAll(): array
    {
        return $this->providers;
    }
}
