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

namespace CarpetAvenue\Productify\Model\Api;

use CarpetAvenue\Productify\Api\LowestPriceInterface;
use CarpetAvenue\Productify\Model\ResourceModel\Lowest as LowestResource;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

class LowestPrice implements LowestPriceInterface
{
    public function __construct(
        private StoreManagerInterface $storeManager,
        private ProductRepositoryInterface $productRepository,
        private LowestResource $lowestResource
    ) {}

    public function getBySku(string $sku): array
    {
        $storeId   = (int)$this->storeManager->getStore()->getId();
        $productId = (int)$this->productRepository->get($sku, false, $storeId)->getId();

        $row = $this->lowestResource->fetch($productId, $storeId);

        if (!$row || $row['lowest_30d_price'] === null) {
            // still return window so UI can decide
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return [
                'price'        => null,
                'formatted'    => null,
                'since'        => null,
                'window_start' => $now->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:s'),
                'window_end'   => $now->format('Y-m-d H:i:s'),
            ];
        }

        return [
            'price'        => (float)$row['lowest_30d_price'],
            'formatted'    => (string)$row['formatted_text'],
            'since'        => $row['lowest_30d_since'],
            'window_start' => $row['window_start'],
            'window_end'   => $row['window_end'],
        ];
    }
}
