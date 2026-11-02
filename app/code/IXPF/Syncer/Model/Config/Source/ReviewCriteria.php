<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What turns an entry into a review. An entry with none of the selected parts is a rating.
 */
class ReviewCriteria implements OptionSourceInterface
{
    public const TEXT = 'text';
    public const PHOTO = 'photo';
    public const SUB_RATINGS = 'sub_ratings';

    public const VALUES = [self::TEXT, self::PHOTO, self::SUB_RATINGS];

    public function toOptionArray(): array
    {
        return [
            ['value' => self::TEXT, 'label' => __('Written text')],
            ['value' => self::PHOTO, 'label' => __('Photo')],
            ['value' => self::SUB_RATINGS, 'label' => __('Quality, Delivery or Customer service rating')],
        ];
    }
}
