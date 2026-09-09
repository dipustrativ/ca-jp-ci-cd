<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\ResourceModel\Product\Jp\Royalty;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Helper;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\ResourceModel\Report\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Select_Exception;
use CarpetAvenue\Japan\Api\ReportsConfigInterface;

class Collection extends \Magento\Reports\Model\ResourceModel\Order\Collection
{

    private ProductAttributeRepositoryInterface $productAttributeRepository;
    protected ReportsConfigInterface $configInterface;

    public function __construct(
        ProductAttributeRepositoryInterface $productAttributeRepository,
        EntityFactory $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        Snapshot $entitySnapshot,
        Helper $coreResourceHelper,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        TimezoneInterface $localeDate,
        Config $orderConfig,
        OrderFactory $reportOrderFactory,
        ReportsConfigInterface $configInterface,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $entitySnapshot, $coreResourceHelper, $scopeConfig, $storeManager, $localeDate, $orderConfig, $reportOrderFactory, $connection, $resource);
        $this->productAttributeRepository = $productAttributeRepository;
        $this->configInterface = $configInterface;
    }

    /**
     * Set Date range to collection.
     *
     * @param string $from
     * @param string $to
     * @return Collection
     * @throws Zend_Db_Select_Exception
     */
    public function setDateRange($from, $to)
    {
        $this->_reset()->addAttributeToSelect(
            '*'
        )->addOrderedQty(
            $from,
            $to
        );
        return $this;
    }

    /**
     * Add ordered qty's
     *
     * @param string $from
     * @param string $to
     * @return $this
     * @throws Zend_Db_Select_Exception
     */
    public function addOrderedQty($from = '', $to = ''){
        $connection = $this->getConnection();

        $costId = $this->productAttributeRepository->get('cost')->getAttributeId();
        $nameId = $this->productAttributeRepository->get('name')->getAttributeId();
        $ownerId = $this->productAttributeRepository->get('owner')->getAttributeId();
        $supplierId = $this->productAttributeRepository->get('supplier')->getAttributeId();
        $widthId = $this->productAttributeRepository->get('width')->getAttributeId();
        $lengthId = $this->productAttributeRepository->get('length')->getAttributeId();
        $jpStoreId = $this->configInterface->storeId();

        $this->getSelect()->reset()->from(
            ['main_table' => $this->getTable('sales_order')],
            [
                'order_no' => 'main_table.increment_id',
                'order_status' => 'main_table.status',
                'invoice_no' => 'si.increment_id',
                'invoice_date' => 'si.created_at',
                'creditmemo_no' => 'sc.increment_id',
                'creditmemo_date' => 'sc.created_at',
                'sku' => 'soi.sku',
                'qty_ordered' => 'soi.qty_ordered',
                'qty_refunded' => 'soi.qty_refunded',
                'cost_YEN' => 'cost_YEN.value',
                'cost_EUR' => 'cost_EUR.value',
                'name' => 'name.value',
                'owner' => 'owner.value',
                'supplier' => 'supplier.value',
                'width' => 'width.value',
                'length' => 'length.value',
                'invoice_currency_rate' => 'si.store_to_eur_rate',
                'creditmemo_currency_rate' => 'sc.store_to_eur_rate'
            ]
        )->joinInner(
            ['soi' => $this->getTable('sales_order_item')],
            'soi.order_id=main_table.entity_id',
            []
        )->joinInner(
            ['si' => $this->getTable('sales_invoice')],
            'si.order_id=main_table.entity_id',
            []
        )->joinLeft(
            ['sci' => $this->getTable('sales_creditmemo_item')],
            'sci.order_item_id=soi.item_id',
            []
        )->joinLeft(
            ['sc' => $this->getTable('sales_creditmemo')],
            'sc.entity_id=sci.parent_id',
            []
        )->joinLeft(
            ['cost_YEN' => $this->getTable('catalog_product_entity_decimal')],
            'cost_YEN.attribute_id='.$costId.' and cost_YEN.store_id='.$jpStoreId.' and cost_YEN.entity_id=soi.product_id',
            []
        )->joinLeft(
            ['cost_EUR' => $this->getTable('catalog_product_entity_decimal')],
            'cost_EUR.attribute_id='.$costId.' and cost_EUR.store_id=0 and cost_EUR.entity_id=soi.product_id',
            []
        )->joinLeft(
            ['name' => $this->getTable('catalog_product_entity_varchar')],
            'name.attribute_id='.$nameId.' and name.store_id=0 and name.entity_id=soi.product_id',
            []
        )->joinLeft(
            ['owner' => $this->getTable('catalog_product_entity_varchar')],
            'owner.attribute_id='.$ownerId.' and owner.store_id=0 and owner.entity_id=soi.product_id',
            []
        )->joinLeft(
            ['supplier' => $this->getTable('catalog_product_entity_varchar')],
            'supplier.attribute_id='.$supplierId.' and supplier.store_id=0 and supplier.entity_id=soi.product_id',
            []
        )->joinLeft(
            ['width' => $this->getTable('catalog_product_entity_int')],
            'width.attribute_id='.$widthId.' and width.store_id=0 and width.entity_id=soi.product_id',
            []
        )->joinLeft(
            ['length' => $this->getTable('catalog_product_entity_int')],
            'length.attribute_id='.$lengthId.' and length.store_id=0 and length.entity_id=soi.product_id',
            []
        );

        $royaltyPercent = $this->configInterface->royaltyPercent()/100;
        $costMultiplier = $this->configInterface->costMultiplier();
        $costBase = $this->configInterface->costBase();

        $expression = new \Zend_Db_Expr('((soi.row_total_incl_tax - soi.discount_amount) / (1 + soi.tax_percent/100))');
        $this->addExpressionFieldToSelect('row_total_excl_tax',$expression,['soi.row_total_incl_tax', 'soi.discount_amount','soi.tax_percent']);
        $expression = new \Zend_Db_Expr('((soi.row_total_incl_tax - soi.discount_amount) / (1 + soi.tax_percent/100)/si.store_to_eur_rate)');
        $this->addExpressionFieldToSelect('row_total_excl_tax_eur',$expression,['soi.row_total_incl_tax', 'soi.discount_amount','soi.tax_percent', 'si.store_to_eur_rate']);

        $expression =  new \Zend_Db_Expr('(((soi.row_total_incl_tax-soi.discount_amount)/(1+soi.tax_percent/100))*'.$royaltyPercent.')');
        $this->addExpressionFieldToSelect('row_royalty',$expression,['soi.row_total_incl_tax', 'soi.discount_amount','soi.tax_percent']);
        $expression =  new \Zend_Db_Expr('(((soi.row_total_incl_tax-soi.discount_amount)/(1+soi.tax_percent/100))*'.$royaltyPercent.'/si.store_to_eur_rate)');
        $this->addExpressionFieldToSelect('row_royalty_eur',$expression,['soi.row_total_incl_tax', 'soi.discount_amount','soi.tax_percent', 'si.store_to_eur_rate']);

        $expression =  new \Zend_Db_Expr("(if('Shahram'<>owner.value,((cost_EUR.value*".$costMultiplier.")+".$costBase."),''))");
        $this->addExpressionFieldToSelect('cost_JP_EUR',$expression,['owner.value','cost_EUR.value']);

        $expression =  new \Zend_Db_Expr("(if('Shahram'<>owner.value,cost_EUR.value,''))");
        $this->addExpressionFieldToSelect('cost_EUR_CA',$expression,['owner.value','cost_EUR.value']);

        $this->addFieldToFilter('main_table.store_id', ['eq' => $jpStoreId]);
        //$this->addFieldToFilter('main_table.status', ['in' => ['complete','closed']]);
        /*$this->addFieldToFilter(['si.created_at'],
            [
                ['gteq' => $from]
            ]);
        $this->addFieldToFilter(['si.created_at'],
            [
                ['lteq' => $to]
            ]);*/
        $this->addFieldToFilter(['si.created_at','sc.created_at'],
            [
                ['gteq' => $from],
                ['gteq' => $from]
            ]);
        $this->addFieldToFilter(['si.created_at','sc.created_at'],
            [
                ['lteq' => $to],
                ['lteq' => $to]
            ]);
        
        return $this;
    }
    /**
     * Store selection is disabled
     *
     * @param array $storeIds
     * @return $this
     */
    public function setStoreIds($storeIds)
    {
        return $this;
    }
}

