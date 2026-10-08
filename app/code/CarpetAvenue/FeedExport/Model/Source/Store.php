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

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\System\Store as SystemStore;

class Store implements OptionSourceInterface
{
    public function __construct(
        private readonly SystemStore $systemStore
    ) {
    }

    public function toOptionArray(): array
    {
        return array_merge(
            [['value' => 0, 'label' => __('Default Values (Admin)')]],
            $this->systemStore->getStoreValuesForForm(false, false)
        );
    }
}
