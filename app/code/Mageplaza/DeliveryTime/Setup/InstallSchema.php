<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_DeliveryTime
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\DeliveryTime\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Class InstallSchema
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
        $connection = $installer->getConnection();

        // NOTE: The original code renamed Mageplaza_Osc's `osc_delivery_time` column into
        // `mp_delivery_information` when the latter was missing. Osc and DeliveryTime are two
        // independent features that happen to touch delivery time (Osc writes `osc_delivery_time`
        // in its own observer), so that rename steals/destroys Osc's live column. On a fresh
        // co-install it left `osc_delivery_time` non-existent, breaking Osc at runtime and making
        // the sales_order data-exporter mview trigger reference a phantom column. DeliveryTime must
        // only ensure its own column exists and leave Osc's alone.
        $salesOrder = $installer->getTable('sales_order');
        if (!$connection->tableColumnExists($salesOrder, 'mp_delivery_information')) {
            $connection->addColumn(
                $salesOrder,
                'mp_delivery_information',
                ['type' => Table::TYPE_TEXT, 'visible' => false, 'comment' => 'Mageplaza Delivery Time']
            );
        }

        $installer->endSetup();
    }
}
