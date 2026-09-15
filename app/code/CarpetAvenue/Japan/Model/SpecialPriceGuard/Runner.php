<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Orchestrates one guard run: scan → decide → (cap) → write → log.
 *
 * Everything is decided before anything is written, so the blast-radius cap can
 * refuse a whole run rather than stopping halfway through a repricing.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use CarpetAvenue\Japan\Api\PriceActionRecorderInterface;
use CarpetAvenue\Japan\Api\SpecialPriceGuardConfigInterface;
use CarpetAvenue\Japan\Model\PriceAction\Entry;
use Psr\Log\LoggerInterface;

class Runner
{
    public function __construct(
        private readonly SpecialPriceGuardConfigInterface $config,
        private readonly Calculator $calculator,
        private readonly Scanner $scanner,
        private readonly CatalogWriter $writer,
        private readonly PriceActionRecorderInterface $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(RunOptions $options): RunResult
    {
        $runId = uniqid('spg_', false);
        $storeId = $this->config->storeId();

        if ($reason = $this->refuse($options)) {
            return new RunResult($runId, $options->dryRun, aborted: true, abortReason: $reason);
        }

        $outcomes = $this->collect($options, $storeId);
        $changes = array_filter($outcomes, static fn (Outcome $o): bool => $o->writesCatalog());

        $catalogCount = $this->scanner->countCatalog();
        if ($reason = $this->capBreach($options, count($changes), $catalogCount)) {
            $this->logger->warning('[CarpetAvenue_Japan] special price guard aborted: ' . $reason);

            return new RunResult(
                $runId,
                $options->dryRun,
                scanned: count($outcomes),
                catalogCount: $catalogCount,
                aborted: true,
                abortReason: $reason
            );
        }

        // array_map keeps the product-id keys when given a single array.
        $values = array_map(static fn (Outcome $o): ?float => $o->decision->newValue, $changes);
        $failures = $options->dryRun ? [] : $this->writer->write($values, $storeId);

        $this->record($outcomes, $failures, $runId, $storeId, $options->dryRun);
        $this->recorder->prune($this->config->logRetentionDays(), Decision::RULE_CODE);

        $result = new RunResult(
            runId: $runId,
            dryRun: $options->dryRun,
            scanned: count($outcomes),
            raised: $this->countAction($outcomes, Entry::ACTION_RAISE),
            cleared: $this->countAction($outcomes, Entry::ACTION_CLEAR),
            skipped: count($outcomes) - count($changes),
            errors: count($failures),
            catalogCount: $catalogCount,
            changes: array_map(static fn (Outcome $o): Entry => $o->toEntry($storeId), array_values($changes))
        );

        $this->logger->info('[CarpetAvenue_Japan] special price guard ' . $result->summary());

        return $result;
    }

    /**
     * Scan the catalog in keyset pages and decide every product.
     *
     * @return Outcome[] keyed by product id
     */
    private function collect(RunOptions $options, int $storeId): array
    {
        $batchSize = $this->config->batchSize();
        $excludedOwners = $this->config->excludedOwners();
        $outcomes = [];

        // Keyset paging: each batch asks for products *after* the highest id
        // seen so far, so the database never counts past rows the way OFFSET
        // does. Page 100 costs the same as page 1.
        $afterId = 0;

        while (true) {
            // How many to ask for this time. Without --limit it is always the
            // full batch; with --limit, never more than the remainder, so the
            // last page is short rather than overshooting.
            //
            //   --limit=1200, batch_size=500:
            //     pass 1  have    0  take min(500, 1200) = 500
            //     pass 2  have  500  take min(500,  700) = 500
            //     pass 3  have 1000  take min(500,  200) = 200   ← short page
            //     pass 4  have 1200  take min(500,    0) =   0   ← stop
            $take = $options->limit > 0 ? min($batchSize, $options->limit - count($outcomes)) : $batchSize;

            // Must stop here, not fall through: Zend_Db_Select::limit(0) emits
            // no LIMIT clause at all, so a zero-sized page would fetch the rest
            // of the catalog and sail straight past --limit.
            if ($take <= 0) {
                break;
            }

            $rows = $this->scanner->fetchBatch($storeId, $afterId, $take, $options->skus, $excludedOwners);
            if ($rows === []) {
                break;
            }

            // Union, not append: `+=` keeps the product-id keys, which the
            // failure lookup in record() depends on. array_merge() would
            // renumber them 0,1,2… and break it. Safe against `+=` silently
            // dropping duplicates because keyset paging makes batches disjoint —
            // every page fetches ids strictly greater than the last.
            $outcomes += $this->decide($rows);
            $afterId = max(array_keys($rows));

            if (count($rows) < $take) {
                break;
            }
        }

        return $outcomes;
    }


    /**
     * Reasons to do nothing at all, checked before touching the catalog.
     */
    private function refuse(RunOptions $options): ?string
    {
        if (!$this->config->isEnabled() && !$options->force) {
            return 'guard disabled in config';
        }

        $missing = $this->scanner->missingAttributes();

        return $missing === [] ? null : 'missing product attributes: ' . implode(', ', $missing);
    }


    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Outcome[] keyed by product id
     */
    private function decide(array $rows): array
    {
        $outcomes = [];
        foreach ($rows as $productId => $row) {
            $outcomes[$productId] = new Outcome($productId, $row, $this->calculator->decide($row));
        }

        return $outcomes;
    }

    /**
     * Refuse runs that would reprice an implausible share of the catalog —
     * usually a fat-fingered multiplier rather than a real price movement.
     */
    private function capBreach(RunOptions $options, int $affected, int $catalogCount): ?string
    {
        $cap = $this->config->maxAffectedPercent();
        if ($options->force || $options->skus !== [] || $cap <= 0.0 || $catalogCount <= 0 || $affected === 0) {
            return null;
        }

        $percent = $affected / $catalogCount * 100;
        if ($percent <= $cap) {
            return null;
        }

        return sprintf(
            '%d of %d products (%.1f%%) would change, over the %.1f%% cap — re-run with --force if intended',
            $affected,
            $catalogCount,
            $percent,
            $cap
        );
    }

    /**
     * @param Outcome[] $outcomes
     * @param array<int, string> $failures
     */
    private function record(array $outcomes, array $failures, string $runId, int $storeId, bool $dryRun): void
    {
        $entries = [];
        foreach ($outcomes as $productId => $outcome) {
            if (!$outcome->decision->isLoggable()) {
                continue;
            }

            $entry = $outcome->toEntry($storeId);
            $entries[] = isset($failures[$productId]) ? $entry->failed($failures[$productId]) : $entry;
        }

        $this->recorder->logMany($entries, $runId, $dryRun);
    }

    /**
     * @param Outcome[] $outcomes
     */
    private function countAction(array $outcomes, string $action): int
    {
        return count(array_filter($outcomes, static fn (Outcome $o): bool => $o->decision->action === $action));
    }
}
