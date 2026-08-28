<?php

declare(strict_types=1);

namespace CarpetAvenue\Widget\Model\Config\Source;

use Magento\Catalog\Model\Category as CategoryModel;

class Category implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Category collection factory
     *
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $categoryCollectionFactory;

    /**
     * Construct
     *
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
    }

    /**
     * Return option array
     *
     * @return array
     */
    public function toOptionArray()
    {
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $collection */
        $collection = $this->categoryCollectionFactory->create();

        $collection->addAttributeToSelect('name')->load();

        $options = [];

        foreach ($collection as $category) {
            $options[] = ['label' => $category->getName(), 'value' => $category->getId()];
        }

        return $options;
    }
}
