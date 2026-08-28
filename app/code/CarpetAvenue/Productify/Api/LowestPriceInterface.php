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

namespace CarpetAvenue\Productify\Api;

interface LowestPriceInterface
{
    /**
     * Return preformatted lowest-price label + raw values for a SKU in current store.
     * @param string $sku
     * @return array{price: float|null, formatted: string|null, since: string|null, window_start: string, window_end: string}
     */
    public function getBySku(string $sku): array;
}
