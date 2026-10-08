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

use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use Magento\Framework\Data\OptionSourceInterface;

class Trigger implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => FeedExporterInterface::TRIGGER_MANUAL, 'label' => __('Manual (admin)')],
            ['value' => FeedExporterInterface::TRIGGER_CRON, 'label' => __('Cron')],
            ['value' => FeedExporterInterface::TRIGGER_CLI, 'label' => __('CLI')],
        ];
    }
}
