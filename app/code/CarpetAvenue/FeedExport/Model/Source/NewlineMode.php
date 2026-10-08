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

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use Magento\Framework\Data\OptionSourceInterface;

class NewlineMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ProfileInterface::NEWLINES_KEEP, 'label' => __('Keep line breaks')],
            ['value' => ProfileInterface::NEWLINES_BR, 'label' => __('Replace with <br />')],
            ['value' => ProfileInterface::NEWLINES_SPACE, 'label' => __('Replace with space')],
            ['value' => ProfileInterface::NEWLINES_REMOVE, 'label' => __('Remove')],
        ];
    }
}
