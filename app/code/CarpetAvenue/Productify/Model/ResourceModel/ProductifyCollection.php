<?php

namespace CarpetAvenue\Productify\Model\ResourceModel;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductifyCollection extends AbstractDb
{
    /**
     * Rows per multi-row statement. ~30 columns a row keeps it far below MySQL's
     * 65535-placeholder limit.
     */
    private const BATCH_SIZE = 500;

    protected function _construct(): void
    {
        $this->_init('carpetavenue_productify_collection', 'entity_id');
    }

    /**
     * Update the row matching $conditions (default: id + store_id), or insert it
     *
     * @param array $data
     * @param array $conditions
     * @return bool False when the row could not be written
     */
    public function updateOrCreate(array $data, array $conditions = []): bool
    {
        try {
            $connection = $this->getConnection();
            $table = $this->getMainTable();

            if (count($conditions) == 0) {
                $conditions['id'] = $data['id'];
                $conditions['store_id'] = $data['store_id'];
            }
            $select = $connection->select()
                ->from($table, ['entity_id'])
                ->where($this->buildWhere($conditions));

            $id = $connection->fetchOne($select);

            if ($id) {
                $connection->update($table, $data, ['entity_id = ?' => $id]);
            } else {
                $connection->insert($table, array_merge($data, $conditions));
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Batched updateOrCreate() for many rows keyed by (id, store_id)
     *
     * The table has no unique key on (id, store_id), so each chunk first looks up the
     * entity_id of the rows that already exist, then writes them with ON DUPLICATE KEY
     * on the primary key and inserts the rest. That is 3 statements per chunk instead
     * of 2 per row. A chunk that fails is retried row by row, so one bad row cannot
     * drop the others.
     *
     * @param array[] $rows Rows as built by CollectionBuilder::buildForStore(), all with the same keys
     * @param callable|null $onProgress Called as ($done, $total) after each chunk
     * @return int Rows that could not be written
     */
    public function saveMany(array $rows, ?callable $onProgress = null): int
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $total = count($rows);
        $done = 0;
        $failed = 0;

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            try {
                $entityIds = $this->findEntityIds($chunk);
                $updates = [];
                $inserts = [];
                foreach ($chunk as $row) {
                    $entityId = $entityIds[$row['id'] . ':' . $row['store_id']] ?? null;
                    if ($entityId) {
                        $updates[] = ['entity_id' => $entityId] + $row;
                    } else {
                        $inserts[] = $row;
                    }
                }
                if ($updates) {
                    $connection->insertOnDuplicate($table, $updates, array_keys(reset($chunk)));
                }
                if ($inserts) {
                    $connection->insertMultiple($table, $inserts);
                }
            } catch (\Exception $e) {
                foreach ($chunk as $row) {
                    if (!$this->updateOrCreate($row)) {
                        $failed++;
                    }
                }
            }
            $done += count($chunk);
            if ($onProgress) {
                $onProgress($done, $total);
            }
        }
        return $failed;
    }

    /**
     * Existing entity_id per "id:store_id"
     *
     * MIN() picks the row updateOrCreate() would have updated when (id, store_id) is
     * duplicated, since its lookup walks the id index in entity_id order.
     *
     * @param array[] $rows
     * @return array<string, int>
     */
    private function findEntityIds(array $rows): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from(
                $this->getMainTable(),
                ['id', 'store_id', 'entity_id' => new Expression('MIN(entity_id)')]
            )
            ->where('id IN (?)', array_column($rows, 'id'))
            ->where('store_id IN (?)', array_unique(array_column($rows, 'store_id')))
            ->group(['id', 'store_id']);

        $entityIds = [];
        foreach ($connection->fetchAll($select) as $row) {
            $entityIds[$row['id'] . ':' . $row['store_id']] = (int)$row['entity_id'];
        }
        return $entityIds;
    }

    private function buildWhere(array $conditions): string
    {
        $wheres = [];
        foreach ($conditions as $field => $value) {
            $wheres[] = $this->getConnection()->quoteInto("$field = ?", $value);
        }
        return implode(' AND ', $wheres);
    }
}
