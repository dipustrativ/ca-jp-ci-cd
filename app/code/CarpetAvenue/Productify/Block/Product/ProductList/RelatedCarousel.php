<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Product\ProductList;

use CarpetAvenue\Productify\Helper\RelatedCarouselData;
use Exception;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Checkout\Model\ResourceModel\Cart;
use Magento\Checkout\Model\Session;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Zend_Db_Expr;

/**
 * Related products carousel block.
 * Merged from Evalent_RelatedCarouselProducts (reference project).
 */
class RelatedCarousel extends AbstractProduct implements IdentityInterface
{
    /**
     * @var Collection
     */
    protected $_itemCollection;

    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var Visibility
     */
    protected $_catalogProductVisibility;

    /**
     * @var Cart
     */
    protected $_checkoutCart;

    /**
     * @var Manager
     */
    protected $moduleManager;

    /**
     * @var CollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var RelatedCarouselData
     */
    private $dataHelper;

    /**
     * @var Http
     */
    private $request;

    private $itemCount;

    /**
     * @var CookieManagerInterface
     */
    private CookieManagerInterface $cookieManager;

    public function __construct(
        CollectionFactory $productCollectionFactory,
        AttributeRepositoryInterface $attributeRepository,
        CategoryRepositoryInterface $categoryRepository,
        RelatedCarouselData $dataHelper,
        Context $context,
        Cart $checkoutCart,
        Visibility $catalogProductVisibility,
        Session $checkoutSession,
        Manager $moduleManager,
        Http $request,
        CookieManagerInterface $cookieManager,
        array $data = []
    ) {
        $this->_checkoutCart = $checkoutCart;
        $this->cookieManager = $cookieManager;
        $this->_catalogProductVisibility = $catalogProductVisibility;
        $this->_checkoutSession = $checkoutSession;
        $this->moduleManager = $moduleManager;
        parent::__construct(
            $context,
            $data
        );
        $this->attributeRepository = $attributeRepository;
        $this->categoryRepository = $categoryRepository;
        $this->dataHelper = $dataHelper;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->request = $request;
    }

    public function isBlockVisible(): bool
    {
        return $this->dataHelper->isEnabled() && $this->isUsedRequest();
    }

    /**
     * Prepare data
     *
     * @return $this
     */
    protected function _prepareData()
    {
        $product = $this->getProduct();
        /* @var $product \Magento\Catalog\Model\Product */

        $this->_itemCollection = $this->productCollectionFactory->create();

        $category = $this->dataHelper->getDeepestCategory($product);
        if ($category == null) {
            // Makes the collection empty
            $this->_itemCollection->addAttributeToFilter('entity_id', 0);
            return $this;
        }

        $pageSize = $this->dataHelper->getListSize();

        if (!$this->setCollectionSortOrder($product)) {
            $this->_itemCollection->addAttributeToFilter('entity_id', 0);
            return $this;
        }
        $this->_itemCollection->addCategoryFilter($category);
        $this->_itemCollection->addAttributeToFilter('entity_id', ['neq' => $product->getId()]);

        if ($this->moduleManager->isEnabled('Magento_Checkout')) {
            $this->_addProductAttributesAndPrices($this->_itemCollection);
        }

        $this->_itemCollection->setVisibility($this->_catalogProductVisibility->getVisibleInCatalogIds());
        $this->itemCount = $this->_itemCollection->getSize();
        $this->_itemCollection->setPage(1, $pageSize);

        $this->_itemCollection->load();

        foreach ($this->_itemCollection as $item) {
            $item->setDoNotUseCategoryId(true);
        }

        return $this;
    }

    /**
     * @param $product
     * @return bool
     */
    private function setCollectionSortOrder($product)
    {
        // No sort attributes configured — show the carousel without the
        // "similar attributes first" ordering instead of hiding it.
        if ($this->dataHelper->getMainAttribute() === '' || $this->dataHelper->getSecondaryAttribute() === '') {
            return true;
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

        $this->_itemCollection->addAttributeToSelect($mainAttributeCode, 'left');
        $this->_itemCollection->addAttributeToSelect($secondaryAttributeCode, 'left');
        // Quoted, not interpolated: these are attribute values, and one
        // apostrophe in a design/colour name breaks the whole statement.
        $connection = $this->_itemCollection->getConnection();
        $mainValue = $connection->quote((string)$mainAttributeValue);
        $secondaryValue = $connection->quote((string)$secondaryAttributeValue);

        $order = new Zend_Db_Expr("is_salable DESC, CASE WHEN $mainAttributeCode = $mainValue AND $secondaryAttributeCode = $secondaryValue THEN 1
        WHEN $mainAttributeCode = $mainValue THEN 2
        WHEN $secondaryAttributeCode = $secondaryValue THEN 3
        ELSE 4 END");
        $this->_itemCollection->getSelect()->order($order);
        return true;
    }

    /**
     * Get collection items
     *
     * @return Collection
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getItems()
    {
        if ($this->_itemCollection === null) {
            $this->_prepareData();
        }
        if ($this->_itemCollection->count() > 0) {
            $this->addCurrentProductToCollection($this->_itemCollection);
        }

        return $this->_itemCollection;
    }

    /**
     * Return identifiers for produced content
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getIdentities()
    {
        $identities = [];
        $items = $this->getItems();
        if (!is_array($items)) {
            return [];
        }
        foreach ($items as $item) {
            $identities = array_merge($identities, $item->getIdentities());
        }
        return $identities;
    }

    /**
     * Find out if some products can be easy added to cart
     *
     * @return bool
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function canItemsAddToCart()
    {
        foreach ($this->getItems() as $item) {
            if (!$item->isComposite() && $item->isSaleable() && !$item->getRequiredOptions()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $collection
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function addCurrentProductToCollection($collection)
    {
        $productEntityId = $this->getProduct()->getEntityId();
        if (in_array($productEntityId, $collection->getAllIds())) {
            return $this;
        }
        $currentProductCollection = $this->productCollectionFactory->create()
            ->addFieldToFilter('entity_id', [$productEntityId])
            ->addAttributeToSelect('*')
            ->load();

        foreach ($collection as $item) {
            $currentProductCollection->addItem($item);
        }
        $this->_itemCollection = $currentProductCollection;
        return $this;
    }

    private function getCookie(string $key)
    {
        return $this->cookieManager->getCookie($key);
    }

    /**
     * Is used get parameter to display block
     */
    protected function isUsedRequest(): bool
    {
        if ($this->dataHelper->isEnabledRequestParam()) {
            if ($this->dataHelper->getRequestParamName() === '') {
                return true;
            }
            $paramValueCookie = $this->getCookie($this->dataHelper->getRequestParamName());
            $paramValue = $this->request->getParam($this->dataHelper->getRequestParamName()) ?: $paramValueCookie;

            if ($this->dataHelper->getRequestParamValue() !== '') {
                return $this->dataHelper->getRequestParamValue() == $paramValue;
            }
            return false;
        }

        return true;
    }
}
