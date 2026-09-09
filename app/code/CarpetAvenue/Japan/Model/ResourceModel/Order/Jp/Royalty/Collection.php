<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\ResourceModel\Order\Jp\Royalty;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Helper;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\ResourceModel\Report\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zend_Db_Select_Exception;
use CarpetAvenue\Japan\Api\ReportsConfigInterface;

class Collection  extends \Magento\Reports\Model\ResourceModel\Order\Collection
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

        $this->getSelect()->reset()->from(
            ['main_table' => $this->getTable('sales_order')],
            [
                'order_no' => 'main_table.increment_id',
                'order_status' => 'main_table.status',
                'invoice_no' => 'si.increment_id',
                'invoice_date' => 'si.created_at',
                'creditmemo_no' => 'sc.increment_id',
                'creditmemo_date' => 'sc.created_at',
                'invoice_currency_rate' => 'si.store_to_eur_rate',
                'creditmemo_currency_rate' => 'sc.store_to_eur_rate'
            ]
        )->joinInner(
            ['si' => $this->getTable('sales_invoice')],
            'si.order_id=main_table.entity_id',
            []
        )->joinLeft(
            ['sc' => $this->getTable('sales_creditmemo')],
            'sc.order_id=main_table.entity_id',
            []
        );
        
        $royaltyPercent = $this->configInterface->royaltyPercent()/100;
        
        $expression = new \Zend_Db_Expr('(si.grand_total-si.tax_amount)');
        $this->addExpressionFieldToSelect('invoiced_excl_tax',$expression,['si.grand_total', 'si.tax_amount']);
        $expression = new \Zend_Db_Expr('((si.grand_total-si.tax_amount)/si.store_to_eur_rate)');
        $this->addExpressionFieldToSelect('invoiced_excl_tax_eur',$expression,['si.grand_total', 'si.tax_amount', 'si.store_to_eur_rate']);

        $expression =  new \Zend_Db_Expr('((si.grand_total-si.tax_amount)*'.$royaltyPercent.')');
        $this->addExpressionFieldToSelect('invoiced_royalty',$expression,['si.grand_total', 'si.tax_amount']);
        $expression =  new \Zend_Db_Expr('((si.grand_total-si.tax_amount)*'.$royaltyPercent.'/si.store_to_eur_rate)');
        $this->addExpressionFieldToSelect('invoiced_royalty_eur',$expression,['si.grand_total', 'si.tax_amount', 'si.store_to_eur_rate']);

        $expression = new \Zend_Db_Expr('(sc.grand_total-sc.tax_amount)');
        $this->addExpressionFieldToSelect('refunded_excl_tax',$expression,['sc.grand_total', 'sc.tax_amount']);
        $expression = new \Zend_Db_Expr('((sc.grand_total-sc.tax_amount)/sc.store_to_eur_rate)');
        $this->addExpressionFieldToSelect('refunded_excl_tax_eur',$expression,['sc.grand_total', 'sc.tax_amount', 'sc.store_to_eur_rate']);

        $expression =  new \Zend_Db_Expr('((sc.grand_total-sc.tax_amount)*'.$royaltyPercent.')');
        $this->addExpressionFieldToSelect('refunded_royalty',$expression,['sc.grand_total', 'sc.tax_amount']);
        $expression =  new \Zend_Db_Expr('((sc.grand_total-sc.tax_amount)*'.$royaltyPercent.'/sc.store_to_eur_rate)');
        $this->addExpressionFieldToSelect('refunded_royalty_eur',$expression,['sc.grand_total', 'sc.tax_amount', 'sc.store_to_eur_rate']);

        $this->addFieldToFilter('main_table.store_id', ['eq' => $this->configInterface->storeId()]);
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

