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

namespace CarpetAvenue\FeedExport\Model\Export\DataProvider;

use CarpetAvenue\FeedExport\Model\Export\ExportContext;
use Magento\Catalog\Model\Product;

/**
 * Contributes fields to one exported product node. Register new providers in di.xml
 * (CarpetAvenue\FeedExport\Model\Export\ProductDataBuilder "providers" argument).
 */
interface DataProviderInterface
{
    /**
     * @return array<string, mixed> Field => scalar, or nested arrays for list nodes
     */
    public function provide(Product $product, ExportContext $context): array;
}
