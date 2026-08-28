<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Core
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Helper;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Statement_Interface;

class UpsertBulk
{
    /**
     * UpsertBulk constructor.
     * @param ResourceConnection $resource
     */
    public function __construct(
        protected ResourceConnection $resource
    ) {
    }

    /**
     * Upsert Bulkly
     *
     * @param array $rows
     * @param $table
     * @return Zend_Db_Statement_Interface
     */
    public function upsert(array $rows, $table): Zend_Db_Statement_Interface
    {
        $first = reset($rows);
        $columns = implode(
            ',',
            array_map(function ($value) {
                return "$value";
            }, array_keys($first))
        );
        $values = implode(',', array_map(function ($row) {
            return '('.implode(
                    ',',
                    array_map(function ($value) {
                        return '"'.str_replace('"', '""', $value).'"';
                    }, $row)
                ).')';
        }, $rows));
        $updates = implode(
            ',',
            array_map(
                function ($value) {
                    return "$value = VALUES($value)";
                },
                array_keys($first)
            )
        );
        $sql = "INSERT INTO $table($columns) VALUES $values ON DUPLICATE KEY UPDATE $updates";
        return $this->resource->getConnection()->query($sql);
    }
}
