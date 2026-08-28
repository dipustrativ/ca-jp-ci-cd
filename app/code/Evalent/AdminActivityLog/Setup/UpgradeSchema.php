<?php

declare(strict_types=1);

namespace Evalent\AdminActivityLog\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\UpgradeSchemaInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{

    /**
     * {@inheritdoc}
     * @throws \Zend_Db_Exception
     */
    public function upgrade(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    ) {

        $setup->startSetup();

        // Add date to Login logger
        if (version_compare($context->getVersion(), "1.0.3", "<")) {
            $setup->getConnection()->addColumn(
                $setup->getTable('evalent_adminactivitylog_loginactivity'),
                'event_time',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                    'length' => null,
                    'nullable' => false,
                    'default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT,
                    'comment' => 'Event time'
                ]
            );
        }

        //Add cache log
        if (version_compare($context->getVersion(), "1.0.4", "<")) {
            $table_evalent_adminactivitylog_cacheactivity = $setup->getConnection()->newTable(
                $setup->getTable('evalent_adminactivitylog_cacheactivity')
            );

            $table_evalent_adminactivitylog_cacheactivity->addColumn(
                'cacheactivity_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                null,
                array('identity' => true,'nullable' => false,'primary' => true,'unsigned' => true,),
                'Entity ID'
            );

            $table_evalent_adminactivitylog_cacheactivity->addColumn(
                'username',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                null,
                ['nullable' => false],
                'username'
            );

            $table_evalent_adminactivitylog_cacheactivity->addColumn(
                'full_name',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                null,
                [],
                'full_name'
            );

            $table_evalent_adminactivitylog_cacheactivity->addColumn(
                'datetime',
                \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                null,
                ['nullable' => false, 'default' => \Magento\Framework\DB\Ddl\Table::TIMESTAMP_INIT],
                'datetime'
            );

            $table_evalent_adminactivitylog_cacheactivity->addColumn(
                'cache_type',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                null,
                [],
                'Cache type'
            );

            $table_evalent_adminactivitylog_cacheactivity->addColumn(
                'action_type',
                \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
                null,
                [],
                'action_type'
            );

            $setup->getConnection()->createTable($table_evalent_adminactivitylog_cacheactivity);
        }

        $setup->endSetup();
    }
}
