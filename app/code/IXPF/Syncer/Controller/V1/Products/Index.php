<?php

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Helper\Image;
use Magento\Eav\Model\Config;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\InventorySalesAdminUi\Model\GetSalableQuantityDataBySku;


class Index extends AbstractController
{
    protected $tokenValidator;
    protected ProductCollectionFactory $productCollectionFactory;
    protected GetSalableQuantityDataBySku $getSalableQuantityDataBySku;
    protected CategoryCollectionFactory $categoryFactory;
    protected ResourceConnection $resourceConnection;
    protected Image $imageHelper;
    protected RequestInterface $request;
    protected ProductRepository $productRepository;
    protected Config $eavConfig;

    public function __construct(
        Context                   $context,
        TokenValidator            $tokenValidator,
        JsonFactory               $resultFactory,
        ProductCollectionFactory  $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        GetSalableQuantityDataBySku $getSalableQuantityDataBySku,
        ResourceConnection        $resourceConnection,
        Image                     $imageHelper,
        RequestInterface          $request,
        ProductRepository         $productRepository,
        Config                    $eavConfig
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryFactory = $categoryCollectionFactory;
        $this->getSalableQuantityDataBySku = $getSalableQuantityDataBySku;
        $this->resourceConnection = $resourceConnection;
        $this->imageHelper = $imageHelper;
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->eavConfig = $eavConfig;
    }


    public function execute()
    {
        try {
            //-- token validation check
            $this->tokenValidator();

            if ($productId = $this->getRequest()->getParam('product_id')) {
                $store = $this->getRequest()->getParam('store');
                $product = $this->productRepository->getById($productId,false, intval($store));
                return $this->jsonResponse($this->getProduct($product), 200);
            } elseif ($productSku = $this->getRequest()->getParam('product_sku')) {
                $store = $this->getRequest()->getParam('store');
                $product = $this->productRepository->get($productSku,false, intval($store));
                return $this->jsonResponse($this->getProduct($product), 200);
            } else {
                $page = $this->getRequest()->getParam('page', 1);
                $pageSize = $this->getRequest()->getParam('limit', 10);
                $paramFromId = $this->getRequest()->getParam('from_id');
                $paramToId = $this->getRequest()->getParam('to_id');
                $paramNewProduct = $this->getRequest()->getParam('new_product');

                $collection = $this->productCollectionFactory->create();

                if ($paramFromId > 0 && $paramToId > $paramFromId) {
                    $collection->addFieldToFilter('entity_id', ['gteq' => $paramFromId]);
                    $collection->addFieldToFilter('entity_id', ['lteq' => $paramToId]);
                } else {
                    $collection->setPageSize($pageSize);
                    $collection->setCurPage($page);
                }

                $collection->setOrder('entity_id', 'DESC');
                $totalCount = $collection->getSize();

                $items = [];
                foreach ($collection as $product) {
                    $product = $this->productRepository->getById($product->getId());
                    $items[] = $this->getProduct($product);
                }

                if ($paramFromId > 0 && $paramToId > $paramFromId) {
                    $pageSize = $totalCount;
                }

                return $this->jsonResponse([
                    'items' => $items,
                    'total_count' => intval($totalCount),
                    'current_page' => intval($page),
                    'page_size' => intval($pageSize),
                ], 200);
            }
        } catch (\Exception $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 400);
        }
    }

    /**
     * @throws LocalizedException
     */
    private function getProduct($product): array
    {
        $imageStatus = 0;
        $imagePath = $product->getThumbnail();
        $mainImageUrl = $this->getImageUrl($imagePath);
        if ($imagePath != "no_selection" &&
            basename($mainImageUrl) != "image.jpg" &&
            basename($mainImageUrl) != "small_image.jpg") {
            $imageStatus = 1;
        }

        $configurableLinkProducts = [];
        $attributesCodeWithLabel = [];
        if ($product->getTypeId() == 'configurable') {
            $configurableProducts = $this->getConfigurableProducts($product);
            if (count($configurableProducts)) {
                $configurableLinkProducts = $configurableProducts['child_products'];
                $attributesCodeWithLabel = $configurableProducts['configurable_attributes'];
            }
        }

        $stockInventory = $this->getProductInventories($product->getSku());
        $totalQty = 0;
        $isInStock = 0;
        $stockManage = 0;
        foreach ($stockInventory as $stockItem) {
            $totalQty += intval($stockItem['quantity'] ?? 0);
            if (intval($stockItem['status'] ?? 0) == 1) {
                $stockManage = 1;
            }
        }
        if ($totalQty > 0) {
            $isInStock = 1;
        }

        try {
            $saleableQuantity = $product->getExtensionAttributes()->getStockItem()->getQty();
        } catch (\Exception $e) {
            $saleableQuantity = 0;
        }

        try {
            $saleableInventory = $this->getSalableQuantityDataBySku->execute($product->getSku());
        } catch (\Exception $e) {
            $saleableInventory = [];
        }

        $product = [
            'id' => intval($product->getId()),
            'sku' => $product->getSku(),
            'type' => $product->getTypeId(),
            'status' => $this->getOriginalProductDBStatus(intval($product->getId())),
            'visibility' => intval($product->getVisibility()),
            'name' => $product->getName(),
            'base_price' => floatval($product->getPrice()),
            'base_final_price' => floatval($product->getFinalPrice()),
            'special_price' => floatval($product->getSpecialPrice()),
            'special_price_from_date' => $product->getSpecialFromDate(),
            'special_price_to_date' => $product->getSpecialToDate(),
            'attribute_set_id' => intval($product->getAttributeSetId()),
            'store_ids' => $product->getStoreIds(),
            'stock_quantity' => floatval($totalQty),
            'salable_quantity' => floatval($saleableQuantity),
            'is_in_stock' => floatval($isInStock),
            'stock_manage' => intval($stockManage),
            'source_inventory' => $stockInventory,
            'stock_inventory' => $saleableInventory,
            'url_key' => $product->getProductUrl(),
            'image_status' => intval($imageStatus),
            'main_image' => [
                'path' => $product->getThumbnail(),
                'url' => $this->getImageUrl($product->getThumbnail())
            ],
            'gallery_images' => $this->getGalleryImages($product),
            'categories' => $this->getCategory($product->getCategoryIds(), intval($product->getId())),
            'store_attributes' => $this->getAttributeValues($product),
            'created_at' => $product->getCreatedAt(),
            'updated_at' => $product->getUpdatedAt(),
        ];

        if (count($configurableLinkProducts)) {
            $product['configurable_product_links'] = $configurableLinkProducts;
        }
        if (count($attributesCodeWithLabel)) {
            $product['configurable_attributes'] = $attributesCodeWithLabel;
        }

        return $product;
    }

    /**
     * @param array $categoryIds
     * @param int $productId
     * @return array
     */
    private function getCategory(array $categoryIds, int $productId): array
    {
        $categoryData = [];
        if (!empty($categoryIds)) {
            $connection = $this->resourceConnection->getConnection();
            $sql = $connection->select()
                ->from('catalog_category_entity', ['entity_id', 'path'])
                ->joinLeft(
                    'catalog_category_entity_varchar',
                    'catalog_category_entity_varchar.entity_id = catalog_category_entity.entity_id AND catalog_category_entity_varchar.attribute_id = 45 AND catalog_category_entity_varchar.store_id = 0',
                    ['name' => 'catalog_category_entity_varchar.value']
                )
                ->where('catalog_category_entity.entity_id IN (?)', $categoryIds);
            $results = $connection->fetchAll($sql);

            //-- get the product position in categories
            $productPositionSql = $connection->select()
                ->from('catalog_category_product', ['category_id', 'position'])
                ->where('catalog_category_product.product_id = ?', $productId)
                ->where('catalog_category_product.category_id IN (?)', $categoryIds);
            $categoryProductPositions = $connection->fetchAssoc($productPositionSql);

            foreach ($results as $row) {
                $position = 1000;
                if (isset($categoryProductPositions[$row['entity_id']])) {
                    $position = intval($categoryProductPositions[$row['entity_id']]['position']);
                }
                $categoryData[] = [
                    'id' => intval($row['entity_id']),
                    'name' => $row['name'],
                    'full_path' => $row['path'],
                    'position' => $position
                ];
            }

        }
        return $categoryData;
    }

    private function getAttributeValues($product): ?array
    {
        $productId = $product->getId();
        $connection = $this->resourceConnection->getConnection();
        $data = [];
        //-- not include into the attributes
        $notNeed = [
            //'price',
            //'special_price',
            //'special_from_date',
            //'special_to_date',
            'image',
            'small_image',
            'thumbnail',
            'swatch_image',
            //'options_container',
            //'gift_message_available',
            //'url_internal_update',
            //'order_increment_id',
            //'quantity_and_stock_status',
            //'redirect_to_configurable_product',
            'status',
        ];

        foreach ($product->getAttributes() as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $backendType = $attribute->getBackendType();
            $attributeId = $attribute->getAttributeId();

            if (in_array($attributeCode, $notNeed)) {
                continue;
            }

            if ($backendType === 'static') {
                continue;
            }

            $table = "catalog_product_entity_{$backendType}";

            $select = $connection->select()
                ->from($table, ['store_id', 'value'])
                ->where('entity_id = ?', $productId)
                ->where('attribute_id = ?', $attributeId);

            $results = $connection->fetchAll($select);

            foreach ($results as $row) {
                $storeId = strval($row['store_id'] ?: 0);
                $value = $row['value'];

                if (!isset($data["store-" . $storeId])) {
                    $data["store-" . $storeId] = [];
                }

                if ($backendType == "int") {
                    $value = intval($value);
                } elseif ($backendType == "decimal") {
                    $value = floatval($value);
                } else {
                    $value = strval($value);
                }

                $data["store-" . $storeId][] = [
                    'name' => $attribute->getFrontendLabel(),
                    'code' => strval($attributeCode),
                    'value' => $value
                ];
            }
        }

        return count($data) ? $data : null;
    }

    /**
     * @throws LocalizedException
     */
    private function getGalleryImages($product): array
    {
        $data = [];
        try {
            $images = $product->getMediaGalleryEntries();
            if (empty($images)) {
                return $data;
            }

            $productId = $product->getId();

            foreach ($images as $image) {
                $imageRoles = $this->getImageRoles($productId, $image->getData('file'));

                $data[] = [
                    'id' => intval($image->getId()),
                    'path' => $image->getData('file'),
                    'type' => $image->getData('media_type'),
                    'url' => $this->getImageUrl($image->getData('file')),
                    'position' => intval($image->getData('position')),
                    'disabled' => intval($image->getData('disabled')),
                    'label' => $image->getData('label'),
                    'roles' => $imageRoles
                ];
            }
        } catch (\Exception $exception) {
            return [];
        }
        return $data;
    }

    private function getImageUrl($imagePath): string
    {
        if ($imagePath && $imagePath !== 'no_selection') {
            // Get base media URL and construct the full image URL
            $baseMediaUrl = $this->_url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]);
            $mainImageUrl = $baseMediaUrl . 'catalog/product' . $imagePath;
        } else {
            // Use a placeholder image if no image is set
            $mainImageUrl = $this->imageHelper->getDefaultPlaceholderUrl('image');
        }
        return $mainImageUrl;
    }

    private function getConfigurableProducts($product): array
    {
        $configurableLinkProducts = [];
        $configurableAttributes = [];
        $attributesCodeWithLabel = [];
        $configurableType = $product->getTypeInstance();
        $usedAttributes = $configurableType->getConfigurableAttributes($product);
        $attributeCodes = [
            'name', 'thumbnail', 'swatch_image', 'price', 'final_price', 'special_price', 'special_from_date', 'special_to_date', 'image'
        ];
        foreach ($usedAttributes as $attr) {
            $attributeCodes[] = $attr->getProductAttribute()->getAttributeCode();
            $configurableAttributes [$attr->getProductAttribute()->getAttributeCode()] = $attr->getProductAttribute()->getFrontendLabel();
            $attributesCodeWithLabel[] = [
                'attribute_code' => $attr->getProductAttribute()->getAttributeCode(),
                'label' => $attr->getProductAttribute()->getFrontendLabel(),
            ];
        }

        $childIds = $configurableType->getUsedProductIds($product);
        $collection = $this->productCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $childIds]);
        $collection->addAttributeToSelect($attributeCodes);
        if (count($collection->getItems()) == 0) {
            return [];
        }

        foreach ($collection as $configurableProduct) {
            $configurableProductDetails = [
                'id' => intval($configurableProduct->getId()),
                'name' => $configurableProduct->getName(),
                'sku' => $configurableProduct->getSku(),
                'status' => intval($configurableProduct->getStatus()),
                'base_price' => floatval($configurableProduct->getPrice()),
                'base_final_price' => floatval($configurableProduct->getFinalPrice()),
                'special_price' => floatval($configurableProduct->getSpecialPrice()),
                'special_price_from_date' => $configurableProduct->getSpecialFromDate(),
                'special_price_to_date' => $configurableProduct->getSpecialToDate(),
                'main_image' => [
                    'path' => $product->getThumbnail(),
                    'url' => $this->getImageUrl($product->getThumbnail())
                ],
                'swatch_image' => [
                    'path' => $configurableProduct->getData('swatch_image'),
                    'url' => $this->getImageUrl($configurableProduct->getData('swatch_image')),
                ],
                'configurable_attributes' => []
            ];
            foreach ($configurableAttributes as $code => $label) {
                $optionId = $configurableProduct->getData($code);
                if ($optionId !== null) {
                    $attribute = $this->eavConfig->getAttribute('catalog_product', $code);
                    $label = $attribute->getSource()->getOptionText($optionId);
                    $configurableProductDetails['configurable_attributes'][] = [
                        'attribute_code' => $code,
                        'attribute_value' => $optionId,
                        'attribute_frontend_label' => $label
                    ];
                }
            }
            $configurableLinkProducts[] = $configurableProductDetails;
        }

        return [
            'child_products' => $configurableLinkProducts,
            'configurable_attributes' => $attributesCodeWithLabel
        ];
    }

    private function getProductInventories($productSku)
    {
        $query = "SELECT
                si.source_code,
                si.quantity,
                si.status
            FROM
                catalog_product_entity AS cpe
            JOIN
                inventory_source_item AS si
                ON cpe.sku = si.sku
            WHERE
                cpe.sku = '{$productSku}';
            ";

        $connection = $this->resourceConnection->getConnection();
        $data = [];
        foreach ($connection->fetchAll($query) as $row) {
            $data[] = [
                'source_code' => $row['source_code'],
                'quantity' => floatval($row['quantity']),
                'status' => intval($row['status'])
            ];
        }
        return $data;
    }

    /**
     * @throws LocalizedException
     */
    private function getImageRoles($productId, $imagePath): array
    {
        $connection = $this->resourceConnection->getConnection();
        $roles = [];

        // Define image role attributes
        $imageAttributes = ['image', 'small_image', 'thumbnail', 'swatch_image'];

        foreach ($imageAttributes as $attributeCode) {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $attributeCode);
            if (!$attribute || !$attribute->getId()) {
                continue;
            }

            $select = $connection->select()
                ->from('catalog_product_entity_varchar', ['value'])
                ->where('entity_id = ?', $productId)
                ->where('attribute_id = ?', $attribute->getId())
                ->where('store_id = ?', 0);

            $value = $connection->fetchOne($select);

            if ($value === $imagePath) {
                $roles[] = $attributeCode;
            }
        }

        return $roles;
    }

    /**
     * Get the original product status from the database, not from the product model, to avoid any modification by plugins or observers
     *
     * @param int $productId
     * @return int
     */
    protected function getOriginalProductDBStatus(int $productId): int
    {
        $connection = $this->resourceConnection->getConnection();

        $statusAttributeQuery = $connection->select()
            ->from('eav_attribute', ['attribute_id'])
            ->where('entity_type_id = ?', 4) // 4 is the entity_type_id for products
            ->where('attribute_code = ?', 'status');
        $statusAttributeId = $connection->fetchOne($statusAttributeQuery);
        $select = $connection->select()
            ->from('catalog_product_entity_int', ['value'])
            ->where('entity_id = ?', $productId)
            ->where('attribute_id = ?', $statusAttributeId);

        return intval($connection->fetchOne($select));
    }

}
