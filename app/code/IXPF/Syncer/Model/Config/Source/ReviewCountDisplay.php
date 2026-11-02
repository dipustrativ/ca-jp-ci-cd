<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the drawer headline counts a pool. Example: 10 entries, 8 of them with text, a photo or
 * sub-ratings (reviews), 2 with stars only (ratings).
 */
class ReviewCountDisplay implements OptionSourceInterface
{
    /** "Based on 8 reviews and 2 ratings" - every entry counted once. */
    public const SPLIT = 'split';

    /** "Based on 10 ratings and 8 reviews" - every entry is a rating, reviews are a subset. */
    public const OVERLAP = 'overlap';

    /** "Based on 10 reviews" - no split. */
    public const COMBINED = 'combined';

    public const VALUES = [self::SPLIT, self::OVERLAP, self::COMBINED];

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SPLIT, 'label' => __('Split, each counted once: "Based on 8 reviews and 2 ratings"')],
            ['value' => self::OVERLAP, 'label' => __('Split, all are ratings: "Based on 10 ratings and 8 reviews"')],
            ['value' => self::COMBINED, 'label' => __('Combined: "Based on 10 reviews"')],
        ];
    }
}
