<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\ProductStatus\Model\CatalogSearch\Indexer\Fulltext;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogSearch\Model\ResourceModel\EngineProvider;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use CarpetAvenue\Productify\ProductStatus\Api\ConfigInterface;

/**
 * Fulltext index data provider that adds ProductStatus (approved/online/weight) and
 * MSI/legacy stock filtering to the set of searchable products.
 *
 * Extends the Magento core DataProvider directly. Previously extended
 * Amasty\Shopby's provider; the four stock/attribute helper methods below were
 * inherited from Amasty and are now reimplemented here since that module is removed.
 */
class DataProvider extends \Magento\CatalogSearch\Model\Indexer\Fulltext\Action\DataProvider
{
    protected ConfigInterface $productStatusConfig;
    protected StoreManagerInterface $storeManager;
    protected ResourceConnection $resource;
    protected ScopeConfigInterface $config;
    protected Config $eavConfig;

    protected DefaultStockProviderInterface $defaultStockProvider;
    protected StockResolverInterface $stockResolver;
    protected StockIndexTableNameResolverInterface $stockIndexTableNameResolver;
    private $productVisibility;
    private $antiGapMultiplier = 5;
    private \Magento\Framework\EntityManager\EntityMetadata $metadata;

    public function __construct(
        ResourceConnection $resource,
        ScopeConfigInterface $config,
        Config $eavConfig,
        Visibility $productVisibility,
        ManagerInterface $eventManager,
        StoreManagerInterface $storeManager,
        MetadataPool $metadataPool,
        Type $catalogProductType,
        CollectionFactory $prodAttributeCollectionFactory,
        EngineProvider $engineProvider,
        StockResolverInterface $stockResolver,
        DefaultStockProviderInterface $defaultStockProvider,
        StockIndexTableNameResolverInterface $stockIndexTableNameResolver,
        ConfigInterface $productStatusConfig
    ) {
        $this->productStatusConfig = $productStatusConfig;
        $this->storeManager = $storeManager;
        $this->resource = $resource;
        $this->config = $config;
        $this->eavConfig = $eavConfig;
        $this->stockResolver = $stockResolver;
        $this->defaultStockProvider = $defaultStockProvider;
        $this->stockIndexTableNameResolver = $stockIndexTableNameResolver;
        $this->productVisibility = $productVisibility->getVisibleInSiteIds();
        $this->metadata = $metadataPool->getMetadata(ProductInterface::class);
        parent::__construct(
            $resource,
            $catalogProductType,
            $eavConfig,
            $prodAttributeCollectionFactory,
            $engineProvider,
            $eventManager,
            $storeManager,
            $metadataPool
        );
    }

    private function getAttributeByCode(string $attributeCode)
    {
        return $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
    }

    /**
     * Resolve the MSI stock id for the sales channel of the given store's website.
     */
    private function getStockId(int $storeId): ?int
    {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $websiteCode = $this->storeManager->getWebsite($websiteId)->getCode();
        $stock = $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode);

        return (int)$stock->getStockId();
    }

    /**
     * Legacy (default stock) filter via cataloginventory_stock_status.
     */
    private function addDefaultStockFilter(Select $select, bool $displayOutOfStock): void
    {
        $select->join(
            ['stock_status_index' => $this->getTable('cataloginventory_stock_status')],
            'e.entity_id = stock_status_index.product_id',
            []
        );
        if (!$displayOutOfStock) {
            $select->where('stock_status_index.stock_status = ?', StockStatusInterface::STATUS_IN_STOCK);
        }
    }

    /**
     * MSI filter via the inventory_stock_{stockId} index view (keyed on sku).
     */
    private function addMsiStockFilter(Select $select, int $stockId, bool $displayOutOfStock): void
    {
        $stockTable = $this->stockIndexTableNameResolver->execute($stockId);
        $select->join(
            ['inventory_stock' => $stockTable],
            'e.sku = inventory_stock.sku',
            []
        );
        if (!$displayOutOfStock) {
            $select->where('inventory_stock.is_salable = ?', 1);
        }
    }

    private function getTable(string $table): string
    {
        return $this->resource->getTableName($table);
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    public function getSearchableProducts(
        $storeId,
        array $staticFields,
        $productIds = null,
        $lastProductId = 0,
        $batch = 1000
    ) {
        $storeId = (int)$storeId;
        $select = $this->getSelectForSearchableProducts($storeId, $staticFields, $productIds, (int)$lastProductId, (int)$batch);
        if ($productIds === null) {
            $select->where(
                'e.entity_id < ?',
                $lastProductId ? $this->antiGapMultiplier * $batch + $lastProductId + 1 : $batch + 1
            );
        }
        $products = $this->getConnection()->fetchAll($select);
        if ($productIds === null && !$products) {
            // try to search without limit entity_id by batch size for cover case with a big gap between entity ids
            $products = $this->getConnection()->fetchAll(
                $this->getSelectForSearchableProducts($storeId, $staticFields, $productIds, (int)$lastProductId, (int)$batch)
            );
        }
        return $products; //parent::getSearchableProducts($storeId, $staticFields, $productIds, $lastProductId, $batch);
    }
    private function getSelectForSearchableProducts(
        int $storeId,
        array $staticFields,
        ?array $productIds,
        int $lastProductId,
        int $batch
    ): Select {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $connection = $this->getConnection();

        $select = $connection->select()
            ->useStraightJoin(true)
            ->from(
                ['e' => $this->getTable('catalog_product_entity')],
                array_merge(['entity_id', 'type_id'], $staticFields)
            )
            ->join(
                ['website' => $this->getTable('catalog_product_website')],
                $connection->quoteInto('website.product_id = e.entity_id AND website.website_id = ?', $websiteId),
                []
            );

        $stockId = $this->getStockId($storeId);
        $displayOutOfStock = (bool) $this->config->getValue('cataloginventory/options/show_out_of_stock');

        if ($stockId === null || $stockId === $this->defaultStockProvider->getId()) {
            $this->addDefaultStockFilter($select, $displayOutOfStock);
        } else {
            $this->addMsiStockFilter($select, $stockId, $displayOutOfStock);
        }

        $this->joinAttribute($select, 'visibility', $storeId, $this->productVisibility, false);
        $this->joinAttribute($select, 'status', $storeId, [Status::STATUS_ENABLED], false);
        if ($this->productStatusConfig->approveEnabled()){
            $this->joinAttribute($select, 'approved', $storeId, [\Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES], true);  
        }
        if ($this->productStatusConfig->onlineEnabled()){
            $this->joinAttribute($select, 'online', $storeId, [\Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES], true);
        }
        if ($this->productStatusConfig->weightEnabled()){
            $this->joinAttributeGt($select, 'weight', $storeId, 0);
        }

        if ($productIds !== null) {
            $select->where('e.entity_id IN (?)', $productIds);
        }
        $select->where('e.entity_id > ?', $lastProductId);
        $select->order('e.entity_id');
        $select->limit($batch);

        return $select;
    }
    private function joinAttributeGt(Select $select, string $attributeCode, int $storeId, int $whereValue): void{
        $field = $this->metadata->getLinkField();
        $attribute = $this->getAttributeByCode($attributeCode);
        $attributeTable = $this->getTable('catalog_product_entity_' . $attribute->getBackendType());
        $alias = $attributeCode . '_default';
        $storeAlias = $attributeCode . '_store';

        $whereCondition = $this->getConnection()->getCheckSql(
            $storeAlias . '.value_id > 0',
            $storeAlias . '.value',
            $alias . '.value'
        );

        $select->join(
            [$alias => $attributeTable],
            $this->getConnection()->quoteInto(
                $alias . '.' . $field . '= e.' . $field . ' AND ' . $alias . '.attribute_id = ?',
                $attribute->getAttributeId()
            ) . $this->getConnection()->quoteInto(
                ' AND ' . $alias . '.store_id = ?',
                Store::DEFAULT_STORE_ID
            ),
            []
        )->joinLeft(
            [$storeAlias => $attributeTable],
            $this->getConnection()->quoteInto(
                $storeAlias . '.' . $field . '= e.' . $field . ' AND ' . $storeAlias . '.attribute_id = ?',
                $attribute->getAttributeId()
            ) . $this->getConnection()->quoteInto(
                ' AND ' . $storeAlias . '.store_id = ?',
                $storeId
            ),
            []
        )->where(
            $whereCondition . ' > ?',
            $whereValue
        );
    }
    private function joinAttribute(Select $select, string $attributeCode, int $storeId, array $whereValue, bool $defaultJoinLeft): void
    {
        $field = $this->metadata->getLinkField();
        $attribute = $this->getAttributeByCode($attributeCode);
        $attributeTable = $this->getTable('catalog_product_entity_' . $attribute->getBackendType());
        $alias = $attributeCode . '_default';
        $storeAlias = $attributeCode . '_store';

        $whereCondition = $this->getConnection()->getCheckSql(
            $storeAlias . '.value_id > 0',
            $storeAlias . '.value',
            $alias . '.value'
        );

        if(!$defaultJoinLeft){
            $select->join(
                [$alias => $attributeTable],
                $this->getConnection()->quoteInto(
                    $alias . '.' . $field . '= e.' . $field . ' AND ' . $alias . '.attribute_id = ?',
                    $attribute->getAttributeId()
                ) . $this->getConnection()->quoteInto(
                    ' AND ' . $alias . '.store_id = ?',
                    Store::DEFAULT_STORE_ID
                ),
                []
            )->joinLeft(
                [$storeAlias => $attributeTable],
                $this->getConnection()->quoteInto(
                    $storeAlias . '.' . $field . '= e.' . $field . ' AND ' . $storeAlias . '.attribute_id = ?',
                    $attribute->getAttributeId()
                ) . $this->getConnection()->quoteInto(
                    ' AND ' . $storeAlias . '.store_id = ?',
                    $storeId
                ),
                []
            )->where(
                $whereCondition . ' IN (?)',
                $whereValue
            );
        }
        else{
            $select->joinLeft(
                [$alias => $attributeTable],
                $this->getConnection()->quoteInto(
                    $alias . '.' . $field . '= e.' . $field . ' AND ' . $alias . '.attribute_id = ?',
                    $attribute->getAttributeId()
                ) . $this->getConnection()->quoteInto(
                    ' AND ' . $alias . '.store_id = ?',
                    Store::DEFAULT_STORE_ID
                ),
                []
            )->where(
                $this->getConnection()->getIfNullSql($alias.'.value', 1) . 'IN(?)',
                \Magento\Eav\Model\Entity\Attribute\Source\Boolean::VALUE_YES
            );
        }
    }
}