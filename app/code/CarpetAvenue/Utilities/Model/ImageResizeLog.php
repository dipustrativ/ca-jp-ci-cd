<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_Utilities
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model;

use Magento\Framework\Model\AbstractModel;
use CarpetAvenue\Utilities\Model\ResourceModel\ImageResizeLog as ImageResizeLogResource;

/**
 * Image Resize Log Model
 *
 * Status constants:
 *   STATUS_PENDING   – queued but not yet processed
 *   STATUS_CONVERTED – successfully resized (restore available)
 *   STATUS_SKIPPED   – criteria not met (see skip_reason)
 *   STATUS_ERROR     – resize attempt failed (see skip_reason)
 *   STATUS_RESTORED  – original restored from bucket backup
 */
class ImageResizeLog extends AbstractModel
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_ERROR     = 'error';
    public const STATUS_RESTORED  = 'restored';

    /** Only CONVERTED rows may be restored. */
    public const RESTORABLE_STATUSES = [self::STATUS_CONVERTED];

    protected function _construct(): void
    {
        $this->_init(ImageResizeLogResource::class);
    }
}

