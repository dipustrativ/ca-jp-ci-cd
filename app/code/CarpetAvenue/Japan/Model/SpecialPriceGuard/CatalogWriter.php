<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Puts prices into the catalog. The only class here that writes to Magento.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Psr\Log\LoggerInterface;

class CatalogWriter
{
    public function __construct(
        private readonly ProductAction $productAction,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Write special_price for every product, one statement per distinct price
     * rather than one per product.
     *
     * @param array<int, float|null> $values productId => price to write; null clears it
     * @return array<int, string> Error message per product id that failed
     */
    public function write(array $values, int $storeId): array
    {
        // Group the products by the price they are getting. The key is a
        // formatted string, not the float: PHP truncates float array keys to
        // int, so 304.79 and 304.78 would both land on 304 and one group would
        // silently be given the other's price. Six decimals always contains a
        // ".", so the key can never be read back as an integer either.
        // "null" is its own bucket — those products get their special price
        // removed rather than set.
        $groups = [];
        foreach ($values as $productId => $price) {
            $groups[$price === null ? 'null' : number_format($price, 6, '.', '')][] = $productId;
        }

        $failed = [];
        foreach ($groups as $key => $productIds) {
            $price = $key === 'null' ? null : (float)$key;

            try {
                $this->productAction->updateAttributes($productIds, ['special_price' => $price], $storeId);
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CarpetAvenue_Japan] special price write failed for '
                    . count($productIds) . ' products: ' . $e->getMessage()
                );
                foreach ($productIds as $productId) {
                    $failed[$productId] = $e->getMessage();
                }
            }
        }

        return $failed;
    }
}
