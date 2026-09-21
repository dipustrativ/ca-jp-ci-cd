<?php

namespace IXPF\Syncer\Controller\V2\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use Magento\Catalog\Model\ResourceModel\Product\Action;
use Magento\Framework\App\ResourceConnection;

class BulkPriceUpdate extends AbstractController implements HttpPostActionInterface, CsrfAwareActionInterface
{

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected ProductHelper $productHelper,
        protected ProductRepositoryInterface $productRepository,
        protected ProductUpdateLogger $productUpdateLogger,
        protected Action $productAction,
        protected ResourceConnection $resourceConnection
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            // Token validation check
            $this->tokenValidator();

            $store = $this->getRequest()->getParam('store');
            if (!$store) {
                $store = 0;
            }

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);

            if (empty($payload)) {
                throw new \Exception('Empty product information');
            }
            if (empty($payload['products']) || !is_array($payload['products']) || !count($payload['products'])) {
                throw new \Exception('Invalid or empty products');
            }

            if (!$this->productHelper->getEnableProductBulkUpdateConfig()) {
                throw new \Exception('Product bulk update configuration disabled');
            }

            $allowUpdateAttributes = $this->productHelper->getProductUpdateBulkAllowAttributes();
            $priceUpdateAllowed = in_array('price', $allowUpdateAttributes);
            $specialPriceUpdateAllowed = in_array('special_price', $allowUpdateAttributes);
            if (!$priceUpdateAllowed && !$specialPriceUpdateAllowed) {
                throw new \Exception('Price and Special Price updates are not allowed by configuration');
            }

            //-- log start
            $this->productUpdateLogger->writeDaily("Bulk update started for " . count($payload['products']) . " products");
            $this->productUpdateLogger->writeDaily(json_encode($payload));

            //-- Process all products
            $result = $this->processBulkUpdateOptimized($payload['products'], $store);

            //-- log end
            $this->productUpdateLogger->writeDaily(
                "Bulk update completed: {$result['success_count']} success, {$result['failed_count']} failed"
            );
            $this->productUpdateLogger->writeDaily(json_encode($result['details']));

            return $this->jsonResponse([
                'success' => $result['success_count'],
                'failed' => $result['failed_count'],
                'information' => $result['details']
            ]);

        } catch (\Exception $exception) {
            $this->productUpdateLogger->writeDaily("Bulk update failed: " . $exception->getMessage());
            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
        }
    }

    /**
     * Process all products
     */
    protected function processBulkUpdateOptimized(array $products, int $store): array
    {
        $result = [
            'success_count' => 0,
            'failed_count' => 0,
            'details' => []
        ];

        $validProducts = [];
        foreach ($products as $product) {
            if (empty($product['sku'])) {
                $result['details'][] = [
                    'sku' => '',
                    'success' => false,
                    'message' => 'Empty SKU'
                ];
                $result['failed_count']++;
                continue;
            }
            $validProducts[$product['sku']] = $product;
        }

        if (empty($validProducts)) {
            return $result;
        }

        $productIds = $this->getProductIdsBySku(array_keys($validProducts));
        $priceUpdates = [];
        $specialPriceUpdates = [];
        $bothUpdates = [];

        foreach ($validProducts as $sku => $productData) {
            if (!isset($productIds[$sku])) {
                $result['details'][] = [
                    'sku' => $sku,
                    'success' => false,
                    'message' => 'Product not found'
                ];
                $result['failed_count']++;
                continue;
            }

            $productId = $productIds[$sku];
            $hasPrice = !empty($productData['price']) && floatval($productData['price']) > 0;
            $hasSpecialPrice = !empty($productData['special_price']) && floatval($productData['special_price']) > 0;

            if (!$hasPrice && !$hasSpecialPrice) {
                $result['details'][] = [
                    'sku' => $sku,
                    'success' => false,
                    'message' => 'No valid price data'
                ];
                $result['failed_count']++;
                continue;
            }

            if ($hasPrice && $hasSpecialPrice) {
                $bothUpdates[$productId] = [
                    'sku' => $sku,
                    'price' => floatval($productData['price']),
                    'special_price' => floatval($productData['special_price'])
                ];
            } elseif ($hasPrice) {
                $priceUpdates[$productId] = [
                    'sku' => $sku,
                    'price' => floatval($productData['price'])
                ];
            } else {
                $specialPriceUpdates[$productId] = [
                    'sku' => $sku,
                    'special_price' => floatval($productData['special_price'])
                ];
            }
        }

        if (!empty($priceUpdates)) {
            $this->executeBatchUpdate(
                array_keys($priceUpdates),
                ['price' => array_column($priceUpdates, 'price')],
                $store,
                $result,
                $priceUpdates
            );
        }

        if (!empty($specialPriceUpdates)) {
            $this->executeBatchUpdate(
                array_keys($specialPriceUpdates),
                ['special_price' => array_column($specialPriceUpdates, 'special_price')],
                $store,
                $result,
                $specialPriceUpdates
            );
        }

        if (!empty($bothUpdates)) {
            foreach ($bothUpdates as $productId => $data) {
                try {
                    $this->productAction->updateAttributes(
                        [$productId],
                        [
                            'price' => $data['price'],
                            'special_price' => $data['special_price']
                        ],
                        $store
                    );

                    $result['details'][] = [
                        'sku' => $data['sku'],
                        'success' => true
                    ];
                    $result['success_count']++;
                } catch (\Exception $e) {
                    $result['details'][] = [
                        'sku' => $data['sku'],
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                    $result['failed_count']++;
                }
            }
        }

        return $result;
    }

    /**
     * Execute batch update of attributes
     */
    protected function executeBatchUpdate(array $productIds, array $attributes, int $store, array &$result, array $productData): void
    {
        try {
            $attrData = [];
            foreach ($attributes as $attrCode => $values) {
                foreach ($productIds as $index => $productId) {
                    if (!isset($attrData[$productId])) {
                        $attrData[$productId] = [];
                    }
                    $attrData[$productId][$attrCode] = $values[$index];
                }
            }

            // Update each product with its specific values
            foreach ($attrData as $productId => $attrs) {
                try {
                    $this->productAction->updateAttributes([$productId], $attrs, $store);

                    $sku = $productData[$productId]['sku'];
                    $result['details'][] = [
                        'sku' => $sku,
                        'success' => true
                    ];
                    $result['success_count']++;
                } catch (\Exception $exception) {
                    $sku = $productData[$productId]['sku'];
                    $this->productUpdateLogger->writeDaily("Failed to update product ID: {$sku} Error: " . $exception->getMessage());

                    $result['details'][] = [
                        'sku' => $sku,
                        'success' => false,
                        'message' => $exception->getMessage()
                    ];
                    $result['failed_count']++;
                }
            }
        } catch (\Exception $e) {
            $this->productUpdateLogger->writeDaily("Batch update error: " . $e->getMessage());

            // If batch fails completely, mark all as failed
            foreach ($productIds as $productId) {
                $sku = $productData[$productId]['sku'] ?? 'Unknown';
                $result['details'][] = [
                    'sku' => $sku,
                    'success' => false,
                    'message' => $e->getMessage()
                ];
                $result['failed_count']++;
            }
        }
    }

    /**
     * Get product IDs by SKU
     */
    protected function getProductIdsBySku(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity');

        $select = $connection->select()
            ->from($tableName, ['sku', 'entity_id'])
            ->where('sku IN (?)', $skus);

        return $connection->fetchPairs($select);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}