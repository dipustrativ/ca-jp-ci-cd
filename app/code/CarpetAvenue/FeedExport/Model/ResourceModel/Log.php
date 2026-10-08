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

class Log extends AbstractDb
{
    public const TABLE = 'carpetavenue_feedexport_log';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'log_id');
    }

    /**
     * @return int Rows deleted
     */
    public function deleteOlderThan(int $days): int
    {
        return $this->getConnection()->delete(
            $this->getMainTable(),
            ['created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)' => $days]
        );
    }
}
