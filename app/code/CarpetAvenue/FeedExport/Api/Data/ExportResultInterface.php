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

namespace CarpetAvenue\FeedExport\Api\Data;

/**
 * Outcome of one profile export run.
 *
 * @api
 */
interface ExportResultInterface
{
    /**
     * @return int
     */
    public function getProfileId(): int;

    /**
     * @return int
     */
    public function getRecordCount(): int;

    /**
     * Absolute paths of written files.
     *
     * @return string[]
     */
    public function getFiles(): array;

    /**
     * @return float Seconds
     */
    public function getDuration(): float;
}
