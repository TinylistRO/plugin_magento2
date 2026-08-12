<?php
/**
 * Copyright (c) Tinylist. All rights reserved.
 */
declare(strict_types=1);

namespace Tinylist\Dropshipping\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Pdf\InvoiceFactory as InvoicePdfFactory;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use Throwable;
use Tinylist\Dropshipping\Api\Data\InvoiceResultInterface;
use Tinylist\Dropshipping\Api\OrderInvoiceInterface;
use Tinylist\Dropshipping\Model\Data\InvoiceResultFactory;

/**
 * Web API entry point for the invoice PDF.
 *
 * Magento 2 exposes invoice *records* over REST but never the rendered
 * document, which is what a buyer-facing platform actually needs — hence this
 * endpoint.
 *
 * All of the order's invoices are rendered into one PDF, matching the admin's
 * own "Print Invoices" behaviour; the metadata reports the most recent one.
 */
class OrderInvoice implements OrderInvoiceInterface
{
    private const SOURCE = 'magento';

    public function __construct(
        private readonly OrderLocator $orderLocator,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly InvoicePdfFactory $invoicePdfFactory,
        private readonly Emulation $emulation,
        private readonly InvoiceResultFactory $resultFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $orderIncrementId
     * @return InvoiceResultInterface
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function get($orderIncrementId)
    {
        $orderIncrementId = trim((string) $orderIncrementId);

        $order = $this->orderLocator->getByIncrementId($orderIncrementId);
        if ($order === null) {
            throw new NoSuchEntityException(
                new Phrase('Order "%1" was not found.', [$orderIncrementId])
            );
        }

        $invoices = $this->loadInvoices((int) $order->getEntityId());
        if ($invoices === []) {
            // Expected while the merchant hasn't invoiced yet — the caller polls.
            return $this->resultFactory->create(['available' => false]);
        }

        $latest = $invoices[count($invoices) - 1];

        return $this->resultFactory->create([
            'available' => true,
            'pdfBase64' => base64_encode($this->render($invoices, (int) $order->getStoreId())),
            'invoiceNumber' => $latest->getIncrementId(),
            'issuedAt' => $latest->getCreatedAt(),
            'currency' => $order->getOrderCurrencyCode(),
            'source' => self::SOURCE,
        ]);
    }

    /**
     * @return InvoiceInterface[] Oldest first.
     */
    private function loadInvoices(int $orderId): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('order_id', $orderId)
            ->addSortOrder($this->sortOrderBuilder->setField('entity_id')->setAscendingDirection()->create())
            ->create();

        return array_values($this->invoiceRepository->getList($criteria)->getItems());
    }

    /**
     * Render the invoices to raw PDF bytes.
     *
     * The PDF model resolves the theme, locale and translations from the current
     * design scope. A Web API request runs in the webapi_rest area, which has
     * none of that configured, so the order's own store is emulated for the
     * render — otherwise the document comes out in the wrong locale, or the
     * render fails outright.
     *
     * @param InvoiceInterface[] $invoices
     * @throws LocalizedException
     */
    private function render(array $invoices, int $storeId): string
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

        try {
            return (string) $this->invoicePdfFactory->create()->getPdf($invoices)->render();
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Tinylist invoice PDF render failed for store %d: %s', $storeId, $e->getMessage()),
                ['exception' => $e]
            );

            throw new LocalizedException(new Phrase('Could not render the invoice PDF.'), $e);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }
}
