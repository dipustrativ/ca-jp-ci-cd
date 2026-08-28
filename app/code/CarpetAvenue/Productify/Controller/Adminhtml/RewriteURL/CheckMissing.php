<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Controller\Adminhtml\RewriteURL;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class CheckMissing extends Action
{
    public function __construct(
        Context $context,
        protected JsonFactory $jsonFactory,
        protected ResourceConnection $resourceConnection,
        protected StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $connection = $this->resourceConnection->getConnection();
            $missingUrls = [];

            // Get all stores
            $stores = $this->storeManager->getStores();

            // Getting all attribute id
            $statusAttributeId = $this->resourceConnection->getConnection()
                ->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'status' AND entity_type_id = 4");

            $visibilityAttributeId = $this->resourceConnection->getConnection()
                ->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'visibility' AND entity_type_id = 4");

            $urlKeyAttributeId = $this->resourceConnection->getConnection()
                ->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'url_key' AND entity_type_id = 4");

            foreach ($stores as $store) {
                $storeId = $store->getId();

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
                            CPE.entity_id";

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
                        'store_id'      => $missing['store_id'],
                        'store_name'    => $store->getName()
                    ];
                }
            }

            if (empty($missingUrls)) {
                return $result->setData([
                    'success' => true,
                    'message' => 'No missing URL rewrites found. All products have proper URL rewrites.',
                    'missing_urls' => []
                ]);
            }

            // Format missing URLs for display
            $formattedUrls = [];
            $uniqueSkus = [];
            $counter = 0;
            $shopID = '';
            foreach ($missingUrls as $missing) {
                if ($shopID !== $missing['store_id']) {
                    $shopID = $missing['store_id'];
                    $formattedUrls[] = "<hr><hr>";
                    $formattedUrls[] = "<strong style='font-size: 12px; font-weight: bold'>Store: " . $missing['store_name'] . " (ID: " . $missing['store_id'] . ")</strong>";
                    $formattedUrls[] = "<hr><hr>";
                    $counter = 0;
                }
                $counter++;
                $formattedUrls[] = sprintf(
                    '%s | %s | %s | %s | %s',
                    $counter,
                    $missing['entity_id'],
                    $missing['sku'],
                    $missing['url_path'],
                    $missing['request_path']
                );

                // Collect unique SKUs
                if (!in_array($missing['sku'], $uniqueSkus)) {
                    $uniqueSkus[] = $missing['sku'];
                }
            }

            return $result->setData([
                'success' => true,
                'message' => sprintf('Found %d products with missing URL rewrites:', count($missingUrls)),
                'missing_urls' => $formattedUrls,
                'unique_skus' => $uniqueSkus,
                'unique_sku_count' => count($uniqueSkus)
            ]);

        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => 'Error checking missing URLs: ' . $e->getMessage()
            ]);
        }
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Productify::productify');
    }
}