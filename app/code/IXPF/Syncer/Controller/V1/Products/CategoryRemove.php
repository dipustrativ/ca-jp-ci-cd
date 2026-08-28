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

            //-- Log Payload
            $this->logger->writeDaily(json_encode($payload));

            $products = $this->getProductSkuFromId($payload['product_ids']);
            $productExistingCategories = $this->getExistingCategoriesFromProductIds($payload['product_ids']);
            $categoryIds = $payload['category_ids'];
            $removeResponse = [];
            $success = 0;
            $failed = 0;
            $notFound = 0;
            foreach ($products as $product) {
                try {
                    if (isset($productExistingCategories[$product['entity_id']]) && !empty($productExistingCategories[$product['entity_id']]['category_ids'])) {
                        $existingCategoryIds = explode(",", $productExistingCategories[$product['entity_id']]['category_ids']);
                        $deleteCategoryIds = array_values(array_intersect($existingCategoryIds, $categoryIds));
                        $this->logger->writeDaily("Removing categories from product SKU: ".$product['sku'].", Product ID: ".$product['entity_id'].", Existing Categories: ". $productExistingCategories[$product['entity_id']]['category_ids'] .", Removing Categories: ".implode("|", $categoryIds).", New Categories: N/A");
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
                        $this->logger->writeDaily("Removing categories from product SKU: ".$product['sku'].", Product ID: ".$product['entity_id'].", Existing Categories: None, Removing Categories: ".implode("|", $categoryIds).", New Categories: None");
                    }
                } catch (Exception $exception) {
                    $failed += 1;
                    $removeResponse[] = [
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
            $this->logger->writeExceptionDaily($exception, "Bulk Category remove");
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