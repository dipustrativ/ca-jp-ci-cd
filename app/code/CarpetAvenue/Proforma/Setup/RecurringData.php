<?php
declare(strict_types=1);

namespace CarpetAvenue\Proforma\Setup;

use CarpetAvenue\Proforma\Model\Proforma;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\SalesSequence\Model\Builder;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Provision the sales sequence (meta + sequence table) for the "proforma" entity.
 *
 * ProformaRepository::create() calls sequenceManager->getSequence('proforma', 0),
 * which fails with a null sequence table when no meta exists. The module never
 * created the sequence, so we create it here for store 0 (used by the repository)
 * and every store.
 *
 * A recurring data script (not a data patch) is used because Builder::create()
 * issues a CREATE TABLE DDL, which is not allowed inside the data-patch transaction.
 * Builder::create() is idempotent, so re-running on each setup:upgrade is safe.
 */
class RecurringData implements InstallDataInterface
{
    public function __construct(
        private readonly Builder $sequenceBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        $storeIds = [0];
        foreach ($this->storeManager->getStores() as $store) {
            $storeIds[] = (int) $store->getId();
        }

        foreach (array_unique($storeIds) as $storeId) {
            $this->sequenceBuilder
                ->setEntityType(Proforma::ENTITY_TYPE)
                ->setStoreId($storeId)
                ->setPrefix('')
                ->setSuffix('')
                ->setStartValue(1)
                ->setStep(1)
                ->setMaxValue(4294967295)
                ->setWarningValue(4294967295)
                ->create();
        }
    }
}
