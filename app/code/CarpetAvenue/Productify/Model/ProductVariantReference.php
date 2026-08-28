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

use Magento\Framework\Model\AbstractModel;

class ProductVariantReference extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\ProductVariantReference::class);
    }
}