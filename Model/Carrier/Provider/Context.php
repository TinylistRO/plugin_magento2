<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Carrier\Provider;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Tinylist\Dropshipping\Model\Data\TrackingEventFactory;

/**
 * Shared services every carrier provider needs, bundled so each provider's own
 * constructor stays about its own configuration.
 */
class Context
{
    public function __construct(
        public readonly ScopeConfigInterface $scopeConfig,
        public readonly ModuleManager $moduleManager,
        public readonly CurlFactory $curlFactory,
        public readonly EncryptorInterface $encryptor,
        public readonly ResourceConnection $resourceConnection,
        public readonly ShipmentTrackRepositoryInterface $trackRepository,
        public readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        public readonly TrackingEventFactory $eventFactory,
    ) {
    }
}
