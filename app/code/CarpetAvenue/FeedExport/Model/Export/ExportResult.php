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

namespace CarpetAvenue\FeedExport\Model\Export;

use CarpetAvenue\FeedExport\Api\Data\ExportResultInterface;

class ExportResult implements ExportResultInterface
{
    /**
     * @param string[] $files
     */
    public function __construct(
        private readonly int $profileId,
        private readonly int $recordCount,
        private readonly array $files,
        private readonly float $duration
    ) {
    }

    public function getProfileId(): int
    {
        return $this->profileId;
    }

    public function getRecordCount(): int
    {
        return $this->recordCount;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }
}
