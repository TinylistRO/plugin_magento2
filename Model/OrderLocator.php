<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Resolve an order by increment id.
 *
 * Magento 1's Mage::getModel('sales/order')->loadByIncrementId() has no
 * service-contract equivalent, so this wraps the repository search both callers
 * need and keeps the SearchCriteria plumbing in one place.
 */
class OrderLocator
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
    ) {
    }

    public function getByIncrementId(string $incrementId): ?OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();

        return $items === [] ? null : reset($items);
    }
}
