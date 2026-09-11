<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Customer\Export;

use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Filter form for the Customer export. Every filter is a filter on the
 * customer — for addresses too, since an address is only useful next to the
 * customer it belongs to.
 */
class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerExtraData $extraData,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormAction(): string
    {
        return $this->getUrl('exportimport/customer_export/download');
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function getWebsiteOptions(): array
    {
        $options = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $options[] = [
                'value' => (int)$website->getId(),
                'label' => sprintf('%s [%s]', $website->getName(), $website->getCode()),
            ];
        }
        return $options;
    }

    /**
     * Customer groups, id => code, as the CSV names them.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function getGroupOptions(): array
    {
        $options = [];
        foreach ($this->extraData->getGroupCodes() as $id => $code) {
            $options[] = ['value' => $id, 'label' => $code];
        }
        return $options;
    }
}
