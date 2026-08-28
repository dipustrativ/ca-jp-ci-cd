<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use CarpetAvenue\Core\Helper\UpsertBulk;

class QueryAdapter
{
    public function __construct(
        protected UpsertBulk $upsertBulk
    ) {
    }

    public function addUpdateCollection($data): void
    {
        $this->upsertBulk->upsert($data, Data::PRODUCT_COLLECTION_TABLE);
    }
}
