<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ImageDriver implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'auto',    'label' => __('Auto (prefer Imagick, fallback GD)')],
            ['value' => 'imagick', 'label' => __('Imagick')],
            ['value' => 'gd',      'label' => __('GD')],
        ];
    }
}
