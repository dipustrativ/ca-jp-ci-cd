<?php

declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Exception\LocalizedException;

class ProductList extends \Magento\Catalog\Block\Product\AbstractProduct
{
    /** @var Collection */
    protected $itemCollection;

    /** @var \Magento\Catalog\Model\Category|null */
    private $category;

    /** @var int */
    private $limit = 5;


    /**
     * @return Collection
     */
    public function getProductCollection()
    {
        if ($this->itemCollection !== null) {
            return $this->itemCollection;
        }

        $collection = $this->getCategory()->getProductCollection();
        $collection = $collection
            ->addAttributeToSelect('*')
            ->addAttributeToSort('position', 'asc')
            ->addAttributeToSort('entity_id', 'desc')
            ->setPageSize($this->getLimit())
            ->setCurPage(1);

        $this->itemCollection = $collection;
        return $this->itemCollection;
    }

    /**
     * @return \Magento\Catalog\Model\Category
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCategory(): \Magento\Catalog\Model\Category
    {
        if ($this->category === null) {
            throw new LocalizedException(__("Category not set"));
        }

        return $this->category;
    }

    /**
     * @param \Magento\Catalog\Model\Category $category
     * @return $this
     */
    public function setCategory(\Magento\Catalog\Model\Category $category)
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setLimit(int $limit)
    {
        $this->limit = $limit;

        return $this;
    }

    public function getChildBlock($alias)
    {
        if ($alias !== "addto") {
            return parent::getChildBlock($alias);
        }

        return $this->getLayout()->getBlock("categoryproductlistwidget.addto");
    }
}
