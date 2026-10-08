<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export;

use CarpetAvenue\FeedExport\Model\Export\DataProvider\DataProviderInterface;
use Magento\Catalog\Model\Product;

/**
 * Runs every registered data provider for a product and merges the result into one node.
 */
class ProductDataBuilder
{
    /**
     * @param DataProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Product $product, ExportContext $context): array
    {
        $row = [
            'entity_id' => (int)$product->getId(),
            'created_at' => (string)$product->getCreatedAt(),
        ];
        foreach ($this->providers as $provider) {
            $row = array_replace($row, $provider->provide($product, $context));
        }
        return $row;
    }
}
