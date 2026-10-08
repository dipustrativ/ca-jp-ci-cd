<?php

namespace IXPF\Syncer\Controller\V1\Products;

use IXPF\Syncer\Controller\AbstractController;
use IXPF\Syncer\Helper\Logger\BulkCategoryAssignLogger;
use IXPF\Syncer\Helper\TokenValidator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use IXPF\Syncer\Helper\Helpers;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Catalog\Api\CategoryLinkManagementInterface;

class CategoryAssign extends AbstractController  implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        Context $context,
        TokenValidator $tokenValidator,
        JsonFactory $resultFactory,
        protected Helpers $helpers,
        protected ResourceConnection $resourceConnection,
        protected CategoryLinkManagementInterface $categoryLinkManagement,
        protected BulkCategoryAssignLogger $logger
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
                throw new \Exception("Empty category and product information");
            }
            if (empty($payload['category_ids'])) {
                throw new \Exception("Empty category ids");
            }
            if (empty($payload['product_ids'])) {
                throw new \Exception("Empty product ids");
            }

            $productIds = $this->normalizeIds($payload['product_ids']);
            $categoryIds = $this->normalizeIds($payload['category_ids']);
            if ($productIds === null || $categoryIds === null) {
                $this->logger->writeDaily('[category_assign] Invalid ids in payload: ' . json_encode($payload), 'error');
                return $this->jsonResponse([
                    'error' => 'product_ids and category_ids must be arrays of positive integers'
                ], 422);
            }

            if (!$this->helpers->getEnableBulkCategoryAssign()) {
                throw new \Exception("Bulk category assign is disabled currently");
            }

            //-- Log Payload
            $this->logger->writeDaily('[category_assign] payload: ' . json_encode($payload));

            $products = $this->getProductSkuFromId($productIds);
            $productExistingCategories = $this->getExistingCategoriesFromProductIds($productIds);
            $assignResponse = [];
            $success = 0;
            $failed = 0;
            foreach ($products as $product) {
                try {
                    //-- check existing categories and merge
                    $insertCategories = $categoryIds;
                    if (isset($productExistingCategories[$product['entity_id']]) && !empty($productExistingCategories[$product['entity_id']]['category_ids'])) {
                        $existingCategoryIds = $productExistingCategories[$product['entity_id']]['category_ids'];
                        $insertCategories = array_merge($existingCategoryIds, $insertCategories);
                    }

                    //-- assign categories
                    $this->categoryLinkManagement->assignProductToCategories($product['sku'], $insertCategories);

                    //-- set position to 1000 for newly assigned (position = 0) entries
                    $connection = $this->resourceConnection->getConnection();
                    $connection->update(
                        $connection->getTableName('catalog_category_product'),
                        ['position' => 1000],
                        [
                            'product_id = ?' => (int)$product['entity_id'],
                            'category_id IN (?)' => array_map('intval', $insertCategories),
                            'position = ?' => 0
                        ]
                    );

                    $success += 1;
                    $assignResponse[] = [
                        'success' => true,
                        'product_id' => $product['entity_id']
                    ];
                } catch (\Exception $exception) {
                    $failed += 1;
                    $this->logger->writeDaily(
                        "[category_assign] Failed product ID: {$product['entity_id']} SKU: {$product['sku']} Error: "
                        . $exception->getMessage(),
                        'error'
                    );
                    $assignResponse[] = [
                        'success' => false,
                        'product_id' => $product['entity_id'],
                        'error' => $exception->getMessage()
                    ];
                }
            }

            $this->logger->writeDaily(
                "[category_assign] Completed: {$success} success, {$failed} failed. Details: " . json_encode($assignResponse)
            );

            //-- failed message
            if (count($productIds) == $failed) {
                return $this->jsonResponse([
                    'success' => false,
                    'total_success' =>  $success,
                    'total_failed' => $failed,
                    'details' => $assignResponse
                ], 500);
            }

            return $this->jsonResponse([
                'success' => true,
                'total_success' =>  $success,
                'total_failed' => $failed,
                'details' => $assignResponse
            ], 200);

        } catch (\Exception $exception) {
            //-- Log
            $this->logger->writeExceptionDaily($exception, "[category_assign] Bulk Category assign");
            $this->logger->writeDaily('[category_assign] Failed bulk category assign. Error: ' . $exception->getMessage(), 'error');

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
