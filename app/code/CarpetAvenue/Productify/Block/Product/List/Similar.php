<?php

namespace CarpetAvenue\Productify\Block\Product\List;

use Evalent\DynamicSimilarProducts\Helper\Data;
use Evalent\DynamicSimilarProducts\Model\ResourceModel\AddIsInStockWithReservationFilterToCollection;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Checkout\Model\ResourceModel\Cart;
use Magento\Checkout\Model\Session;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Elasticsearch\Model\ResourceModel\Fulltext\Collection\SearchCriteriaResolverFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Module\Manager;
use Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite;
use Magento\Search\Api\SearchInterface;

class Similar extends AbstractProduct implements IdentityInterface
{

    /**
     * @var Collection
     */
    protected $_itemCollection;

    /**
     * Checkout session
     *
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * Catalog product visibility
     *
     * @var Visibility
     */
    protected $_catalogProductVisibility;

    /**
     * Checkout cart
     *
     * @var Cart
     */
    protected $_checkoutCart;

    private $itemCount;

    private bool $useParentCategory = false;

    private bool $usedSizeFallback = false;

    private int $similarProductByName = 0;

    private array $similarProductsFilters = [];

    private ?Product $product = null;

    private SearchCriteriaBuilder|null $searchCriteriaBuilder = null;
    private array $searchOrders = ['is_out_of_stock' => 'DESC', '_score' => 'DESC'];


    /**
     * @param GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite
     * @param AddIsInStockWithReservationFilterToCollection $addIsInStockFilterToCollection
     * @param CollectionFactory $productCollectionFactory
     * @param AttributeRepositoryInterface $attributeRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param Data $dataHelper
     * @param Context $context
     * @param Cart $checkoutCart
     * @param Visibility $catalogProductVisibility
     * @param Session $checkoutSession
     * @param Manager $moduleManager
     * @param SearchCriteriaResolverFactory $searchCriteriaResolverFactory
     * @param SearchInterface $search ,
     * @param FilterBuilder $filterBuilder
     * @param array $data
     */
    public function __construct(
        Context $context,
        protected GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite,
        protected AddIsInStockWithReservationFilterToCollection $addIsInStockFilterToCollection,
        protected CollectionFactory $productCollectionFactory,
        protected AttributeRepositoryInterface $attributeRepository,
        protected CategoryRepositoryInterface $categoryRepository,
        protected Data $dataHelper,
        protected Cart $checkoutCart,
        protected Visibility $catalogProductVisibility,
        protected Session $checkoutSession,
        protected Manager $moduleManager,
        protected SearchCriteriaResolverFactory $searchCriteriaResolverFactory,
        protected SearchInterface $search,
        protected FilterBuilder $filterBuilder,
        array $data = []
    ){
        $this->_checkoutCart = $checkoutCart;
        $this->_catalogProductVisibility = $catalogProductVisibility;
        $this->_checkoutSession = $checkoutSession;
        parent::__construct(
            $context,
            $data
        );
    }

    public function getProductId()
    {
        return $this->getData('product_id');
    }

    public function getProduct(): ?Product
    {
        if ($this->product == null) {
            $storeId = $this->_storeManager->getStore()->getId();
            $productFactory = ObjectManager::getInstance()->get(\Magento\Catalog\Model\ProductFactory::class);

            /** @var \Magento\Catalog\Model\ResourceModel\Product $productResource */
            $productResource = ObjectManager::getInstance()
                ->get(\Magento\Catalog\Model\ResourceModel\Product::class);

            /** @var \Magento\Catalog\Model\Product $product */
            $product = $productFactory->create();
            $productResource->load($product, $this->getProductId(), null);

            $product->setStoreId($storeId);

            $this->product = $product;
            return $product;
        }
        return $this->product;
    }

    protected function _toHtml()
    {
        if (!$this->dataHelper->isEnabled() || $this->_itemCollection == null) {
            return "";
        }
        return parent::_toHtml(); // TODO: Change the autogenerated stub
    }

    /**
     * Prepare data
     *
     * @return $this
     */
    protected function _prepareData(): static
    {
        $this->usedSizeFallback = false;
        $this->doPrepareData();
        // Don't retry against the parent category once the size-only fallback
        // has supplied results — the retry re-runs the name-anchored search and
        // would clobber the fallback's in-stock size matches with an empty set.
        if (!$this->usedSizeFallback && $this->itemCount < $this->dataHelper->getUseParentCategoryThreshold()) {
            $this->useParentCategory = true;
            return $this->doPrepareData(true);
        }
        return $this;
    }

    private function getSearchCriteriaBuilder()
    {
        if (null === $this->searchCriteriaBuilder) {
            $this->searchCriteriaBuilder = ObjectManager::getInstance()
                ->get(SearchCriteriaBuilder::class);
        }
        return $this->searchCriteriaBuilder;
    }

    protected function doPrepareData($useParentCategory = false): static
    {
        // Get necessary data for the retrieval of related products
        $this->_itemCollection = $this->productCollectionFactory->create();
        $product = $this->getProduct();

        $category = $this->dataHelper->getDeepestCategory($product);
        if ($category == null) {
            $this->_itemCollection = null;
            return $this;
        }

        if ($useParentCategory) {
            $category = $category->getParentCategory();
        }

        if (($width = $product->getData('width2')) && ($height = $product->getData('height2'))) {
            $searchWidthHigh = 1 + $this->dataHelper->getSecondaryAttributeWidth() / 100;
            $searchWidthLow = 1 - $this->dataHelper->getSecondaryAttributeWidth() / 100;

            $builder = $this->getSearchCriteriaBuilder();

            $this->getSimilarProductByName($product);

            // Set search query in request to trigger text search layer
            $this->_request->setParam('q', $product->getName());

            $builder->addFilter($this->filterBuilder->setField('width2.from')
                ->setValue((string)round($width * $searchWidthLow))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('width2.to')
                ->setValue((string)round($width * $searchWidthHigh))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('height2.from')
                ->setValue((string)round($height * $searchWidthLow))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('height2.to')
                ->setValue((string)round($height * $searchWidthHigh))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('visibility')
                ->setValue([3, 4])
                ->create()
            );

            if ($color = $this->getProduct()->getData('color')) {
                $match = explode(',', $color);
                $ignore = [$this->dataHelper->getMainAttributeIgnore()];
                $match = array_diff($match, $ignore);

                $colorSortValue = implode(',', $match);
                $this->similarProductsFilters["sort"] = [
                    "color" => $colorSortValue
                ];

                // Set sort_color parameter in request so AJAX uses same color sorting as catalogsearch
                $this->getRequest()->setParam('sort_color', $colorSortValue);
            }

            if ($this->dataHelper->getTertiaryAttribute() && ($productionType = $this->getProduct()->getData($this->dataHelper->getTertiaryAttribute()))){
                $builder->addFilter($this->filterBuilder->setField($this->dataHelper->getTertiaryAttribute())
                    ->setValue([$productionType])
                    ->create()
                );
            }

            // Fetch 20 products to ensure we have enough saleable products after filtering
            $searchCriteriaResolver = $this->searchCriteriaResolverFactory->create([
                'builder' => $builder,
                'collection' => $this->_itemCollection,
                'searchRequestName' => 'quick_search_container',
                'currentPage' => 1,
                'size' => 20,
                'orders' => $this->searchOrders,
            ]);
            $searchCriteria = $searchCriteriaResolver->resolve();

            // The search engine can reject the query for cluster-specific
            // reasons the generic "Could not perform search query" hides — e.g.
            // the colour _script sort reading params._source on ES clusters that
            // restrict source access. Don't 500 the whole grid: log the real
            // cause and fall through to the size-only SQL fallback below, which
            // needs no search engine.
            $searchResult = null;
            try {
                $searchResult = $this->search->search($searchCriteria);
            } catch (\Exception $e) {
                $this->_logger->critical($e);
            }

            $entityIds = [];
            $scores = [];

            foreach ($searchResult ? $searchResult->getItems() : [] as $item) {
                $entityIds[] = $item->getId();
                // Try to get the score if available
                if (method_exists($item, 'getScore')) {
                    $scores[$item->getId()] = $item->getScore();
                }
            }

            if (0 === count($entityIds)) {
                $entityIds[] = 0;
            } else {
                $this->similarProductsFilters['filters'] = [
                    "width2" => round(($width * $searchWidthLow)) . "-" . round($width * $searchWidthHigh),
                    "height2" => round($height * $searchWidthLow) . "-" . round($height * $searchWidthHigh),
                    "production_type" => $productionType ?? '',
                ];
            }

            $orderList = implode(',', $entityIds);
            $this->_itemCollection->getSelect()->where('e.entity_id IN (?)', $entityIds)
                ->reset(\Magento\Framework\DB\Select::ORDER)
                ->order(new \Magento\Framework\DB\Sql\Expression("FIELD(e.entity_id, $orderList)"));

            $this->_itemCollection = $this->_addProductAttributesAndPrices($this->_itemCollection);
            $this->_itemCollection = $this->_itemCollection->setVisibility($this->_catalogProductVisibility->getVisibleInCatalogIds());

            $stockId = $this->getStockIdForCurrentWebsite->execute();
            $this->addIsInStockFilterToCollection->execute($this->_itemCollection, $stockId);

            // The search above is anchored on the product name, so it only
            // returns the same design family. When that family is entirely out
            // of stock the grid comes back empty even though other in-stock rugs
            // of the same dimensions exist. Fall back to a size (+ production
            // type) only query so those still surface.
            if (0 === (int) $this->_itemCollection->getSize()) {
                // NB: a plain collection, not _addProductAttributesAndPrices() —
                // the latter's INNER price_index / cat_index joins silently drop
                // whole size bands (products with no price-index row for this
                // website/customer group), which zeroed the fallback for some
                // runners. Prices are rendered per item by the controller.
                $fallback = $this->productCollectionFactory->create();
                $fallback->addAttributeToSelect('*');
                $fallback->addAttributeToFilter('width2', [
                    'from' => round($width * $searchWidthLow),
                    'to'   => round($width * $searchWidthHigh),
                ]);
                $fallback->addAttributeToFilter('height2', [
                    'from' => round($height * $searchWidthLow),
                    'to'   => round($height * $searchWidthHigh),
                ]);
                if (($tertiary = $this->dataHelper->getTertiaryAttribute()) && !empty($productionType)) {
                    $fallback->addAttributeToFilter($tertiary, $productionType);
                }
                $fallback->addAttributeToFilter('entity_id', ['neq' => $product->getId()]);
                $fallback->setVisibility($this->_catalogProductVisibility->getVisibleInCatalogIds());
                $this->addIsInStockFilterToCollection->execute($fallback, $stockId);
                $fallback->setPageSize(max(20, (int) $this->dataHelper->getListSize()));

                $this->_itemCollection = $fallback;
                $this->itemCount = (int) $fallback->getSize();
                $this->usedSizeFallback = true;
                foreach ($this->_itemCollection as $item) {
                    $item->setDoNotUseCategoryId(true);
                }
                return $this;
            }

            $this->itemCount = $searchResult->getTotalCount(); //$this->_itemCollection->getSize();
            foreach ($this->_itemCollection as $product) {
                $product->setDoNotUseCategoryId(true);
            }
        }

        return $this;
    }

    private function getSimilarProductByName($product)
    {
        /*
        $collection = $this->_itemCollection;
        $collection->addAttributeToFilter('name', ['like' => '%' . $product->getName() . '%']);
        $collection->addAttributeToFilter('entity_id', ['neq' => $product->getId()]);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter('visibility', [
            'in' => [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH
            ]
        ]);
        $collection = $this->_addProductAttributesAndPrices($collection);
        $collection->setPageSize($this->dataHelper->getListSize());
        $collection->setCurPage(1);
        */
        $this->similarProductByName = 10;
        return $this;
    }


    public function getSimilarToUrl($product)
    {
        $searchPageTrigger = $this->similarProductByName > 0;
        $url = $this->dataHelper->getSimilarToUrl($product, $this->useParentCategory, $searchPageTrigger, $this->similarProductsFilters);
        $url = rtrim($url, '/');
        return $url;
    }


    public function moreProductAvailable()
    {
        return $this->itemCount > $this->dataHelper->getListSize() ? true : false;
    }

    /**
     * @param Product $product
     *
     * @return bool
     */
    private function setCollectionSortOrder($product)
    {
        try {
            $mainAttribute = $this->attributeRepository->get('catalog_product', $this->dataHelper->getMainAttribute());
            $secondaryAttribute = $this->attributeRepository->get('catalog_product', $this->dataHelper->getSecondaryAttribute());
        } catch (\Exception $e) {
            // Root-namespaced on purpose: this file's namespace has no Exception
            // class, so an unqualified "Exception" here never matched anything.
            // \Exception already covers NoSuchEntityException.
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

        $supplier = $product->getData("supplier");
        $suppliers = array("Empera", "Anatolian Weaver", "Kaplan", "Kaplan Kardesler");
        $orderSuppliers = "";

        if (in_array($supplier, $suppliers)) {
            $this->_itemCollection->addAttributeToSelect("supplier", 'left');
            $orderSuppliers = "find_in_set(supplier,'" . implode(',', $suppliers) . "') AND ";
        }

        // Quoted, not interpolated: these are attribute values, and one
        // apostrophe in a design/colour name breaks the whole statement.
        $connection = $this->_itemCollection->getConnection();
        $mainValue = $connection->quote((string)$mainAttributeValue);
        $secondaryValue = $connection->quote((string)$secondaryAttributeValue);

        $order = new \Zend_Db_Expr("CASE WHEN " . $orderSuppliers . "$mainAttributeCode = $mainValue AND $secondaryAttributeCode = $secondaryValue THEN 1
        WHEN " . $orderSuppliers . "$mainAttributeCode = $mainValue THEN 2
        WHEN " . $orderSuppliers . "$secondaryAttributeCode = $secondaryValue THEN 3
        ELSE 4 END");
        $this->_itemCollection->getSelect()->reset('order');
        $this->_itemCollection->getSelect()->order($order);
        return true;
    }


    /**
     * Get collection items
     *
     * @return array
     */
    public function getItems(): array
    {
        /**
         * getIdentities() depends on _itemCollection populated, but it can be empty if the block is hidden
         * @see https://github.com/magento/magento2/issues/5897
         */
        if ($this->_itemCollection === null) {
            $this->_prepareData();
        }

        // Filter out non-saleable products (sold/disabled)
        $saleableItems = [];
        $limit = $this->dataHelper->getListSize();

        // _prepareData() can still leave the collection null (e.g. the
        // parent-category retry finds no category — doPrepareData() nulls it),
        // so guard the loop rather than warn on foreach(null).
        if ($this->_itemCollection === null) {
            return $saleableItems;
        }

        foreach ($this->_itemCollection as $item) {
            $isSaleable = $item->isSaleable();
            if ($isSaleable) {
                $saleableItems[] = $item;

                // Stop after collecting the required number of saleable products
                if (count($saleableItems) >= $limit) {
                    break;
                }
            }
        }
        return $saleableItems;
    }

    /**
     * Return identifiers for produced content
     *
     * @return array
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
     * Before to html handler
     *
     * @return $this
     */
    protected function _beforeToHtml()
    {
        $this->_prepareData();
        return parent::_beforeToHtml();
    }
}
