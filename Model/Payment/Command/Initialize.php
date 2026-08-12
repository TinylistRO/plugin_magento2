<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Payment\Command;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Puts a newly placed order straight into the configured processing status,
 * instead of leaving it in the default "new"/pending state.
 *
 * This is the gateway equivalent of the Magento 1 method's initialize(): the
 * Adapter runs it because payment/tinylist_dropshipping/can_initialize is 1,
 * and it reads its target status from payment/tinylist_dropshipping/order_status.
 */
class Initialize implements CommandInterface
{
    public function __construct(private readonly OrderConfig $orderConfig)
    {
    }

    /**
     * @param array<string, mixed> $commandSubject
     * @return void
     */
    public function execute(array $commandSubject)
    {
        $stateObject = $commandSubject['stateObject'] ?? null;
        if (!$stateObject instanceof DataObject) {
            return;
        }

        $state = Order::STATE_PROCESSING;

        $stateObject->setState($state);
        $stateObject->setStatus($this->resolveStatus($commandSubject, $state));
        $stateObject->setIsNotified(false);
    }

    /**
     * @param array<string, mixed> $commandSubject
     */
    private function resolveStatus(array $commandSubject, string $state): string
    {
        $payment = $commandSubject['payment'] ?? null;
        $configured = null;

        if ($payment instanceof PaymentDataObjectInterface) {
            $configured = $payment->getPayment()->getMethodInstance()->getConfigData('order_status');
        }

        return $configured !== null && $configured !== ''
            ? (string) $configured
            : (string) $this->orderConfig->getStateDefaultStatus($state);
    }
}
