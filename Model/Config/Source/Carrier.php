<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Tinylist\Dropshipping\Model\Carrier\ProviderPool;

/**
 * Options for the "Enabled Carriers" multiselect — whatever is registered in
 * the provider pool, so adding a carrier in di.xml is enough to make it
 * selectable.
 */
class Carrier implements OptionSourceInterface
{
    public function __construct(private readonly ProviderPool $providerPool)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->providerPool->getAll() as $key => $provider) {
            $options[] = ['value' => $key, 'label' => $provider->getLabel()];
        }

        return $options;
    }
}
