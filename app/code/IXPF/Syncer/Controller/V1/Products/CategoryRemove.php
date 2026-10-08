<?php

namespace IXPF\Syncer\Controller\V1\Products;

use Exception;
use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Helpers;
use IXPF\Syncer\Helper\Logger\BulkCategoryRemoveLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Model\CategoryLinkRepository;

class CategoryRemove extends AbstractController  implements HttpPostActionInterface, CsrfAwareActionInterface
{

    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected BulkCategoryRemoveLogger $logger,
        protected Helpers $helpers,
        protected ResourceConnection $resourceConnection,
        protected CategoryLinkRepository $categoryLinkRepository
    )
    {
        parent::__construct($context, $tokenValidator, $resultFactory);
    }

    public function execute()
    {
        try {
            $this->tokenValidator();

            $rawContent = $this->getRequest()->getContent();
            $payload = json_decode($rawContent, true);
            if (empty($payload)) {
                throw new Exception("Empty category and product information");
            }
            if (empty($payload['category_ids'])) {
                throw new Exception("Empty category ids");
            }
            if (empty($payload['product_ids'])) {
                throw new Exception("Empty product ids");
            }

            $productIds = $this->normalizeIds($payload['product_ids']);
            $categoryIds = $this->normalizeIds($payload['category_ids']);
            if ($productIds === null || $categoryIds === null) {
                $this->logger->writeDaily('[category_remove] Invalid ids in payload: ' . json_encode($payload), 'error');
                return $this->jsonResponse([
                    'error' => 'product_ids and category_ids must be arrays of positive integers'
                ], 422);
            }

            //-- Log Payload
            $this->logger->writeDaily('[category_remove] payload: ' . json_encode($payload));

            $products = $this->getProductSkuFromId($productIds);
            $productExistingCategories = $this->getExistingCategoriesFromProductIds($productIds);
            $removeResponse = [];
            $success = 0;
            $failed = 0;
            $notFound = 0;
            foreach ($products as $product) {
                try {
                    if (isset($productExistingCategories[$product['entity_id']]) && !empty($productExistingCategories[$product['entity_id']]['category_ids'])) {
                        $existingCategoryIds = $productExistingCategories[$product['entity_id']]['category_ids'];
                        $deleteCategoryIds = array_values(array_intersect($existingCategoryIds, $categoryIds));
                        $this->logger->writeDaily("[category_remove] Removing categories from product SKU: ".$product['sku'].", Product ID: ".$product['entity_id'].", Existing Categories: ". implode(",", $existingCategoryIds) .", Removing Categories: ".implode("|", $categoryIds).", New Categories: N/A");
                        if (!empty($deleteCategoryIds)) {
                            $this->deleteCategoriesFromProduct($deleteCategoryIds, (int)$product['entity_id']);
                        }

                        $success += 1;
                        $removeResponse[] = [
                            'success' => true,
                            'product_id' => $product['entity_id']
                        ];
                    } else {
                        $notFound += 1;
                        $removeResponse[] = [
                            'success' => false,
                            'product_id' => $product['entity_id'],
                            'error' => 'No existing categories found'
                        ];
                        $this->logger->writeDaily("[category_remove] Removing categories from product SKU: ".$product['sku'].", Product ID: ".$product['entity_id'].", Existing Categories: None, Removing Categories: ".implode("|", $categoryIds).", New Categories: None");
                    }
                } catch (Exception $exception) {
                    $failed += 1;
                    $this->logger->writeDaily(
                        "[category_remove] Failed product ID: {$product['entity_id']} SKU: {$product['sku']} Error: "
                        . $exception->getMessage(),
                        'error'
                    );
                    $removeResponse[] = [
                        'success' => false,
                        'product_id' => $product['entity_id'],
                        'error' => $exception->getMessage()
                    ];
                }
            }

            $this->logger->writeDaily(
                "[category_remove] Completed: {$success} success, {$notFound} not found, {$failed} failed. Details: "
                . json_encode($removeResponse)
            );

            //-- failed message
            if (count($productIds) == $failed) {
                return $this->jsonResponse([
                    'success' => false,
                    'total_success' =>  $success,
                    'not_found' => $notFound,
                    'total_failed' => $failed,
                    'details' => $removeResponse
                ], 500);
            }

            return $this->jsonResponse([
                'success' => true,
                'total_success' =>  $success,
                'total_failed' => $failed,
                'not_found' => $notFound,
                'details' => $removeResponse
            ], 200);

        } catch (Exception $exception) {
            $this->logger->writeExceptionDaily($exception, "[category_remove] Bulk Category remove");
            $this->logger->writeDaily('[category_remove] Failed bulk category remove. Error: ' . $exception->getMessage(), 'error');

            return $this->jsonResponse(['error' => $exception->getMessage()], 500);
        }
    }


    /**
     * @param array $productIds
     * @return array
     */
    private function getExistingCategoriesFromProductIds(array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_category_product'), ['product_id', 'category_id'])
            ->where('product_id IN (?)', $productIds)
            ->order(['product_id', 'category_id']);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['product_id']]['category_ids'][] = (int)$row['category_id'];
        }
        return $result;
    }

    /**
     * @param array $categoryIds
     * @param int $productId
     * @return void
     */
    private function deleteCategoriesFromProduct(array $categoryIds, int $productId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_category_product');
        $connection->delete(
            $tableName,
            [
                'product_id = ?' => $productId,
                'category_id IN (?)' => $categoryIds
            ]
        );
    }


    /**
     * @param array $productIds
     * @return array
     */
    private function getProductSkuFromId(array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
            ->where('entity_id IN (?)', $productIds);
        return $connection->fetchAll($select);
    }


    /**
     * Payload id list → unique positive ints. Null when any entry is not a positive integer.
     *
     * @param mixed $ids
     * @return int[]|null
     */
    private function normalizeIds(mixed $ids): ?array
    {
        if (!is_array($ids) || $ids === []) {
            return null;
        }
        $clean = [];
        foreach ($ids as $id) {
            if (!(is_int($id) || (is_string($id) && ctype_digit($id))) || (int)$id <= 0) {
                return null;
            }
            $clean[] = (int)$id;
        }
        return array_values(array_unique($clean));
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
