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

namespace CarpetAvenue\Productify\Controller\Adminhtml\GroupProducts;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use CarpetAvenue\Productify\Helper\Data;

class Upload extends Action
{
    protected bool $overwriteDesignId = false;
    protected bool $overwriteDesignIdGroup = false;
    protected bool $overwriteSize = false;
    protected bool $overwriteColor = false;
    protected int $designIdGroupAttributeId;

    public function __construct(
        Context                   $context,
        protected JsonFactory     $jsonFactory,
        protected UploaderFactory $uploaderFactory,
        protected Filesystem      $filesystem,
        protected EavSetupFactory $eavSetupFactory,
        private readonly Config   $eavConfig,
        protected ModuleDataSetupInterface $setup,
        protected CollectionFactory $productCollectionFactory,
        protected ResourceConnection $resourceConnection,
        protected RequestInterface $request,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json|ResultInterface|ResponseInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $this->overwriteDesignId = (bool) $this->request->getParam('overwrite_design_id');
            $this->overwriteDesignIdGroup = (bool) $this->request->getParam('overwrite_design_id_group');
            $this->overwriteSize = (bool) $this->request->getParam('overwrite_size');
            $this->overwriteColor = (bool) $this->request->getParam('overwrite_color');

            $uploader = $this->uploaderFactory->create(['fileId' => 'csv_file']);
            $uploader->setAllowedExtensions(['csv']);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);

            $varDir = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $uploadPath = 'carpetavenue/group_products_csv';

            if (!$varDir->isDirectory($uploadPath)) {
                $varDir->create($uploadPath);
            }

            $fileName = 'group_products_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.csv';

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
            $handle = fopen($filePath, 'r');
            $processedCount = 0;

            $products = [];
            $productSizes = [];
            $productColors = [];
            $allSkus = [];

            if ($handle !== false) {

                $headers = fgetcsv($handle, 10000, ',', '"', '\\');

                // Validate required headers
                $requiredHeaders = [Data::CSV_DESIGN_ID_GROUP, Data::CSV_DESIGN_ID_COLOR, Data::CSV_DESIGN_ID_SIZE, 'sku'];
                $missingHeaders = array_diff($requiredHeaders, $headers);

                if (!empty($missingHeaders)) {
                    fclose($handle);
                    return [
                        'success' => false,
                        'message' => "Missing required columns: " . implode(', ', $missingHeaders),
                    ];
                }

                // Get column indices for efficient access
                $designIdIndex = array_search(Data::CSV_DESIGN_ID_GROUP, $headers);
                $colorIndex = array_search(Data::CSV_DESIGN_ID_COLOR, $headers);
                $sizeIndex = array_search(Data::CSV_DESIGN_ID_SIZE, $headers);
                $skuIndex = array_search('sku', $headers);

                // Validate all data rows for empty required fields
                $rowNumber = 1;
                $validationErrors = [];

                while (($data = fgetcsv($handle, 10000, ',', '"', '\\')) !== false) {
                    $rowNumber++;

                    // Skip completely empty rows
                    if (empty(array_filter($data))) {
                        continue;
                    }

                    // Check required fields are not empty
                    if (empty(trim($data[$designIdIndex] ?? ''))) {
                        $validationErrors[] = "Row $rowNumber: " . Data::CSV_DESIGN_ID_GROUP . " is empty";
                    }
                    if (empty(trim($data[$colorIndex] ?? ''))) {
                        $validationErrors[] = "Row $rowNumber: " . Data::CSV_DESIGN_ID_COLOR . " is empty";
                    }
                    if (empty(trim($data[$sizeIndex] ?? ''))) {
                        $validationErrors[] = "Row $rowNumber: " . Data::CSV_DESIGN_ID_SIZE . " is empty";
                    }
                    if (empty(trim($data[$skuIndex] ?? ''))) {
                        $validationErrors[] = "Row $rowNumber: SKU is empty";
                    }
                }

                // If validation errors found, return them
                if (!empty($validationErrors)) {
                    fclose($handle);
                    return [
                        'success' => false,
                        'message' => "Validation failed: " . implode("\n", $validationErrors)
                    ];
                }

                // Reset file pointer to beginning and skip header
                rewind($handle);
                $headers = fgetcsv($handle, 10000, ',', '"', '\\'); // Skip header row

                while (($data = fgetcsv($handle, 10000, ',', '"', '\\')) !== false) {

                    // Skip completely empty rows
                    if (empty(array_filter($data))) {
                        continue;
                    }

                    $row = array_combine($headers, $data);
                    $products[$row[Data::CSV_DESIGN_ID_GROUP]][] = $row;
                    $productColors[] = $row[Data::CSV_DESIGN_ID_COLOR];
                    $productSizes[] = $row[Data::CSV_DESIGN_ID_SIZE];
                    $allSkus[] = $row['sku'];

                    $processedCount++;
                }

                fclose($handle);

                $message = "";

                $productSizes  = array_unique($productSizes);
                $productColors = array_unique($productColors);

                // Process attribute options (same as before)
                $message .= $this->processAttributeOptions($productSizes, $productColors);

                // Get updated attribute options
                $sizeGroups = $this->getAttributeOptions(Data::SIZE_ATTRIBUTE_CODE);
                $colorGroups = $this->getAttributeOptions(Data::COLOR_ATTRIBUTE_CODE);

                $this->designIdGroupAttributeId = $this->getDesignIdGroupAttributeId();

                $isGroupAttributeCode = $this->getIsGroupAttributeId();
                if (!$isGroupAttributeCode) {
                    return [
                        'success' => false,
                        'message' => "No Attribute found for is_group"
                    ];
                }

                $productCollection = $this->getProductCollectionBySku($allSkus);
                $productData = $this->extractProductData($productCollection);

                // Validate Data
                $validationStatus = true;
                $message .= "<p>Total SKUs in CSV: <b>" . count($allSkus) . "</b></p>";
                $message .= "<p>Total matching products found in System : <b>" . count($productData) . "</b></p>";

                $designIdMissing = [];
                $sizeMissing = [];
                foreach ($productData as $product) {
                    if (empty($product[Data::DESIGN_ID_ATTRIBUTE_CODE])) {
                        $designIdMissing[] = $product['sku'];
                    }
                    if (empty($product['size_id'])) {
                        $sizeMissing[] = $product['sku'];
                    }
                }

                if (!empty($designIdMissing) && !$this->overwriteDesignId) {
                    $validationStatus = false;
                    $message .= "<p style='color: #ee6d39'>Products with missing Design ID: <b>" . implode(', ', $designIdMissing) . "</b></p>";
                }
                if (!empty($sizeMissing) && !$this->overwriteSize) {
                    $validationStatus = false;
                    $message .= "<p style='color: #ee6d39'>Products with missing Size: <b>" . implode(', ', $sizeMissing) . "</b></p>";
                }

                if (!$validationStatus) {
                    return [
                        'success' => false,
                        'message' => $message
                    ];
                }

                foreach ($products as $csvProducts) {
                    foreach ($csvProducts as $csvProduct) {
                        $sku = $csvProduct['sku'];

                        if (!isset($productData[$sku])) {
                            $message .= "<p style='color: #ee6d39'>SKU - <b>$sku</b> : Product not found</p>";
                            continue;
                        }

                        try {
                            $product = $productData[$sku];
                            $this->updateProductAttributes(
                                $product,
                                $csvProduct,
                                $sizeGroups,
                                $colorGroups,
                                $isGroupAttributeCode
                            );
                        } catch (Exception $exception) {
                            $message .= "<p style='color: #ee6d39'>SKU - <b>$sku</b> : " . $exception->getMessage()."</p>";
                            continue;
                        }
                    }
                }

                $message .= "<p>Successfully processed <b>$processedCount</b> rows from CSV file.</p>";

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

    private function getProductCollectionBySku(array $skus): Collection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addFieldToFilter('sku', ['in' => $skus]);
        $collection->addAttributeToSelect([
            'entity_id',
            'sku',
            Data::DESIGN_ID_ATTRIBUTE_CODE,
            Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE,
            Data::SIZE_ATTRIBUTE_CODE,
            Data::COLOR_ATTRIBUTE_CODE,
            Data::IS_GROUP_ATTRIBUTE_CODE
        ]);
        return $collection;
    }

    private function extractProductData(Collection $collection): array
    {
        $productData = [];

        foreach ($collection as $product) {
            $productData[$product->getSku()] = [
                'entity_id' => $product->getId(),
                'sku' => $product->getSku(),
                Data::DESIGN_ID_ATTRIBUTE_CODE => trim($product->getData(Data::DESIGN_ID_ATTRIBUTE_CODE) ?? ''),
                Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE => trim($product->getData(Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE) ?? ''),
                'size_id' => $product->getData(Data::SIZE_ATTRIBUTE_CODE),
                'color_id' => $product->getData(Data::COLOR_ATTRIBUTE_CODE),
                'is_group' => $product->getData(Data::IS_GROUP_ATTRIBUTE_CODE)
            ];
        }

        return $productData;
    }

    private function updateProductAttributes(array $product, array $csvProduct, array $sizeGroups, array $colorGroups, int $isGroupAttributeCode): void
    {
        $entityId = $product['entity_id'];

        // Check and update Design ID (Store View level)
        $finalDesignId = $product[Data::CSV_DESIGN_ID];
        $csvDesignId = trim($csvProduct[Data::CSV_DESIGN_ID]);
        if ($this->overwriteDesignId && $product[Data::CSV_DESIGN_ID] !== $csvDesignId) {
            $finalDesignId = $csvDesignId;
            $this->updateOrInsertAttributeValue(
                $entityId,
                Data::DESIGN_ID_ATTRIBUTE_ID,
                $csvDesignId,
                0,
                'catalog_product_entity_varchar'
            );
        }

        // Determine final design ID group value
        $finalDesignIdGroup = $this->overwriteDesignIdGroup
            ? trim($csvProduct[Data::CSV_DESIGN_ID_GROUP])
            : explode('_', $finalDesignId)[0] ?? '';

        // Update Design ID Group if it has changed
        if (!empty($product[Data::CSV_DESIGN_ID_GROUP]) && $product[Data::CSV_DESIGN_ID_GROUP] !== $finalDesignIdGroup) {
            $this->updateOrInsertAttributeValue(
                $entityId,
                $this->designIdGroupAttributeId,
                $finalDesignIdGroup,
                0,
                'catalog_product_entity_varchar'
            );
        } else if(empty($product[Data::CSV_DESIGN_ID_GROUP])) {
            $this->updateOrInsertAttributeValue(
                $entityId,
                $this->designIdGroupAttributeId,
                $finalDesignIdGroup,
                0,
                'catalog_product_entity_varchar'
            );
        }

        // Check and update Size (Global scope)
        $csvSizeLabel = $csvProduct[Data::CSV_DESIGN_ID_SIZE];
        $newSizeId = $sizeGroups[$csvSizeLabel] ?? null;
        if ($this->overwriteSize && $product['size_id'] !== $newSizeId && $newSizeId !== null) {
            $this->updateOrInsertAttributeValue(
                $entityId,
                Data::SIZE_ATTRIBUTE_ID,
                $newSizeId
            );
        }

        // Check and update Color (Global scope)
        $csvColorLabel = $csvProduct[Data::CSV_DESIGN_ID_COLOR];
        $newColorId = $colorGroups[$csvColorLabel] ?? null;
        if ($this->overwriteColor && $product['color_id'] !== $newColorId && $newColorId !== null) {
            $this->updateOrInsertAttributeValue(
                $entityId,
                Data::COLOR_ATTRIBUTE_ID,
                $newColorId
            );
        }

        // Check and update Is Group attribute (Global scope)
        if ($product['is_group'] !== '1') {
            $this->updateOrInsertAttributeValue(
                $entityId,
                $isGroupAttributeCode,
                1
            );
        }
    }

    /**
     * @throws LocalizedException
     */
    private function processAttributeOptions(array $productSizes, array $productColors): string
    {
        $message = "";

        // Process size options
        $sizeAttribute = $this->eavConfig->getAttribute(Product::ENTITY, Data::SIZE_ATTRIBUTE_CODE);
        $existingSizeOptions = $sizeAttribute->getSource()->getAllOptions(false);
        $sizeOptionLabelToIdMap = [];
        foreach ($existingSizeOptions as $option) {
            $sizeOptionLabelToIdMap[strtolower($option['label'])] = $option['value'];
        }

        $sizeNewOptionsData = [];
        $newSize = [];
        $optionCounter = 0;
        foreach ($productSizes as $productSize) {
            $sizeKey = strtolower($productSize);
            if (!isset($sizeOptionLabelToIdMap[$sizeKey]) && !empty($productSize)) {
                $newSize[] = $productSize;
                $sizeNewOptionsData['option_' . $optionCounter] = [0 => $productSize];
                $optionCounter++;
            }
        }

        if (!empty($sizeNewOptionsData)) {
            $message .= count($newSize) . " New size options added : ".implode(', ', $newSize)."<br><br>";
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
            $eavSetup->addAttributeOption([
                'attribute_id' => Data::SIZE_ATTRIBUTE_ID,
                'value'        => $sizeNewOptionsData
            ]);
        }

        // Process color options
        $colorAttribute = $this->eavConfig->getAttribute(Product::ENTITY, Data::COLOR_ATTRIBUTE_CODE);
        $existingColorOptions = $colorAttribute->getSource()->getAllOptions(false);
        $colorOptionLabelToIdMap = [];
        foreach ($existingColorOptions as $option) {
            $colorOptionLabelToIdMap[strtolower($option['label'])] = $option['value'];
        }

        $colorNewOptionsData = [];
        $newColor = [];
        $optionCounter = 0;
        foreach ($productColors as $productColor) {
            $colorKey = strtolower($productColor);
            if (!isset($colorOptionLabelToIdMap[$colorKey]) && !empty($productColor)) {
                $newColor[] = $productColor;
                $colorNewOptionsData['option_' . $optionCounter] = [0 => $productColor];
                $optionCounter++;
            }
        }

        if (!empty($colorNewOptionsData)) {
            $message .= count($newColor) . " New color options added : ".implode(', ', $newColor)."<br><br>";
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
            $eavSetup->addAttributeOption([
                'attribute_id' => Data::COLOR_ATTRIBUTE_ID,
                'value'        => $colorNewOptionsData
            ]);
        }

        return $message;
    }

    /**
     * @throws LocalizedException
     */
    private function getAttributeOptions(string $attributeCode): array
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        $options = [];
        foreach ($attribute->getOptions() as $option) {
            if ($option->getValue()) {
                $options[$option->getLabel()] = $option->getValue();
            }
        }
        return $options;
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Productify::productify_groupproducts');
    }

    private function getIsGroupAttributeId(): int
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['ea' => 'eav_attribute'], ['attribute_id'])
            ->join(
                ['eet' => 'eav_entity_type'],
                'ea.entity_type_id = eet.entity_type_id',
                []
            )
            ->where('ea.attribute_code = ?', Data::IS_GROUP_ATTRIBUTE_CODE)
            ->where('eet.entity_type_code = ?', 'catalog_product');

        return (int) $connection->fetchOne($select);
    }

    private function updateOrInsertAttributeValue($entityId, $attributeId, $value, $storeId = 0, $table = 'catalog_product_entity_int'): void
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($table, ['value_id'])
            ->where('entity_id = ?', $entityId)
            ->where('attribute_id = ?', $attributeId)
            ->where('store_id = ?', $storeId);

        $existingRecord = $connection->fetchOne($select);

        if ($existingRecord) {
            $connection->update(
                $table,
                ['value' => $value],
                [
                    'entity_id = ?' => $entityId,
                    'attribute_id = ?' => $attributeId,
                    'store_id = ?' => $storeId
                ]
            );
        } else {
            $connection->insert($table, [
                'entity_id' => $entityId,
                'attribute_id' => $attributeId,
                'store_id' => $storeId,
                'value' => $value
            ]);
        }
    }

    private function getDesignIdGroupAttributeId(): int
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['ea' => 'eav_attribute'], ['attribute_id'])
            ->join(
                ['eet' => 'eav_entity_type'],
                'ea.entity_type_id = eet.entity_type_id',
                []
            )
            ->where('ea.attribute_code = ?', Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE)
            ->where('eet.entity_type_code = ?', 'catalog_product');

        return (int) $connection->fetchOne($select);
    }
}
