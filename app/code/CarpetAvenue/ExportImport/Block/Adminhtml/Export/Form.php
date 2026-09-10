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

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Export;

use CarpetAvenue\ExportImport\Model\Source\OrderStatus;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderStatus $orderStatus,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormAction(): string
    {
        return $this->getUrl('exportimport/export/download');
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
     * @return array<int, array{value: string, label: string}>
     */
    public function getStatusOptions(): array
    {
        return $this->orderStatus->toOptionArray();
    }
}
