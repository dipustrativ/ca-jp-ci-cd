<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Transaction\Export;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Filter form for the payment-transaction export.
 */
class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormAction(): string
    {
        return $this->getUrl('exportimport/transaction_export/download');
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores() as $store) {
            $website = $store->getWebsite();
            $options[] = [
                'value' => (int)$store->getId(),
                'label' => sprintf(
                    '%s / %s [%s]',
                    $website ? $website->getName() : '',
                    $store->getName(),
                    $store->getCode()
                ),
            ];
        }
        return $options;
    }

    /**
     * The six transaction types Magento recognises, labelled as the admin's own
     * Transactions grid labels them.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getTypeOptions(): array
    {
        return [
            ['value' => TransactionInterface::TYPE_ORDER, 'label' => (string)__('Order')],
            ['value' => TransactionInterface::TYPE_AUTH, 'label' => (string)__('Authorization')],
            ['value' => TransactionInterface::TYPE_CAPTURE, 'label' => (string)__('Capture')],
            ['value' => TransactionInterface::TYPE_VOID, 'label' => (string)__('Void')],
            ['value' => TransactionInterface::TYPE_REFUND, 'label' => (string)__('Refund')],
            ['value' => TransactionInterface::TYPE_PAYMENT, 'label' => (string)__('Payment')],
        ];
    }
}
