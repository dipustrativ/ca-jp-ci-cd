<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Customer;

use CarpetAvenue\Core\Logger\Logger;
use Magento\Customer\Model\Customer;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Writes the `customer_grid_flat` rows of the customers an import touched.
 *
 * ## Why this is not left to the model
 *
 * `customer_entity` is not what the admin's Customers grid reads — it reads the
 * flat index `customer_grid_flat`, and a customer with no row there **does not
 * appear in the grid at all**, however correct the entity is.
 *
 * `Magento\Customer\Model\Customer::afterSave()` looks like it takes care of
 * that, but it only schedules a row reindex behind two gates:
 *
 * ```php
 * public function afterSave() {
 *     if ($this->getIndexer()->getState()->getStatus() == StateInterface::STATUS_VALID) {
 *         $this->_getResource()->addCommitCallback([$this, 'reindex']);   // gate 1
 *     }
 * }
 * public function reindex() {
 *     if (!$this->getIndexer()->isScheduled()) {                          // gate 2
 *         $this->getIndexer()->reindexRow($this->getId());
 *     }
 * }
 * ```
 *
 *  - **Gate 1** — the `customer_grid` indexer must be in state VALID. An
 *    install with the index invalidated (a `setup:upgrade`, a bulk change, a
 *    never-run first reindex) registers no callback at all, so an imported
 *    customer is simply missing from the grid until someone runs a full
 *    `indexer:reindex customer_grid`. This is the common case on a migration
 *    target, which is exactly where this module runs.
 *  - **Gate 2** — "Update by Schedule" skips the row and leaves it to mview. For
 *    this index that means never: `customer_dummy`, the view `customer_grid`
 *    declares in `Magento\Customer\etc\mview.xml`, points at
 *    `Magento\Customer\Model\MviewAction`, a class that is not in the codebase
 *    (2.4.9) — which is why Magento does not really support scheduled mode for
 *    the customer grid.
 *
 * So the import reindexes the rows itself, unconditionally, once per run. It is
 * a row-level reindex of ids the run already has in hand, so the cost is
 * proportional to the import and not to the customer table, and it leaves the
 * indexer's own state alone — an install that was mid-invalidation still needs
 * (and still gets) its full reindex later.
 *
 * Also needed by the ADDRESS import, and there it is the only thing that can
 * work: the grid's `billing_*` columns are derived from the customer's default
 * billing address, and the default pointers are written with a statement rather
 * than a model save ({@see \CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData::setDefaultAddresses()}),
 * so no model hook fires at all.
 *
 * A failure is logged and swallowed: the customers are saved and correct, and
 * refusing to report the import as done because an index could not be refreshed
 * would be the wrong trade — `bin/magento indexer:reindex customer_grid` fixes
 * it after the fact.
 */
class GridReindexer
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Reindex the grid rows of these customers.
     *
     * @param int[] $customerIds
     * @return int how many customers were reindexed (0 when it could not run)
     */
    public function reindex(array $customerIds): int
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if ($customerIds === []) {
            return 0;
        }

        try {
            $this->indexerRegistry->get(Customer::CUSTOMER_GRID_INDEXER_ID)->reindexList($customerIds);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Could not refresh the customer grid for %d customer(s): %s — run '
                . '"bin/magento indexer:reindex customer_grid" to fix the grid.',
                count($customerIds),
                $e->getMessage()
            ));
            return 0;
        }

        $this->logger->info(sprintf('Refreshed the customer grid for %d customer(s).', count($customerIds)));
        return count($customerIds);
    }
}
