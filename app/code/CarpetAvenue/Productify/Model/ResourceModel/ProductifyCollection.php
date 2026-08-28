<?php

namespace CarpetAvenue\Productify\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductifyCollection extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('carpetavenue_productify_collection', 'entity_id');
    }

    public function updateOrCreate(array $data, array $conditions = [])
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
        } catch (\Exception $e) {
        }
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