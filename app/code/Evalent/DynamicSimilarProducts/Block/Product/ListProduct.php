<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Block\Product;

use Exception;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\ProductList\Toolbar;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Data\Helper\PostHelper;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Url\Helper\Data;
use Zend_Db_Expr;

class ListProduct extends \Magento\Catalog\Block\Product\ListProduct implements IdentityInterface
{

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var \Magento\Catalog\Model\Product\Visibility
     */
    private $productVisibility;

    /**
     * @var \Magento\Catalog\Model\Config
     */
    private $catalogConfig;

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    private $attributeRepository;

    protected $itemCollection;

    /**
     * @var \Evalent\DynamicSimilarProducts\Helper\Data
     */
    private $dataHelper;

    public function __construct(
        \Evalent\DynamicSimilarProducts\Helper\Data $dataHelper,
        AttributeRepositoryInterface $attributeRepository,
        CollectionFactory $productCollectionFactory,
        Visibility $productVisibility,
        Config $catalogConfig,
        Context $context,
        PostHelper $postDataHelper,
        Resolver $layerResolver,
        CategoryRepositoryInterface $categoryRepository,
        Data $urlHelper,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $postDataHelper,
            $layerResolver,
            $categoryRepository,
            $urlHelper,
            $data
        );
        $this->productCollectionFactory = $productCollectionFactory;
        $this->productVisibility = $productVisibility;
        $this->catalogConfig = $catalogConfig;
        $this->attributeRepository = $attributeRepository;
        $this->dataHelper = $dataHelper;
    }

    protected function _getProductCollection()
    {
        if ($this->_productCollection === null) {
            $this->_productCollection = $this->initializeProductCollection();
        }

        return $this->_productCollection;
    }

    private function initializeProductCollection()
    {
	    $layer = $this->getLayer();

        $product = $this->getProduct();
        $category = $this->dataHelper->getDeepestCategory($product);
        $layer->setCurrentCategory($category);

        $this->itemCollection = $layer->getProductCollection();

        $this->prepareSortableFieldsByCategory($category);

        $this->setCollectionSortOrder($product);
        $this->itemCollection->addCategoryFilter($category);
        $this->itemCollection->addAttributeToFilter('entity_id', ['neq' => $product->getId()]);

        //$this->addToolbarBlock($this->itemCollection);

        return $this->itemCollection;
    }

    /**
     * Need use as _prepareLayout - but problem in declaring collection from another block.
     * (was problem with search result)
     *
     * @return \Magento\Catalog\Block\Product\ListProduct
     */
    protected function _beforeToHtml()
    {
        $collection = $this->_getProductCollection();

        $this->addToolbarBlock($collection);

        if (!$collection->isLoaded()) {
            $collection->load();
        }

        return $this;
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return bool
     */
    private function setCollectionSortOrder($product)
    {
        if ($this->getRequest()->getParam('product_list_order') != null) {
            return false;
        }
        try {
            $mainAttribute = $this->attributeRepository->get('catalog_product', $this->dataHelper->getMainAttribute());
            $secondaryAttribute = $this->attributeRepository->get('catalog_product', $this->dataHelper->getSecondaryAttribute());
        } catch (NoSuchEntityException $e) {
            return false;
        } catch (Exception $e) {
            return false;
        }
        $mainAttributeCode = $mainAttribute->getAttributeCode();
        $mainAttributeValue = $product->getData($mainAttributeCode);
        $secondaryAttributeCode = $secondaryAttribute->getAttributeCode();
        $secondaryAttributeValue = $product->getData($secondaryAttributeCode);

        // We can only sort on attributes that have string values
        if ((!is_string($mainAttributeValue) && $mainAttributeValue != null) || (!is_string($secondaryAttributeValue) && $secondaryAttributeValue != null)) {
            return false;
        }

        $this->itemCollection->addAttributeToSelect($mainAttributeCode, 'left');
        $this->itemCollection->addAttributeToSelect($secondaryAttributeCode, 'left');

        $supplier = $product->getData("supplier");
        $suppliers = array("Empera", "Anatolian Weaver", "Kaplan", "Kaplan Kardesler");
        $orderSuppliers = "";

        if(in_array($supplier, $suppliers)) {
            $this->itemCollection->addAttributeToSelect("supplier", 'left');
            $orderSuppliers = "find_in_set(supplier,'".implode(',', $suppliers)."') AND ";
        }

        $order = new \Zend_Db_Expr("CASE WHEN " . $orderSuppliers . "$mainAttributeCode = '$mainAttributeValue' AND $secondaryAttributeCode = '$secondaryAttributeValue' THEN 1
        WHEN  " . $orderSuppliers . "$mainAttributeCode = '$mainAttributeValue' THEN 2
        WHEN  " . $orderSuppliers . "$secondaryAttributeCode = '$secondaryAttributeValue' THEN 3
        ELSE 4 END");
        $this->itemCollection->getSelect()->reset('order');
        $this->itemCollection->getSelect()->order($order);
        return true;
    }

    /**
     * Add toolbar block from product listing layout
     *
     * @param Collection $collection
     */
    private function addToolbarBlock(Collection $collection)
    {
        $toolbarLayout = $this->getToolbarFromLayout();

        if ($toolbarLayout) {
            $this->configureToolbar($toolbarLayout, $collection);
        }
    }

    /**
     * Get toolbar block from layout
     *
     * @return bool|Toolbar
     */
    private function getToolbarFromLayout()
    {
        $blockName = $this->getToolbarBlockName();

        $toolbarLayout = false;

        if ($blockName) {
            $toolbarLayout = $this->getLayout()->getBlock($blockName);
        }

        return $toolbarLayout;
    }

    /**
     * Configures the Toolbar block with options from this block and configured product collection.
     *
     * The purpose of this method is the one-way sharing of different sorting related data
     * between this block, which is responsible for product list rendering,
     * and the Toolbar block, whose responsibility is a rendering of these options.
     *
     * @param Toolbar $toolbar
     * @param Collection $collection
     * @return void
     */
    private function configureToolbar(Toolbar $toolbar, Collection $collection)
    {
        // use sortable parameters
        $orders = $this->getAvailableSortByOptions();

        if ($orders) {
            $toolbar->setAvailableOrders($orders);
        }
        $sort = $this->getSortBy();
        if ($sort) {
            $toolbar->setDefaultOrder($sort);
        }
        $dir = $this->getDefaultDirection();
        if ($dir) {
            $toolbar->setDefaultDirection($dir);
        }
        $modes = $this->getModes();
        if ($modes) {
            $toolbar->setModes($modes);
        }
        // set collection to toolbar and apply sort
        $toolbar->setCollection($collection);
        $this->setChild('toolbar', $toolbar);
    }

    /**
     * Retrieve Available Product Listing  Sort By
     *
     * Code as key, value - name
     *
     * @return array
     */
    public function getAvailableSortByOptions()
    {

        $defaultSortBy = $this->dataHelper->getAttributeUsedForSortByArray();

        return $defaultSortBy;

    }

    /**
     * Return unique ID(s) for each object in system
     *
     * @return string[]
     */
    public function getIdentities()
    {
        if (!$this->itemCollection) {
            $this->initializeProductCollection();
        }
        $identities = [];
        foreach ($this->itemCollection as $item) {
            $identities = array_merge($identities, $item->getIdentities());
        }
        return $identities;
    }
}
