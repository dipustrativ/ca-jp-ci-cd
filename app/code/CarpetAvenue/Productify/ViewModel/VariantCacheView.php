<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\ViewModel;

use CarpetAvenue\Productify\Helper\Data;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use CarpetAvenue\Productify\Model\GroupProductCacheRepository;
use Magento\Store\Model\StoreManagerInterface;

class VariantCacheView implements ArgumentInterface
{
    protected GroupProductCacheRepository $repository;
    protected StoreManagerInterface $storeManager;

    /**
     * Colour option id => label, filled in one query on first use.
     *
     * @var array<string, string>
     */
    private array $colorLabels = [];

    public function __construct(
        GroupProductCacheRepository $repository,
        StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig
    ) {
        $this->repository = $repository;
        $this->storeManager = $storeManager;
    }

    /**
     * Colour label for a product tile.
     *
     * Product::getAttributeText() runs its own option query per call and caches
     * nothing, so on a listing it costs one query per tile. The option table is
     * small — load it once and answer from memory.
     *
     * Returns false for "no label", exactly like getAttributeText(), because the
     * cached variant rows store that same false and the template compares the
     * two with ===.
     */
    public function getColorLabel(ProductInterface $product): string|false
    {
        $value = $product->getData(Data::COLOR_ATTRIBUTE_CODE);

        if ($value === null || $value === '') {
            return false;
        }

        if ($this->colorLabels === []) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, Data::COLOR_ATTRIBUTE_CODE);
            foreach ($attribute->getSource()->getAllOptions() as $option) {
                $this->colorLabels[(string)$option['value']] = (string)$option['label'];
            }
        }

        return $this->colorLabels[(string)$value] ?? false;
    }

    public function getVariantsByDesignId(string $designId): ?array
    {
        try {
            $storeId = $this->storeManager->getStore()->getId();
            if(!$storeId) {
                return [];
            }
            $data = $this->repository->getByDesignId($designId, intval($storeId)); // Do not remove the integer parsing
            return $data['variants'] ?? [];
        } catch (NoSuchEntityException) {
            return [];
        }
    }
}
