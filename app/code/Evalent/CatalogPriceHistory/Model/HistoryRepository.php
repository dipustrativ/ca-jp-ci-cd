<?php


namespace Evalent\CatalogPriceHistory\Model;


class HistoryRepository implements \Evalent\CatalogPriceHistory\Api\HistoryRepositoryInterface
{
    /** @var \Evalent\CatalogPriceHistory\Model\ResourceModel\PriceHistory\CollectionFactory */
    protected $priceCollectionFactory;

        /** @var \Evalent\CatalogPriceHistory\Model\ResourceModel\PriceHistory\Collection */
    private $priceCollection;

    /** @var \Evalent\CatalogPriceHistory\Api\ConfigInterface */
    protected  $config;

    protected \Magento\Store\Model\StoreManagerInterface $storeManager;

    public function __construct(
        \Evalent\CatalogPriceHistory\Model\ResourceModel\PriceHistory\CollectionFactory $priceCollectionFactory,
        \Evalent\CatalogPriceHistory\Api\ConfigInterface $config,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ){
        $this->priceCollectionFactory = $priceCollectionFactory;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    public function ignoreForSku($sku){
        $skus = $this->config->getIgnoreForSkus();
        if (empty($skus)) {
            return false;
        }
        return in_array($sku, $skus);
    }
    /**
     * @inheritDoc
     */
    public function getLowestPrice($product, $storeId, $websiteId, $customerGroupId){
        if ($this->ignoreForSku($product->getSku())){
            return null;
        }
        $priceColumn = 'final_price';
        $finalPrice = $product->getFinalPrice();
        $rate = 1;
        
        if ('configurable'=== $product->getTypeId()){
            $priceColumn = 'min_price';
            $rate = $this->storeManager->getStore()->getCurrentCurrencyRate();
        }
        $lowestPriceWithingRange = $finalPrice;
        $previousFinalPriceOutOfRange = $finalPrice;
        // Is there a price change within range that is lower
        $collection = $this->getPriceCollection()->removeAllFieldsFromSelect()
            ->addExpressionFieldToSelect('lowest_price','min({{'.$priceColumn.'}})',$priceColumn)
            ->addFieldToFilter('entity_id',$product->getId())
            ->addFieldToFilter('website_id', $websiteId)
            ->addFieldToFilter('customer_group_id',$customerGroupId)
            ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d', strtotime('-'.$this->getLowestPriceDays($storeId).' days'))]);
        
        $collection->addFieldToFilter($priceColumn, ['lt' => ($finalPrice/$rate)]);

        if (0 < $collection->count()) {
            // Simplest case: A price is lower in history data within range.
            $lowestPrice = $collection->fetchItem();
            if (null !== $lowestPrice->getData('lowest_price')){
                $lowestPriceWithingRange = (float)$lowestPrice->getData('lowest_price')*$rate;
            }
        }

        // Found no lower price within range.
        // Get oldest price change within range. Price change before that is latest lowest price if it is lower than $product->getFinalPrice().
        // If there is no change at all within range then current final price is the lowest within range
        $collection = $this->getPriceCollection(true)->removeAllFieldsFromSelect()
        ->addFieldToSelect($priceColumn)
        ->addFieldToSelect('created_at')
        ->addFieldToFilter('entity_id',$product->getId())
        ->addFieldToFilter('website_id', $websiteId)
        ->addFieldToFilter('customer_group_id',$customerGroupId)
        ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d', strtotime('-'.$this->getLowestPriceDays($storeId).' days'))])
        ->addOrder('created_at', 'ASC')
        ->setPageSize(1);

        if (1 === $collection->count()) {
            // Price have changed within range. Was the price before that lower?
            $collection = $this->getPriceCollection(true)->removeAllFieldsFromSelect()
            ->addFieldToSelect($priceColumn)
            ->addFieldToSelect('created_at')
            ->addFieldToFilter('entity_id',$product->getId())
            ->addFieldToFilter('website_id', $websiteId)
            ->addFieldToFilter('customer_group_id',$customerGroupId)
            ->addFieldToFilter('created_at', ['lt' => date('Y-m-d', strtotime('-'.$this->getLowestPriceDays($storeId).' days'))])
            ->addOrder('created_at', 'DESC')
            ->setPageSize(1);
            if (1 === $collection->count()) {
                $previousPrice = $collection->getFirstItem();
                $previousFinalPriceOutOfRange = (float)$previousPrice->getData($priceColumn)*$rate;
            }
        }
        
        $lowest = min(array($finalPrice,$lowestPriceWithingRange,$previousFinalPriceOutOfRange));
        
        if ($lowest>$finalPrice){
            return null;
        }

        return $lowest;
    }

    /**
     * @inheritDoc
     */
    public function getLowestPriceDays($storeId){
        return (int)$this->config->getLowestPriceDays($storeId);
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     * @return ResourceModel\PriceHistory\Collection
     */
    protected function getPriceCollection($clean = false){
        if (null === $this->priceCollection || $clean){
            $this->priceCollection = $this->priceCollectionFactory->create();

        }
        return $this->priceCollection;
    }
}
