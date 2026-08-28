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

            if (!$this->helpers->getEnableBulkCategoryAssign()) {
                throw new \Exception("Bulk category assign is disabled currently");
            }

            //-- Log Payload
            $this->logger->writeDaily(json_encode($payload));

            $products = $this->getProductSkuFromId($payload['product_ids']);
            $productExistingCategories = $this->getExistingCategoriesFromProductIds($payload['product_ids']);
            $categoryIds = $payload['category_ids'];
            $assignResponse = [];
            $success = 0;
            $failed = 0;
            foreach ($products as $product) {
                try {
                    //-- check existing categories and merge
                    $insertCategories = $categoryIds;
                    if (isset($productExistingCategories[$product['entity_id']]) && !empty($productExistingCategories[$product['entity_id']]['category_ids'])) {
                        $existingCategoryIds = explode(",", $productExistingCategories[$product['entity_id']]['category_ids']);
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
                    $assignResponse[] = [
                        'success' => false,
                        'product_id' => $product['entity_id'],
                        'error' => $exception->getMessage()
                    ];
                }
            }

            //-- failed message
            if (count($payload['product_ids']) == $failed) {
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
            $this->logger->writeExceptionDaily($exception, "Bulk Category assign");
            $this->logger->writeDaily($exception->getMessage());

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
        $sql = "SELECT product_id, GROUP_CONCAT(category_id ORDER BY category_id) AS category_ids FROM catalog_category_product WHERE product_id IN (".implode(",", $productIds).") GROUP BY product_id";
        return $connection->fetchAssoc($sql);
    }


    /**
     * @param array $productIds
     * @return array
     */
    private function getProductSkuFromId(array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $sql = "SELECT `entity_id`,`sku` FROM `catalog_product_entity` WHERE `entity_id` IN (". implode(",", $productIds). ")";
        return $connection->fetchAll($sql);
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