<?php
namespace CarpetAvenue\Productify\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

class SyncOriginBackupObserver implements ObserverInterface
{
    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var EavConfig
     */
    protected $eavConfig;

    /**
     * @var int|null
     */
    protected $originAttributeId;

    /**
     * @var int|null
     */
    protected $originBackupAttributeId;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param EavConfig $eavConfig
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        LoggerInterface $logger,
        EavConfig $eavConfig
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->eavConfig = $eavConfig;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getProduct();
            $originId = $this->getAttributeIdByCode('origin');
            $backupId = $this->getAttributeIdByCode('origin_backup');

            if (!$originId || !$backupId) {
                return;
            }

            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('catalog_product_entity_int');

            // 1. Fetch all 'origin' values for this product (across all stores)
            $select = $connection->select()
                ->from($table, ['store_id', 'value'])
                ->where('attribute_id = ?', $originId)
                ->where('entity_id = ?', $product->getId());

            $originRows = $connection->fetchAll($select);

            if (empty($originRows)) {
                return;
            }

            // 2. Map labels from 'origin' to IDs in 'origin_backup'
            $mapping = $this->getDynamicOptionMapping();

            foreach ($originRows as $row) {
                $currentOptionId = $row['value'];

                if (isset($mapping[$currentOptionId])) {
                    $newOptionId = $mapping[$currentOptionId];

                    $connection->insertOnDuplicate($table, [
                        'attribute_id' => $backupId,
                        'store_id'     => $row['store_id'],
                        'entity_id'    => $product->getId(),
                        'value'        => $newOptionId
                    ], ['value']);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error syncing origin_backup attribute: ' . $e->getMessage());
        }
    }

    /**
     * Builds a map where [Origin Option ID] => [Backup Option ID]
     * based on matching text labels.
     *
     * @return array
     */
    protected function getDynamicOptionMapping(): array
    {
        $mapping = [];
        try {
            $originAttr = $this->eavConfig->getAttribute('catalog_product', 'origin');
            $backupAttr = $this->eavConfig->getAttribute('catalog_product', 'origin_backup');

            $originOptions = $originAttr->getSource()->getAllOptions();
            $backupOptions = $backupAttr->getSource()->getAllOptions();

            // Create a lookup for backup options: [Label] => [ID]
            $backupLookup = [];
            foreach ($backupOptions as $bOpt) {
                if ($bOpt['value']) {
                    $backupLookup[trim($bOpt['label'])] = $bOpt['value'];
                }
            }

            // Match origin IDs to backup IDs via label
            foreach ($originOptions as $oOpt) {
                $label = trim($oOpt['label']);
                if ($oOpt['value'] && isset($backupLookup[$label])) {
                    $mapping[$oOpt['value']] = $backupLookup[$label];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Mapping failed: ' . $e->getMessage());
        }
        return $mapping;
    }

    /**
     * Helper to get attribute ID from code
     *
     * @param string $code
     * @return int|null
     */
    protected function getAttributeIdByCode(string $code): ?int
    {
        try {
            return $this->eavConfig->getAttribute('catalog_product', $code)->getAttributeId();
        } catch (\Exception $e) {
            return null;
        }
    }
}