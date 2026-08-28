<?php

namespace CarpetAvenue\Productify\Cron;

use CarpetAvenue\Productify\Controller\Adminhtml\RewriteURL\GenerateMissing;
use CarpetAvenue\Productify\Helper\GenerateMissingUrlLogger;
use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;

class GenerateMissingUrls
{
    public function __construct(
        protected GenerateMissingUrlLogger $logger,
        protected JsonFactory $jsonFactory,
        protected CollectionFactory $productCollectionFactory,
        protected ResourceConnection $resourceConnection,
        protected StoreRepositoryInterface $storeRepository,
        protected ProductRepositoryInterface $productRepository,
        protected UrlPersistInterface $urlPersist,
        protected ProductUrlPathGenerator $productUrlPathGenerator,
        protected ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        protected StoreManagerInterface $storeManager
    )
    {
    }


    public function execute(): void
    {
        $this->logger->info("PRODUCTIFY:: Starting generation of missing URL rewrites via cron job.");

        $processedCount = 0;
        try {
            $stores = $this->storeRepository->getList();
            foreach ($stores as $store) {
                if ($store->getId() == 0) {
                    continue;
                }

                $storeTotal = 0;
                $storeId = $store->getId();
                $missingProducts = $this->getMissingUrlProducts($storeId, 150);
                if (empty($missingProducts)) {
                    break;
                } else {
                    foreach ($missingProducts as $missingProduct) {
                        try {
                            $storeTotal++;
                            $sku = $missingProduct['sku'];
                            $urlPath = $missingProduct['url_path'];
                            $requestPath = $missingProduct['request_path'];
                            $product = $this->productRepository->get($missingProduct['sku'], true, $store->getId());
                            $productId = $product->getId();
                            $product->unsUrlPath();
                            $product->setUrlPath($this->productUrlPathGenerator->getUrlPath($product));
                            $this->urlPersist->replace($this->productUrlRewriteGenerator->generate($product));
                            $this->logger->info("$storeTotal | $productId | $sku | $urlPath | $requestPath");
                            $processedCount++;
                        } catch (Exception $e) {
                            $this->logger->error("SKU {$missingProduct['sku']}: " . $e->getMessage());
                        }
                    }

                    $this->logger->info("Store: ".$store->getName()."(".$store->getId().") - Total products processed: $storeTotal");
                }
            }

            if (!$processedCount) {
                $this->logger->info("PRODUCTIFY:: No missing URL rewrites found.");
                return;
            }

            $message = "URL rewrite generation Complete.";
            $message .= "Total products processed: $processedCount";
            $this->logger->info("PRODUCTIFY:: " . $message);

        } catch (Exception $e) {
            $this->logger->error("PRODUCTIFY:: Error generating URLs: " . $e->getMessage());
        }
    }

    private function getMissingUrlProducts($storeId, int $limit = 50): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $missingUrls = [];

            // Getting all attribute id
            $statusAttributeId = $this->resourceConnection->getConnection()
                ->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'status' AND entity_type_id = 4");

            $visibilityAttributeId = $this->resourceConnection->getConnection()
                ->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'visibility' AND entity_type_id = 4");

            $urlKeyAttributeId = $this->resourceConnection->getConnection()
                ->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'url_key' AND entity_type_id = 4");

            // Query to find products with url_path but no corresponding URL rewrite
            $sql = "SELECT
                        CPE.entity_id,
                        CPE.sku,
                        CPEI.value AS status,
                        CPEV.value AS url_path,
                        $storeId as store_id,
                        URR.request_path AS request_path
                    FROM
                        catalog_product_entity AS CPE
                            LEFT JOIN catalog_product_entity_varchar AS CPEV ON
                                  CPE.entity_id = CPEV.entity_id AND CPEV.store_id = $storeId AND CPEV.attribute_id = $urlKeyAttributeId
                            LEFT JOIN catalog_product_entity_int AS CPEI ON
                                  CPE.entity_id = CPEI.entity_id AND CPEI.store_id = 0 AND CPEI.attribute_id = $statusAttributeId
                            LEFT JOIN catalog_product_entity_int AS CPEI2 ON
                                  CPE.entity_id = CPEI2.entity_id AND CPEI2.store_id = 0 AND CPEI2.attribute_id = $visibilityAttributeId
                            LEFT JOIN url_rewrite AS URR ON
                                  URR.entity_id = CPE.entity_id AND URR.entity_type = 'product' AND URR.target_path LIKE 'catalog/product/view/id/%' AND URR.store_id = $storeId
                    WHERE
                        CPEI.value = 1 AND CPEV.value IS NOT NULL AND CPEV.value != '' AND URR.request_path IS NOT NULL AND URR.request_path != '' AND URR.request_path != CPEV.value
                    ORDER BY
                        CPE.entity_id
                    LIMIT $limit";

            $missingInStore = $connection->fetchAll($sql);

            foreach ($missingInStore as $missing) {
                $urlPath = $missing['url_path'];
                $requestPath = $missing['request_path'];

                if ($urlPath === $requestPath) {
                    continue;
                }

                $missingUrls[] = [
                    'entity_id'     => $missing['entity_id'],
                    'sku'           => $missing['sku'],
                    'url_path'      => $missing['url_path'],
                    'request_path'  => $missing['request_path'],
                    'store_id'      => $missing['store_id']
                ];
            }

            // Format missing URLs for display
            $uniqueSkus = [];
            foreach ($missingUrls as $missing) {
                // Collect unique SKUs
                if (!in_array($missing['sku'], $uniqueSkus)) {
                    $uniqueSkus[] = array(
                        'sku' => $missing['sku'],
                        'url_path' => $missing['url_path'],
                        'request_path' => $missing['request_path'],
                        'entity_id' => $missing['entity_id']
                    );
                }
            }

            return $uniqueSkus;

        } catch (Exception) {
            return [];
        }
    }


}