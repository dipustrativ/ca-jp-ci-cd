<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model\ResourceModel\AttributeOverrideValue\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid collection for carpetavenue_attribute_override_value.
 * Joins catalog_product_entity to expose SKU column.
 */
class Collection extends SearchResult
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        string $mainTable = 'carpetavenue_attribute_override_value',
        string $resourceModel = \CarpetAvenue\Utilities\Model\ResourceModel\AttributeOverrideValue::class,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null,
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $connection,
            $resource
        );
    }

    protected function _initSelect(): static
    {
        parent::_initSelect();

        $this->getSelect()->joinLeft(
            ['cpe' => $this->getTable('catalog_product_entity')],
            'main_table.entity_id = cpe.entity_id',
            ['sku']
        );

        return $this;
    }
}
