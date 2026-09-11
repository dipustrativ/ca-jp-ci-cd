<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\GridInterface;
use Psr\Log\LoggerInterface;

/**
 * Vat numbers from customer needs to be synced onto orders as the VAT might be added after an order is made.
 * We only add the VAT to orders where there already is none.
 */
class VatSync
{
    private OrderRepositoryInterface $orderRepository;
    private CustomerRepositoryInterface $customerRepository;
    private SearchCriteriaBuilder $criteriaBuilder;
    private GridInterface $entityGrid;
    private InvoiceRepositoryInterface $invoiceRepository;
    private LoggerInterface $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        CustomerRepositoryInterface $customerRepository,
        InvoiceRepositoryInterface $invoiceRepository,
        SearchCriteriaBuilder $criteriaBuilder,
        GridInterface $entityGrid,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->customerRepository = $customerRepository;
        $this->criteriaBuilder = $criteriaBuilder;
        $this->entityGrid = $entityGrid;
        $this->invoiceRepository = $invoiceRepository;
        $this->logger = $logger;
    }

    public function execute()
    {

        try {
            $customerCriteria = $this->criteriaBuilder
                ->addFilter('taxvat', null, 'neq')
                ->create();
            $customers = $this->customerRepository->getList($customerCriteria);

            /** @var OrderInterface $order */
            foreach ($customers->getItems() as $customer) {
                try {
                    $orderCriteria = $this->criteriaBuilder
                        ->addFilter('customer_id', $customer->getId())
                        ->addFilter('customer_taxvat', null, 'null')
                        ->create();
                    $orders = $this->orderRepository->getList($orderCriteria);
                    foreach ($orders->getItems() as $order) {
                        $order->setCustomerTaxvat($customer->getTaxvat());
                        $this->orderRepository->save($order);
                        $invoices = $this->invoiceRepository->getList(
                            $this->criteriaBuilder->addFilter('order_id', $order->getEntityId())->create()
                        );
                        foreach ($invoices->getItems() as $invoice) {
                            $this->entityGrid->purge($invoice->getEntityId());
                            $this->entityGrid->refresh($invoice->getEntityId());
                        }
                    }
                } catch (LocalizedException $e) {

                }
            }
        } catch (LocalizedException $e) {
            $this->logger->error('Unable to update VAT on orders.' . $e->getMessage());
        }
    }
}
