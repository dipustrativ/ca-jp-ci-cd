<?php


namespace Evalent\AdminActivityLog\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\InstallSchemaInterface;

class InstallSchema implements InstallSchemaInterface
{

    /**
     * {@inheritdoc}
     * @throws \Zend_Db_Exception
     */
    public function install(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $installer = $setup;
        $installer->startSetup();

        $this->installLoginActivity($setup);
        $this->installModelActivity($setup);
        $this->installCmsActivity($setup);
        $this->installConfigActivity($setup);

        $setup->endSetup();
    }

    /**
     * @param SchemaSetupInterface $setup
     *
     * @throws \Zend_Db_Exception
     */
    public function installModelActivity($setup)
    {

        $table_evalent_adminactivitylog_modelactivity = $setup->getConnection()->newTable(
            $setup->getTable('evalent_adminactivitylog_modelactivity')
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'modelactivity_id',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            null,
            array('identity' => true,'nullable' => false,'primary' => true,'unsigned' => true,),
            'Entity ID'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'username',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            'username'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'full_name',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'full_name'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'datetime',
            \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false],
            'datetime'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'model_entity_id',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Models entity id'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'action_type',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'action_type'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'edited_attributes',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Edited attributes'
        );

        $table_evalent_adminactivitylog_modelactivity->addColumn(
            'model_type',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            'model_type'
        );

        $setup->getConnection()->createTable($table_evalent_adminactivitylog_modelactivity);
    }

    /**
     * @param SchemaSetupInterface $setup
     *
     * @throws \Zend_Db_Exception
     */
    public function installLoginActivity($setup)
    {
        $table_evalent_adminactivitylog_loginactivity = $setup->getConnection()->newTable(
            $setup->getTable('evalent_adminactivitylog_loginactivity')
        );

        $table_evalent_adminactivitylog_loginactivity->addColumn(
            'loginactivity_id',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            null,
            array('identity' => true,'nullable' => false,'primary' => true,'unsigned' => true,),
            'Entity ID'
        );

        $table_evalent_adminactivitylog_loginactivity->addColumn(
            'username',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            'username'
        );

        $table_evalent_adminactivitylog_loginactivity->addColumn(
            'ip_address',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Ip address of location'
        );

        $table_evalent_adminactivitylog_loginactivity->addColumn(
            'status',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            '0: Failed, 1 Success, 2 Logout'
        );

        $setup->getConnection()->createTable($table_evalent_adminactivitylog_loginactivity);
    }


    /**
     * @param SchemaSetupInterface $setup
     *
     * @throws \Zend_Db_Exception
     */
    public function installCmsActivity($setup)
    {

        $table_evalent_adminactivitylog_cmsactivity = $setup->getConnection()->newTable(
            $setup->getTable('evalent_adminactivitylog_cmsactivity')
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'cmsactivity_id',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            null,
            array('identity' => true,'nullable' => false,'primary' => true,'unsigned' => true,),
            'Entity ID'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'username',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            'username'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'full_name',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'full_name'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'datetime',
            \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false],
            'datetime'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'title',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Title of the CMS'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'action_type',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'action_type'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'edited_attributes',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Edited attributes'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'old_content',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Old content'
        );

        $table_evalent_adminactivitylog_cmsactivity->addColumn(
            'new_content',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'New content'
        );

        $setup->getConnection()->createTable($table_evalent_adminactivitylog_cmsactivity);
    }



    /**
     * @param SchemaSetupInterface $setup
     *
     * @throws \Zend_Db_Exception
     */
    public function installConfigActivity($setup)
    {

        $table_evalent_adminactivitylog_configactivity = $setup->getConnection()->newTable(
            $setup->getTable('evalent_adminactivitylog_configactivity')
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'configactivity_id',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            null,
            array('identity' => true,'nullable' => false,'primary' => true,'unsigned' => true,),
            'Entity ID'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'username',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            ['nullable' => false],
            'username'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'full_name',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'full_name'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'datetime',
            \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
            null,
            ['nullable' => false],
            'datetime'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'storeview',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            null,
            ['default' => '1','nullable' => false],
            'storeview'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'website',
            \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
            null,
            ['default' => '1','nullable' => false],
            'website'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'edited_attributes',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Edited Attributes'
        );

        $table_evalent_adminactivitylog_configactivity->addColumn(
            'section',
            \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            null,
            [],
            'Config section'
        );

        $setup->getConnection()->createTable($table_evalent_adminactivitylog_configactivity);
    }
}
