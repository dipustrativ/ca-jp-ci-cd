<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\SalesGrid;

use Magento\Framework\App\ResourceConnection;

/**
 * Read-only lookup of order-currency amounts for invoice / credit memo grid rows.
 *
 * The sales grid tables only carry some amounts in the base currency (EUR), and
 * the credit memo grid has no currency code at all. Rather than adding grid
 * columns, the money columns fetch the order-currency values from the source
 * document for the rows on the current page. One query per table per request.
 */
class OrderCurrencyAmounts
{
    private const TABLES = ['sales_invoice', 'sales_creditmemo'];

    /** @var array<string,array<int,array>> table => entity_id => row */
    private array $rows = [];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Source document rows keyed by entity_id; includes order_grand_total from sales_order.
     *
     * @param string $table sales_invoice | sales_creditmemo
     * @param int[] $entityIds
     * @return array<int,array>
     */
    public function get(string $table, array $entityIds): array
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported sales document table "%s".', $table));
        }

        $this->rows[$table] ??= [];
        $missing = array_diff(array_map('intval', $entityIds), array_keys($this->rows[$table]));

        if ($missing) {
            $connection = $this->resource->getConnection('sales');
            $select = $connection->select()
                ->from(['e' => $this->resource->getTableName($table, 'sales')])
                ->joinLeft(
                    ['o' => $this->resource->getTableName('sales_order', 'sales')],
                    'o.entity_id = e.order_id',
                    ['order_grand_total' => 'o.grand_total']
                )
                ->where('e.entity_id IN (?)', $missing);

            foreach ($connection->fetchAll($select) as $row) {
                $this->rows[$table][(int)$row['entity_id']] = $row;
            }
        }

        return array_intersect_key($this->rows[$table], array_flip(array_map('intval', $entityIds)));
    }
}
