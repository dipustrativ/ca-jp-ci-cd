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

use CarpetAvenue\FeedExport\Model\Log;
use Magento\Framework\Data\OptionSourceInterface;

class LogStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Log::STATUS_RUNNING, 'label' => __('Running')],
            ['value' => Log::STATUS_SUCCESS, 'label' => __('Success')],
            ['value' => Log::STATUS_FAILED, 'label' => __('Failed')],
        ];
    }
}
