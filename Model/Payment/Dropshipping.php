<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model\Payment;

/**
 * The "Tinylist Dropshipping" offline payment method.
 *
 * There is no method class in the Magento 1 sense: the method is assembled from
 * Magento\Payment\Model\Method\Adapter virtual types in etc/di.xml, with its
 * behaviour coming from config (etc/config.xml) and its one side effect from
 * {@see Command\Initialize}. This class exists to keep the method code in one
 * place, referenced by both the di.xml wiring and the command.
 */
class Dropshipping
{
    public const CODE = 'tinylist_dropshipping';
}
