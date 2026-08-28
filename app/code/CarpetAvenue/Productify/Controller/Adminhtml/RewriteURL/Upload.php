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

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use CarpetAvenue\Productify\Helper\Data;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;

class Upload extends Action
{
    public function __construct(
        Context                   $context,
        protected JsonFactory     $jsonFactory,
        protected UploaderFactory $uploaderFactory,
        protected Filesystem      $filesystem,
        protected CollectionFactory $productCollectionFactory,
        protected ResourceConnection $resourceConnection,
        protected RequestInterface $request,
        protected StoreRepositoryInterface $storeRepository,
        protected ProductRepositoryInterface $productRepository,
        protected UrlPersistInterface $urlPersist,
        protected ProductUrlPathGenerator $productUrlPathGenerator,
        protected ProductUrlRewriteGenerator $productUrlRewriteGenerator
    ) {
        parent::__construct($context);
    }

    public function execute(): Json|ResultInterface|ResponseInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $uploader = $this->uploaderFactory->create(['fileId' => 'csv_file']);
            $uploader->setAllowedExtensions(['csv']);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);

            $varDir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $uploadPath = 'carpetavenue/rewrite_url_csv';

            if (!$varDir->isDirectory($uploadPath)) {
                $varDir->create($uploadPath);
            }

            $fileName = 'rewrite_issue_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';

            $uploadResult = $uploader->save($varDir->getAbsolutePath($uploadPath), $fileName);

            if ($uploadResult) {
                $filePath = $varDir->getAbsolutePath($uploadPath . '/' . $fileName);
                $processResult = $this->processCsvFile($filePath);

                $result->setData([
                    'success' => $processResult['success'],
                    'message' => $processResult['message']
                ]);
            } else {
                $result->setData([
                    'success' => false,
                    'message' => 'File upload failed.'
                ]);
            }

        } catch (Exception $e) {
            $result->setData([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }

        return $result;
    }

    private function processCsvFile($filePath): array
    {
        try {
            $message = '';
            $handle = fopen($filePath, 'r');
            $totalCount = 0;
            $processedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            $productSkus = [];
            $maxProcess = Data::MAX_UNIQUE_CAN_PROCESS_SINGLE_TIME;

            if ($handle !== false) {

                $headers = fgetcsv($handle, 10000, ',', '"', '\\');

                // Validate required headers
                $rowNumber = 1;
                $requiredHeaders = [Data::REWRITE_URL_ISSUE_CSV_COLUMN_NAME];
                $missingHeaders = array_diff($requiredHeaders, $headers);

                if (!empty($missingHeaders)) {
                    fclose($handle);
                    return [
                        'success' => false,
                        'message' => "Missing required columns: " . implode(', ', $missingHeaders),
                    ];
                }

                $stores = $this->storeRepository->getList();
                $itemSkuColumnIndex = array_search(Data::REWRITE_URL_ISSUE_CSV_COLUMN_NAME, $headers);
                $connection = $this->resourceConnection->getConnection();

                // Now read all the rows
                while (($data = fgetcsv($handle, 10000, ',', '"', '\\')) !== false) {
                    $rowNumber++;

                    // Skip completely empty rows
                    if (empty(array_filter($data))) {
                        continue;
                    }

                    $productSku = $data[$itemSkuColumnIndex] ?? null;
                    if ($productSku) {
                        $totalCount++;
                        $productSku = trim($productSku);
                        if (!in_array($productSku, $productSkus)) {
                            // @TODO - Sanitize Text Before Query
                            $productSkus[] = $productSku;
                            $productId = $connection->quoteInto(
                                "SELECT entity_id FROM " . $this->resourceConnection->getTableName('catalog_product_entity') . " WHERE sku = ?",
                                $productSku
                            );
                            $fetchedProductId = $connection->fetchOne($productId);
                            if ($fetchedProductId) {
                                try {
                                    foreach ($stores as $store) {
                                        $product = $this->productRepository->get($productSku, true, $store->getId());
                                        $product->unsUrlPath();
                                        $product->setUrlPath($this->productUrlPathGenerator->getUrlPath($product));
                                        $this->urlPersist->replace($this->productUrlRewriteGenerator->generate($product));
                                    }
                                    $message .= "<p>Row $rowNumber: SKU '$productSku' Process Successfully.</p>";
                                    $processedCount++;
                                } catch (Exception $exception) {
                                    $errorCount++;
                                    $message .= "<p style='color: red;'>Row $rowNumber: SKU '$productSku' Error : </p>".$exception->getMessage();
                                    continue;
                                }
                            } else {
                                $skippedCount++;
                                $message .= "<p style='color: orange;'>Row $rowNumber: SKU '$productSku' not found. Skipping.</p>";
                            }
                        }
                    } else {
                        $skippedCount++;
                        $message .= "<p style='color: orange;'>Row $rowNumber: SKU is Empty. Skipping.</p>";
                    }
                }

                $message .= "<hr>";
                $message .= "<p>Total Row : <b>$totalCount</b></p>";
                $message .= "<p>Unique SKU : <b>".$processedCount+$skippedCount+$errorCount."</b></p>";
                $message .= "<p>Successfully processed : <b>$processedCount</b></p>";
                $message .= "<p style='color: orange;'>Skipped : <b>$skippedCount</b></p>";
                $message .= "<p style='color: red;'>Error : <b>$errorCount</b></p>";

                return [
                    'success' => true,
                    'message' => $message
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error processing CSV: ' . $e->getMessage()
            ];
        }

        return [
            'success' => false,
            'message' => "Error processing CSV"
        ];
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Productify::productify_rewriteurl');
    }
}
