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

namespace CarpetAvenue\FeedExport\Block\Adminhtml\Profile\Edit;

use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\Taxonomy;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Xtento-style category mapping: category tree of the profile store, one taxonomy autocomplete per category.
 */
class CategoryMapping extends Template
{
    /**
     * @var string
     */
    protected $_template = 'CarpetAvenue_FeedExport::profile/category_mapping.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly Taxonomy $taxonomy,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string, string>
     */
    public function getTaxonomies(): array
    {
        return $this->taxonomy->getSources();
    }

    /**
     * Root category of the profile store (Default Category) with its children; all roots for store 0.
     *
     * @return array<int, array{id: int, name: string, children: array<int, mixed>}>
     */
    public function getCategoryTree(): array
    {
        $storeId = $this->getStoreId();
        $rootId = Category::TREE_ROOT_ID;
        if ($storeId) {
            try {
                $rootId = (int)$this->_storeManager->getStore($storeId)->getRootCategoryId();
            } catch (NoSuchEntityException $e) {
                $storeId = 0;
            }
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect('name')
            ->addFieldToFilter('path', ['like' => $rootId === Category::TREE_ROOT_ID ? '1/%' : "1/$rootId%"])
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC');

        $nodes = [];
        $children = [];
        foreach ($collection as $category) {
            $id = (int)$category->getId();
            $path = explode('/', (string)$category->getPath());
            // "1/2" must not match "1/20/..." in the LIKE filter above.
            if ($rootId !== Category::TREE_ROOT_ID && ($path[1] ?? null) !== (string)$rootId) {
                continue;
            }
            $nodes[$id] = ['id' => $id, 'name' => (string)$category->getName(), 'children' => []];
            $children[(int)$category->getParentId()][] = $id;
        }

        $topLevel = $rootId === Category::TREE_ROOT_ID ? ($children[$rootId] ?? []) : [$rootId];
        $build = function (int $id) use (&$build, &$nodes, $children): array {
            $node = $nodes[$id];
            foreach ($children[$id] ?? [] as $childId) {
                $node['children'][] = $build($childId);
            }
            return $node;
        };
        return array_map($build, array_values(array_filter($topLevel, static fn($id) => isset($nodes[$id]))));
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetConfig(): array
    {
        return [
            'searchUrl' => $this->getUrl('*/*/searchTaxonomy'),
        ];
    }

    private function getStoreId(): int
    {
        $profileId = (int)$this->getRequest()->getParam('profile_id');
        if (!$profileId) {
            return 0;
        }
        try {
            return $this->profileRepository->getById($profileId)->getStoreId();
        } catch (NoSuchEntityException $e) {
            return 0;
        }
    }
}
