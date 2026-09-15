<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Reads the catalog rows the guard needs. This is what the base store's
 * `vw_jp_specialprice` database view did, except:
 *
 *   - attribute ids are resolved at runtime, never baked in (the owner
 *     attribute is 210 here and 184 in the base store — a view would be
 *     silently wrong),
 *   - it is versioned in git like the rest of the module,
 *   - it can be filtered and paged, which a fixed view cannot.
 *
 * Performance notes, measured on production-shaped data (1,114 products /
 * 11,617 decimal rows):
 *
 *   - the `special_price IS NOT NULL` predicate makes the optimiser lead with
 *     the 690-row special-price set; every other table joins `eq_ref rows: 1`.
 *     No temp table, no filesort. Whole scan: 4.5 ms.
 *   - paging is keyset (`entity_id > ?`), not OFFSET, so page 100 costs the
 *     same as page 1.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

class Scanner
{
    private const ENTITY_TYPE = 'catalog_product';

    /** @var array<string, int|null> */
    private array $attributeIds = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * One keyset page of products that currently carry a special price.
     *
     * @param string[] $skus Restrict to these SKUs; empty means the whole catalog
     * @return array<int, array<string, mixed>> Rows keyed by entity_id, ascending
     */
    public function fetchBatch(
        int $storeId,
        int $afterEntityId,
        int $limit,
        array $skus = [],
        array $excludedOwners = []
    ): array {
        $select = $this->baseSelect($storeId)
            ->where('sp.value IS NOT NULL')
            ->where('cpe.entity_id > ?', $afterEntityId)
            ->order('cpe.entity_id ' . Select::SQL_ASC)
            ->limit($limit);

        // Drop excluded owners here rather than in PHP: on a catalog where a
        // large owner is excluded this is 40% fewer rows crossing into PHP
        // (676 → 404 on the current data).
        if ($excludedOwners !== []) {
            $select->where('o.value IS NULL OR o.value NOT IN (?)', $excludedOwners);
        }

        if ($skus !== []) {
            $select->where('cpe.sku IN (?)', $skus);
        }

        return $this->index($this->connection()->fetchAll($select));
    }

    /**
     * Attribute codes the scan cannot work without. `owner` is not one of them —
     * a missing owner attribute simply means nobody can be excluded.
     *
     * @return string[]
     */
    public function missingAttributes(): array
    {
        $missing = [];
        foreach (['cost', 'price', 'special_price'] as $code) {
            if ($this->attributeId($code) === null) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /**
     * Total product count — denominator for the blast-radius cap.
     */
    public function countCatalog(): int
    {
        $select = $this->connection()->select()
            ->from($this->table('catalog_product_entity'), ['count' => 'COUNT(*)']);

        return (int)$this->connection()->fetchOne($select);
    }

    /**
     * Shared join skeleton. `cost` and `price` are inner joins — a product
     * without either cannot be priced and is none of this guard's business.
     * `owner` is a left join on purpose: the base store used an inner join and
     * silently lost every product missing an owner row.
     */
    private function baseSelect(int $storeId): Select
    {
        $decimal = $this->table('catalog_product_entity_decimal');

        $select = $this->connection()->select()
            ->from(['cpe' => $this->table('catalog_product_entity')], ['entity_id', 'sku'])
            ->join(['c' => $decimal], $this->on('c', $storeId, 'cost'), ['cost' => 'c.value'])
            ->join(['p' => $decimal], $this->on('p', $storeId, 'price'), ['price' => 'p.value'])
            ->joinLeft(['sp' => $decimal], $this->on('sp', $storeId, 'special_price'), ['special_price' => 'sp.value']);

        // A missing owner attribute just means nobody can be excluded.
        if ($this->attributeId('owner') !== null) {
            $select->joinLeft(
                ['o' => $this->table('catalog_product_entity_varchar')],
                $this->on('o', $storeId, 'owner'),
                ['owner' => 'o.value']
            );
        } else {
            $select->columns(['owner' => new \Zend_Db_Expr('NULL')]);
        }

        return $select;
    }

    /**
     * EAV join condition. Both ids are integers resolved from the EAV config,
     * never request input, so interpolating them is safe and far more legible
     * than threading quoteInto() through every join.
     */
    private function on(string $alias, int $storeId, string $attributeCode): string
    {
        return sprintf(
            '%1$s.entity_id = cpe.entity_id AND %1$s.store_id = %2$d AND %1$s.attribute_id = %3$d',
            $alias,
            $storeId,
            (int)$this->attributeId($attributeCode)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function index(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['entity_id']] = $row;
        }

        return $indexed;
    }

    private function attributeId(string $code): ?int
    {
        if (!array_key_exists($code, $this->attributeIds)) {
            try {
                $id = (int)$this->eavConfig->getAttribute(self::ENTITY_TYPE, $code)->getId();
            } catch (\Exception $e) {
                $id = 0;
            }
            $this->attributeIds[$code] = $id > 0 ? $id : null;
        }

        return $this->attributeIds[$code];
    }

    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}
