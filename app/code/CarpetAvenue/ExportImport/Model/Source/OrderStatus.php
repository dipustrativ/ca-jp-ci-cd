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

namespace CarpetAvenue\ExportImport\Model\Source;

use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Order status options for the admin export filter (code => label).
 */
class OrderStatus
{
    public function __construct(
        private readonly CollectionFactory $statusCollectionFactory,
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $collection = $this->statusCollectionFactory->create()->orderByLabel();
        foreach ($collection as $status) {
            $options[] = [
                'value' => (string)$status->getStatus(),
                'label' => (string)$status->getLabel(),
            ];
        }
        return $options;
    }

    /**
     * Set of valid status codes, used by the importer/validator.
     *
     * @return array<string, string>
     */
    public function getValidStatusCodes(): array
    {
        $codes = [];
        foreach ($this->statusCollectionFactory->create() as $status) {
            $codes[(string)$status->getStatus()] = (string)$status->getStatus();
        }
        return $codes;
    }
}
