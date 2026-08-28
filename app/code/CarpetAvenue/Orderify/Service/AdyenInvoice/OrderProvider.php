<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Service\AdyenInvoice;

use CarpetAvenue\Orderify\Helper\Config;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * Selects Adyen orders eligible for automatic invoice processing:
 * Adyen payment (not bank transfer), no invoice, older than the configured
 * minimum age.
 */
class OrderProvider
{
    private const ADYEN_METHOD_PREFIX = 'adyen_%';
    private const BANK_TRANSFER = 'evinvoicepayment';

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly Config $configHelper,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return Order[]
     */
    public function getOrders(): array
    {
        $minAgeMinutes = $this->configHelper->getAdyenAutoInvoiceMinAgeMinutes();

        /** @var OrderCollection $collection */
        $collection = $this->orderCollectionFactory->create();
        $collection->getSelect()->joinInner(
            ['sop' => $collection->getTable('sales_order_payment')],
            'sop.parent_id = main_table.entity_id',
            []
        )
            ->where('sop.method LIKE ?', self::ADYEN_METHOD_PREFIX)
            ->where('sop.method != ?', self::BANK_TRANSFER);

        $collection->addFieldToFilter('main_table.state', [
            'in' => [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT],
        ]);
        $collection->addFieldToFilter('main_table.total_invoiced', [['null' => true], ['eq' => 0]]);
        $collection->addFieldToFilter('main_table.created_at', [
            'from' => $this->dateTime->gmtDate(null, sprintf('-%d minutes', $minAgeMinutes)), // 10:40:00
            'to'   => $this->dateTime->gmtDate(null, '-3 minutes'),                            // 10:47:00
        ]);

        $collection->setOrder('main_table.entity_id', 'ASC');
        $collection->setPageSize($this->configHelper->getAdyenAutoInvoiceBatchSize());

        return $collection->getItems();
    }
}
