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

namespace CarpetAvenue\FeedExport\Model\Source;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Data\OptionSourceInterface;

class ProductStatusFilter implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => __('Any')],
            ['value' => (string)Status::STATUS_ENABLED, 'label' => __('Enabled only (+ approved/online/weight rules)')],
            ['value' => (string)Status::STATUS_DISABLED, 'label' => __('Disabled only')],
        ];
    }
}
