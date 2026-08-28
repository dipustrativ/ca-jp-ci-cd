<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Config\Source;

use Magento\Catalog\Model\Category;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Class CategoryTree
 * Custom source model for category multi-select with hierarchical tree display
 */
class CategoryTree implements OptionSourceInterface
{
    protected $categoryTree;

    /**
     * CategoryTree constructor.
     *
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param RequestInterface $request
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        protected CategoryCollectionFactory $categoryCollectionFactory,
        protected RequestInterface $request,
        protected StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Get options array with hierarchical category tree
     *
     * @return array
     * @throws LocalizedException
     */
    public function toOptionArray(): array
    {
        if ($this->categoryTree === null) {
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect('name')
                ->addAttributeToSelect('is_active')
                ->setOrder('position', 'ASC');
            $categoryById = [
                Category::TREE_ROOT_ID => [
                    'value' => Category::TREE_ROOT_ID,
                    'label' => __('Default Category')
                ],
            ];
            foreach ($collection as $category) {
                $categoryById[$category->getId()] = [
                    'value' => $category->getId(),
                    'label' => $category->getName(),
                    'parent_id' => $category->getParentId()
                ];
            }
            $this->categoryTree = $this->buildTree($categoryById);
        }
        return $this->categoryTree;
    }

    /**
     * Build hierarchical tree structure
     *
     * @param array $categories
     * @param int $parentId
     * @param int $level
     * @return array
     */
    protected function buildTree(array $categories, int $parentId = 1, int $level = 0): array
    {
        $tree = [];
        foreach ($categories as $category) {
            if (isset($category['parent_id']) && $category['parent_id'] == $parentId) {
                $prefix = str_repeat('--', $level);
                $label = $level > 0 ? $prefix . ' ' . $category['label'] : $category['label'];
                $tree[] = [
                    'value' => $category['value'],
                    'label' => $label
                ];
                $children = $this->buildTree($categories, (int)$category['value'], $level + 1);
                if (!empty($children)) {
                    $tree = array_merge($tree, $children);
                }
            }
        }
        return $tree;
    }
}

