<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export\DataProvider;

use CarpetAvenue\FeedExport\Model\Export\ExportContext;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;

/**
 * parent_id + <parent_item> (full product data of the configurable/grouped parent)
 * + option_parameters_in_url ("attrId=valueId&...") for configurable deep links.
 */
class ParentProduct implements DataProviderInterface
{
    public function __construct(
        private readonly ConfigurableResource $configurableResource,
        private readonly GroupedLink $groupedLink,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly General $general,
        private readonly Categories $categories
    ) {
    }

    public function provide(Product $product, ExportContext $context): array
    {
        $wantsParentId = $context->isRequired('parent_id');
        $wantsParentItem = $context->isRequired('parent_item') || $context->isRequired('option_parameters_in_url');
        if (!$wantsParentId && !$wantsParentItem) {
            return [];
        }

        $data = [];
        $parentId = $this->getParentId((int)$product->getId());
        if ($wantsParentId) {
            $context->write($data, 'parent_id', $parentId);
        }
        if ($wantsParentItem) {
            $data['parent_item'] = $parentId ? $this->getParentData($product, $parentId, $context) : [];
        }
        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function getParentData(Product $child, int $parentId, ExportContext $context): array
    {
        $parent = $context->getCache('parent_product_' . $parentId, function () use ($parentId, $context) {
            try {
                return $this->productRepository->getById($parentId, false, $context->getStoreId() ?: null);
            } catch (NoSuchEntityException $e) {
                return null;
            }
        });
        if (!$parent instanceof Product) {
            return [];
        }

        $data = $context->getCache('parent_data_' . $parentId, function () use ($parent, $context) {
            $data = $this->general->buildProductData($parent, $context);
            $data['entity_id'] = (int)$parent->getId();
            if ($context->isRequired('parent_item/cats')) {
                $data['cats'] = $this->categories->getCategoryNodes($parent, $context);
            }
            return $data;
        });

        if ($context->isRequired('option_parameters_in_url') && $parent->getTypeId() === Configurable::TYPE_CODE) {
            $context->write($data, 'option_parameters_in_url', $this->getOptionParameters($parent, $child));
        }
        return $data;
    }

    private function getOptionParameters(Product $parent, Product $child): string
    {
        $parameters = [];
        /** @var Configurable $typeInstance */
        $typeInstance = $parent->getTypeInstance();
        foreach ($typeInstance->getConfigurableAttributes($parent) as $superAttribute) {
            $attribute = $superAttribute->getProductAttribute();
            $childValue = $child->getData($attribute->getAttributeCode());
            foreach ($superAttribute->getOptions() ?: [] as $option) {
                if ((string)$option['value_index'] === (string)$childValue) {
                    $parameters[] = $attribute->getId() . '=' . $option['value_index'];
                }
            }
        }
        return implode('&', $parameters);
    }

    private function getParentId(int $productId): int
    {
        $parentIds = $this->groupedLink->getParentIdsByChild($productId, GroupedLink::LINK_TYPE_GROUPED);
        if (!$parentIds) {
            $parentIds = $this->configurableResource->getParentIdsByChild($productId);
        }
        return $parentIds ? (int)end($parentIds) : 0;
    }
}
