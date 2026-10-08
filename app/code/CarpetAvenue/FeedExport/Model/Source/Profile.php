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

use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class Profile implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->collectionFactory->create()->addFieldToSelect(['profile_id', 'name']) as $profile) {
            $options[] = [
                'value' => (int)$profile->getId(),
                'label' => sprintf('#%d %s', $profile->getId(), $profile->getName()),
            ];
        }
        return $options;
    }
}
