<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Block\Adminhtml\AttributeOverride;

use CarpetAvenue\Utilities\Helper\Config;
use CarpetAvenue\Utilities\Model\Config\Source\WebsiteScopeCustomAttributes;
use CarpetAvenue\Utilities\Model\Config\Source\WebsiteScopePriceAttributes;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class ManualFix extends Template
{
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly WebsiteScopePriceAttributes $priceAttributesSource,
        private readonly WebsiteScopeCustomAttributes $customAttributesSource,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getDryRunUrl(): string
    {
        return $this->getUrl('utilities/attributeoverride/dryrun');
    }

    public function getRunFixUrl(): string
    {
        return $this->getUrl('utilities/attributeoverride/runfix');
    }

    /**
     * All active store views (excluding admin store 0).
     *
     * @return array  ['value' => store_id, 'label' => name]
     */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores() as $store) {
            $options[] = [
                'value' => $store->getId(),
                'label' => $store->getWebsite()->getName() . ' / ' . $store->getName(),
            ];
        }
        return $options;
    }

    /**
     * @return array  ['value' => code, 'label' => label]
     */
    public function getPriceAttributeOptions(): array
    {
        return $this->priceAttributesSource->toOptionArray();
    }

    /**
     * @return array  ['value' => code, 'label' => label]
     */
    public function getCustomAttributeOptions(): array
    {
        return $this->customAttributesSource->toOptionArray();
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
