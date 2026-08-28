<?php

declare(strict_types=1);

namespace Evalent\DynamicSimilarProducts\Block\Product\ProductList;

use Evalent\DynamicSimilarProducts\Helper\Data;
use Evalent\DynamicSimilarProducts\Model\ResourceModel\AddIsInStockWithReservationFilterToCollection;
use Exception;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Checkout\Model\ResourceModel\Cart;
use Magento\Checkout\Model\Session;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager;
use Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite;
use Zend_Db_Select;

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

    /**
     * @var Manager
     */
    protected $moduleManager;

    /**
     * @var CollectionFactory
     */
    protected $productCollectionFactory;

    /**
     * @var AddIsInStockWithReservationFilterToCollection
     */
    protected AddIsInStockWithReservationFilterToCollection $addIsInStockFilterToCollection;

    /**
     * @var GetStockIdForCurrentWebsite
     */
    protected $getStockIdForCurrentWebsite;

    /**
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var Data
     */
    private $dataHelper;

    private $itemCount;

    private $useParentCategory = false;

    private int $similarProductByName = 0;

    private array $similarProductsFilters = [];

    protected \Magento\Elasticsearch\Model\ResourceModel\Fulltext\Collection\SearchCriteriaResolverFactory $searchCriteriaResolverFactory;
    protected \Magento\Search\Api\SearchInterface $search;
    protected \Magento\Framework\Api\FilterBuilder $filterBuilder;
    private \Magento\Framework\Api\Search\SearchCriteriaBuilder|null $searchCriteriaBuilder = null;
    private $searchOrders = ['_score' => 'ASC'];

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
     * @param \Magento\Elasticsearch\Model\ResourceModel\Fulltext\Collection\SearchCriteriaResolverFactory $searchCriteriaResolverFactory
     * @param \Magento\Search\Api\SearchInterface $search ,
     * @param \Magento\Framework\Api\FilterBuilder $filterBuilder
     * @param array $data
     */
    public function __construct(
        GetStockIdForCurrentWebsite                                                                  $getStockIdForCurrentWebsite,
        AddIsInStockWithReservationFilterToCollection                                                $addIsInStockFilterToCollection,
        CollectionFactory                                                                            $productCollectionFactory,
        AttributeRepositoryInterface                                                                 $attributeRepository,
        CategoryRepositoryInterface                                                                  $categoryRepository,
        Data                                                                                         $dataHelper,
        Context                                                                                      $context,
        Cart                                                                                         $checkoutCart,
        Visibility                                                                                   $catalogProductVisibility,
        Session                                                                                      $checkoutSession,
        Manager                                                                                      $moduleManager,
        \Magento\Elasticsearch\Model\ResourceModel\Fulltext\Collection\SearchCriteriaResolverFactory $searchCriteriaResolverFactory,
        \Magento\Search\Api\SearchInterface                                                          $search,
        \Magento\Framework\Api\FilterBuilder                                                         $filterBuilder,
        array                                                                                        $data = []
    )
    {
        $this->_checkoutCart = $checkoutCart;
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
        $this->addIsInStockFilterToCollection = $addIsInStockFilterToCollection;
        $this->getStockIdForCurrentWebsite = $getStockIdForCurrentWebsite;

        $this->searchCriteriaResolverFactory = $searchCriteriaResolverFactory;
        $this->search = $search;
        $this->filterBuilder = $filterBuilder;
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
    protected function _prepareData()
    {
        $this->doPrepareData();
        if ($this->itemCount < $this->dataHelper->getUseParentCategoryThreshold()) {
            $this->useParentCategory = true;
            return $this->doPrepareData(true);
        }
        return $this;
    }

    private function getSearchCriteriaBuilder()
    {
        if (null === $this->searchCriteriaBuilder) {
            $this->searchCriteriaBuilder = \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Magento\Framework\Api\Search\SearchCriteriaBuilder::class);
        }
        return $this->searchCriteriaBuilder;
    }

    protected function doPrepareData($useParentCategory = false)
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

            $builder->addFilter($this->filterBuilder->setField('name')
                ->setConditionType('like')
                ->setValue($product->getName())
                ->create()
            );

            $builder->addFilter($this->filterBuilder->setField('width2.from')
                ->setValue((string)($width * $searchWidthLow))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('width2.to')
                ->setValue((string)($width * $searchWidthHigh))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('height2.from')
                ->setValue((string)($height * $searchWidthLow))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('height2.to')
                ->setValue((string)($height * $searchWidthHigh))
                ->create()
            );
            $builder->addFilter($this->filterBuilder->setField('visibility')
                ->setValue([2, 4])
                ->create()
            );

            $builder->addFilter($this->filterBuilder->setField('category_ids')
                ->setValue([$category->getEntityId()])
                ->create()
            );

            if ($color = $this->getProduct()->getData('color')) {
                $match = explode(',', $color);
                $ignore = [$this->dataHelper->getMainAttributeIgnore()];
                $match = array_diff($match, $ignore);
                $builder->addFilter($this->filterBuilder->setField('color')
                    ->setValue($match)
                    ->create()
                );

                $this->similarProductsFilters["sort"] = [
                    "color" => implode(',', $match)
                ];
            }

            if ($this->dataHelper->getTertiaryAttribute() && ($productionType = $this->getProduct()->getData($this->dataHelper->getTertiaryAttribute()))){
                $builder->addFilter($this->filterBuilder->setField($this->dataHelper->getTertiaryAttribute())
                    ->setValue($productionType)
                    ->create()
                );
            }

            $searchCriteriaResolver = $this->searchCriteriaResolverFactory->create([
                'builder' => $builder,
                'collection' => $this->_itemCollection,
                'searchRequestName' => 'catalog_view_container',
                'currentPage' => 1,
                'size' => $this->dataHelper->getListSize(),
                'orders' => $this->searchOrders,
            ]);

            $searchCriteria = $searchCriteriaResolver->resolve();
            $searchResult = $this->search->search($searchCriteria);

            $entityIds = [];
            foreach ($searchResult->getItems() as $item) {
                $entityIds[] = $item->getId();
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

            /*$orderList = implode(',', $entityIds);
            $this->_itemCollection->getSelect()->where('e.entity_id IN (?)', $entityIds)
                ->reset(Zend_Db_Select::ORDER)
                ->order(new Expression("FIELD(e.entity_id, $orderList)"));*/

            $this->_itemCollection->getSelect()
                ->where('e.entity_id IN (?)', $entityIds)
                ->reset(\Zend_Db_Select::ORDER)
                ->order('e.entity_id DESC');

            $this->_itemCollection = $this->_addProductAttributesAndPrices($this->_itemCollection);
            $this->_itemCollection = $this->_itemCollection->setVisibility($this->_catalogProductVisibility->getVisibleInCatalogIds());

            // $stockId = $this->getStockIdForCurrentWebsite->execute();
            // $this->addIsInStockFilterToCollection->execute($this->_itemCollection, $stockId);
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

        $supplier = $product->getData("supplier");
        $suppliers = array("Empera", "Anatolian Weaver", "Kaplan", "Kaplan Kardesler");
        $orderSuppliers = "";

        if (in_array($supplier, $suppliers)) {
            $this->_itemCollection->addAttributeToSelect("supplier", 'left');
            $orderSuppliers = "find_in_set(supplier,'" . implode(',', $suppliers) . "') AND ";
        }

        $order = new \Zend_Db_Expr("CASE WHEN " . $orderSuppliers . "$mainAttributeCode = '$mainAttributeValue' AND $secondaryAttributeCode = '$secondaryAttributeValue' THEN 1
        WHEN " . $orderSuppliers . "$mainAttributeCode = '$mainAttributeValue' THEN 2
        WHEN " . $orderSuppliers . "$secondaryAttributeCode = '$secondaryAttributeValue' THEN 3
        ELSE 4 END");
        $this->_itemCollection->getSelect()->reset('order');
        $this->_itemCollection->getSelect()->order($order);
        return true;
    }


    /**
     * Get collection items
     *
     * @return Collection
     */
    public function getItems()
    {
        /**
         * getIdentities() depends on _itemCollection populated, but it can be empty if the block is hidden
         * @see https://github.com/magento/magento2/issues/5897
         */
        if ($this->_itemCollection === null) {
            $this->_prepareData();
        }
        return $this->_itemCollection;
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
