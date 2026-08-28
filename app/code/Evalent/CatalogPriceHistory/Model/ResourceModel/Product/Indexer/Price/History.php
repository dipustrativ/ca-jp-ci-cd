<?php
declare(strict_types=1);

namespace Evalent\CatalogPriceHistory\Model\ResourceModel\Product\Indexer\Price;

class History extends \Magento\Indexer\Model\ResourceModel\AbstractResource implements HistoryInterface
{
    const TABLE_NAME = 'catalog_product_index_price_history';

    /**
     * Define main price index table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(self::TABLE_NAME, 'entity_id');
    }

    protected function getAllEntityIds(){
        $connection = $this->getConnection();
        $table = $connection->getTableName('catalog_product_entity');
        $select = $connection->select()->from($table)->columns(['entity_id' => 'entity_id']);
        return array_column($connection->fetchAll($select), 'entity_id');
    }
    /**
     * @return $this|History
     * @throws \Throwable
     */
    public function reindexAll(): HistoryInterface
    {
        //$this->beginTransaction();
        try {
            $allIds = $this->getAllEntityIds();
            foreach (array_chunk($allIds,500) as $ids) {
                $this->moveDataToHistoryTable($ids);
            }
        } catch (\Throwable $exception) {
            //$this->rollBack();
            throw $exception;
        }
        //$this->getConnection()->commit();
        return $this;
    }

    /**
     * @param $entityIds
     * @return $this
     * @throws \Throwable
     */
    public function reindexEntity($entityIds): HistoryInterface
    {
        //$this->beginTransaction();
        try {
            $this->moveDataToHistoryTable($entityIds);
        } catch (\Throwable $exception) {
            //$this->rollBack();
            throw $exception;
        }
        //$this->getConnection()->commit();
        return $this;
    }

    /**
     * @param $entityIds
     * @return $this
     */
    protected function moveDataToHistoryTable($entityIds = null): History
    {
        $columns = [
            'entity_id' => 'entity_id',
            'customer_group_id' => 'customer_group_id',
            'website_id' => 'website_id',
            'tax_class_id' => 'tax_class_id',
            'price' => 'price',
            'final_price' => 'final_price',
            'min_price' => 'min_price',
            'max_price' => 'max_price',
            'tier_price' => 'tier_price',
        ];

        $connection = $this->getConnection();
        $table = $connection->getTableName('catalog_product_index_price');
        $idxTableName = $connection->getTableName(self::TABLE_NAME);


        $selectMaxCpiph = $connection->select()->from(
            $idxTableName,
            [
                'max_row_id' => 'MAX(row_id)'
            ]
        )->where(
            'entity_id in (?)', count($entityIds) > 0 ? $entityIds : 0, \Zend_Db::INT_TYPE
        )->group([
            'entity_id',
            'customer_group_id',
            'website_id',
            'tax_class_id'
        ]);

        $selectMaxRowId = $connection->select()->from(
            $selectMaxCpiph,
            ['max_row_id']
        );

        $select = $connection->select()->from(
            ['cpip' => $table],
            $columns
        )->joinLeft(
            ['cpiph' => $idxTableName],
            'cpip.entity_id = cpiph.entity_id AND
            cpip.website_id = cpiph.website_id AND
            cpip.customer_group_id = cpiph.customer_group_id AND
            cpip.tax_class_id = cpiph.tax_class_id AND
            cpip.price <=> cpiph.price AND
            cpip.final_price <=> cpiph.final_price AND
            cpip.max_price <=> cpiph.max_price AND
            cpip.min_price <=> cpiph.min_price AND
            cpip.tier_price <=> cpiph.tier_price AND
            cpiph.row_id IN (' . $selectMaxCpiph->__toString() . ')',
            ''
        )->where(
            'cpiph.entity_id is null'
        );

        if ($entityIds !== null) {
            $select->where('cpip.entity_id in (?)', count($entityIds) > 0 ? $entityIds : 0, \Zend_Db::INT_TYPE);
        }

        $query = $select->insertFromSelect($idxTableName, $columns, false);
        $connection->query($query);

        return $this;
    }
}
