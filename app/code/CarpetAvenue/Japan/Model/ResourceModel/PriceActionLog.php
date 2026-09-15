<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Append-only audit shared by every Japan price rule. Backs the admin grid.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PriceActionLog extends AbstractDb
{
    public const TABLE = 'carpetavenue_japan_price_action_log';
    public const ID_FIELD = 'log_id';

    /** Rows per INSERT. Keeps the packet size sane on a first run. */
    private const INSERT_CHUNK = 500;

    protected function _construct(): void
    {
        $this->_init(self::TABLE, self::ID_FIELD);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function addMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $written = 0;
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $written += (int)$this->getConnection()->insertMultiple($this->getMainTable(), $chunk);
        }

        return $written;
    }

    /**
     * Drop log rows older than the retention window. 0 days keeps everything.
     */
    public function prune(int $days, ?string $ruleCode = null): int
    {
        if ($days <= 0) {
            return 0;
        }

        $where = [
            'created_at < ?' => new \Zend_Db_Expr(sprintf('DATE_SUB(NOW(), INTERVAL %d DAY)', $days)),
        ];
        if ($ruleCode !== null) {
            $where['rule_code = ?'] = $ruleCode;
        }

        return (int)$this->getConnection()->delete($this->getMainTable(), $where);
    }
}
