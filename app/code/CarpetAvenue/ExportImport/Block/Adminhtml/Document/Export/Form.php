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

namespace CarpetAvenue\ExportImport\Block\Adminhtml\Document\Export;

use CarpetAvenue\ExportImport\Model\Document\Pool;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Filter form for the invoice / credit-memo / shipment export. The document type
 * comes from the request, so one block and one template serve every screen.
 */
class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly Pool $pool,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getDocumentType(): string
    {
        return (string)$this->getRequest()->getParam('type');
    }

    public function getDocumentLabel(): string
    {
        $type = $this->getDocumentType();
        return $this->pool->has($type) ? $this->pool->getType($type)->getLabel() : $type;
    }

    public function getFormAction(): string
    {
        return $this->getUrl('exportimport/document_export/download', ['type' => $this->getDocumentType()]);
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
     * The numeric states of the current document type, empty when it has none.
     *
     * The labels come from the type: the money-document tables both store
     * `state` as an int but number it differently past 1, and a shipment has no
     * state column at all.
     *
     * @return array<int, array{value: int, label: string}>
     */
    public function getStateOptions(): array
    {
        $type = $this->getDocumentType();
        return $this->pool->has($type) ? $this->pool->getType($type)->getStateOptions() : [];
    }

    /** Whether to render the state filter at all. */
    public function hasStateFilter(): bool
    {
        return $this->getStateOptions() !== [];
    }
}
