<?php

namespace IXPF\Syncer\Model\Api\Product;

use IXPF\Syncer\Api\Product\ProductApi;

use IXPF\Syncer\Api\Product\ProductListResponseApi;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class Product implements ProductApi
{
    protected $productCollectionFactory;
    protected $categoryCollectionFactory;

    protected $request;

    protected $productResponseApi;
    protected $productListResponseApi;
    protected $categoryFactory;
    protected $resourceConnection;

    public function __construct(
        RequestInterface           $request,
        ProductCollectionFactory   $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductResponseFactory     $productResponseApi,
        ProductListResponseFactory $productListResponseApi,
        CategoryInterfaceFactory $categoryFactory,
        ResourceConnection $resourceConnection
    )
    {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->request = $request;
        $this->productResponseApi = $productResponseApi;
        $this->productListResponseApi = $productListResponseApi;
        $this->categoryFactory = $categoryFactory;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @return ProductListResponseApi
     * @throws LocalizedException
     */
    public function list(): ProductListResponseApi
    {
        $page = (int)$this->request->getParam('page', 1);
        $pageSize = (int)$this->request->getParam('limit', 20);

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('visibility', [
            'in' => [
                Visibility::VISIBILITY_IN_CATALOG,
                Visibility::VISIBILITY_IN_SEARCH,
                Visibility::VISIBILITY_BOTH
            ]
        ]);
        $collection->setPageSize($pageSize);
        $collection->setCurPage($page);

        $totalCount = $collection->getSize();
        $totalPages = ceil($totalCount / $pageSize);

        $items = [];
        foreach ($collection as $product) {
            $items[] = $this->productResponseApi->create([
                'data' => [
                    'id' => $product->getId(),
                    'sku' => $product->getSku(),
                    'type' => $product->getTypeId(),
                    'status' => $product->getStatus(),
                    'categories' => $this->getCategory($product->getCategoryIds()),
                    'created_at' => $product->getCreatedAt(),
                    'updated_at' => $product->getUpdatedAt(),
                    'translations' => $this->getAttributeValues($product),
                ]
            ]);
        }

        return $this->productListResponseApi->create([
            'data' => [
                'items' => $items,
                'total_count' => $totalCount,
                'page_size' => $collection->getPageSize(),
            ]
        ]);

    }

    /**
     * @param array $categoryIds
     * @return array
     * @throws LocalizedException
     */
    private function getCategory(array $categoryIds): array
    {
        $categoryData = [];
        if (!empty($categoryIds)) {
            $categoryCollection = $this->categoryCollectionFactory->create();
            $categoryCollection->addAttributeToSelect('name')
                ->addAttributeToFilter('entity_id', ['in' => $categoryIds]);
            foreach ($categoryCollection as $category) {
                $categoryObj = $this->categoryFactory->create();
                $categoryObj->setId((int)$category->getId());
                $categoryObj->setName((string)$category->getName());
                $categoryObj->setParentId((int)$category->getParentId());
                $categoryData[] = $categoryObj;
            }
        }
        return $categoryData;
    }

    private function getAttributeValues($product): array
    {
        $data = [];
        $productId = $product->getId();
        $connection = $this->resourceConnection->getConnection();

        foreach ($product->getAttributes() as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $backendType = $attribute->getBackendType(); // varchar, int, text, etc.
            $attributeId = $attribute->getAttributeId();

            //-- Skip static attributes like 'entity_id'
            if ($backendType === 'static') {
                continue;
            }

            //-- Get table name
            $table = "catalog_product_entity_{$backendType}";

            //-- Fetch values for all stores
            $select = $connection->select()
                ->from($table, ['store_id', 'value'])
                ->where('entity_id = ?', $productId)
                ->where('attribute_id = ?', $attributeId);

            $results = $connection->fetchAll($select);

            foreach ($results as $row) {
                $storeId = (int)$row['store_id'];
                $value = $row['value'];

                if (!isset($data[$storeId])) {
                    $data[$storeId] = [];
                }

                $data[$storeId][$attributeCode] = $value;
            }
        }

        return $data;
    }

}
