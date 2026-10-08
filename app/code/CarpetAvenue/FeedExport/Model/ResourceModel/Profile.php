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

namespace CarpetAvenue\FeedExport\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Profile extends AbstractDb
{
    public const TABLE = 'carpetavenue_feedexport_profile';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'profile_id');
    }

    /**
     * Stamp last run without touching the rest of the row (no full model save during cron).
     */
    public function updateLastExecution(int $profileId, string $dateTime): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            ['last_execution' => $dateTime],
            ['profile_id = ?' => $profileId]
        );
    }
}
