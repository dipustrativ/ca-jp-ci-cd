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

namespace CarpetAvenue\Productify\Model;

use Magento\Framework\Serialize\Serializer\Json;

class ProductVariantReferenceRepository
{
    protected ProductVariantReferenceFactory $factory;
    protected ResourceModel\ProductVariantReference $resource;
    protected Json $serializer;

    public function __construct(
        ProductVariantReferenceFactory $factory,
        ResourceModel\ProductVariantReference $resource,
        Json $serializer
    ) {
        $this->factory = $factory;
        $this->resource = $resource;
        $this->serializer = $serializer;
    }

    public function saveReference(int $productId, int $storeId, array $referenceData): void
    {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getMainTable();

        $jsonData = $this->serializer->serialize($referenceData);

        $connection->insertOnDuplicate(
            $tableName,
            [
                'product_id' => $productId,
                'store_id' => $storeId,
                'reference_data' => $jsonData,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            ['reference_data', 'updated_at']
        );
    }

    public function getReference(int $productId, int $storeId): ?array
    {
        $connection = $this->resource->getConnection();
        $tableName = $this->resource->getMainTable();

        $select = $connection->select()
            ->from($tableName, ['reference_data'])
            ->where('product_id = ?', $productId)
            ->where('store_id = ?', $storeId)
            ->limit(1);

        $result = $connection->fetchOne($select);

        return $result ? $this->serializer->unserialize($result) : null;
    }
}