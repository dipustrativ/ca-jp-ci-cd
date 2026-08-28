<?php

declare(strict_types=1);

namespace CarpetAvenue\Widget\Block\Widget;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;

class CategoryProductView extends Template implements BlockInterface
{

    /** @var \Magento\Catalog\Api\CategoryRepositoryInterface */
    private $categoryRepository;

    /** @var null|\Magento\Catalog\Model\Category */
    private $category = null;

    /** @var \CarpetAvenue\Widget\Block\Widget\ProductList|null */
    private $listBlock = null;

    /**
     * @param \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param array $data
     */
    public function __construct(
        CategoryRepositoryInterface $categoryRepository,
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->categoryRepository = $categoryRepository;
    }

    protected function _construct()
    {
        parent::_construct();

        $this->addData([
            'cache_lifetime' => 3600,
        ]);
    }

    public function getCacheKeyInfo()
    {
        return [
            'CARPET_CATEGORY_PRODUCT_LIST_WIDGET',
            $this->_storeManager->getStore()->getId(),
            $this->_design->getDesignTheme()->getId(),
            $this->getData('category_id'),
            $this->getTemplate(),
            $this->getTitle()
        ];
    }

    public function _toHtml()
    {
        if ($this->getCategory() === null) {
            return "";
        }

        try {
            $block = $this->getProductListBlock();
            if ($block === null) {
                return "";
            }
        } catch (\Exception $e) {
            return "";
        }

        return parent::_toHtml();
    }

    /**
     * @return \Magento\Catalog\Api\Data\CategoryInterface|\Magento\Catalog\Model\Category|null
     */
    public function getCategory()
    {
        if ($this->category !== null) {
            return $this->category;
        }

        $id = $this->getData('category_id');
        try {
            /** @var \Magento\Catalog\Model\Category $category */
            return $this->category = $this->categoryRepository->get($id);
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * @return \CarpetAvenue\Widget\Block\Widget\ProductList
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getProductListBlock()
    {
        if ($this->listBlock !== null) {
            return $this->listBlock;
        }

        $amount = $this->getData('product_count');

        if (($category = $this->getCategory()) === null) {
            throw new LocalizedException(__("No category selected"));
        }

        $category = $this->getCategory();
        /** @var \CarpetAvenue\Widget\Block\Widget\ProductList $block */
        $block = $this->getLayout()->createBlock(ProductList::class);
        $block
            ->setCategory($category)
            ->setData("category_page_url", $category->getUrl())
            ->setData("title", __('%1 products', $block->getCategory()->getName()))
            ->setLimit((int) $amount ?? 5)
            ->setTemplate("CarpetAvenue_Widget::product/items.phtml")
        ;

        return $this->listBlock = $block;
    }

}
