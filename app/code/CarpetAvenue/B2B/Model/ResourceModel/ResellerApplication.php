<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ResellerApplication extends AbstractDb
{
    public const TABLE = 'carpetavenue_b2b_reseller_application';
    public const ID_FIELD = 'application_id';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, self::ID_FIELD);
    }

    /**
     * True when an application already exists for this email (case-insensitive per column collation).
     */
    public function existsByEmail(string $email): bool
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), self::ID_FIELD)
            ->where('email = ?', $email)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }
}
