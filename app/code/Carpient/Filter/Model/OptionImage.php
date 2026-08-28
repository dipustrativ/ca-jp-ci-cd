<?php

declare(strict_types=1);

namespace Carpient\Filter\Model;

use Magento\Framework\Model\AbstractModel;
use Carpient\Filter\Model\ResourceModel\OptionImage as OptionImageResource;

/**
 * Attribute option image model — one image per eav attribute option.
 */
class OptionImage extends AbstractModel
{
    /** Tag the ViewModel\OptionImages map is cached under. */
    public const CACHE_TAG = 'carpient_filter_option_image';

    /**
     * @var string
     */
    protected $_cacheTag = self::CACHE_TAG;

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(OptionImageResource::class);
    }
}
