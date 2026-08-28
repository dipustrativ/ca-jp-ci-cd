<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Developify\Ui\DataProvider;

use Magento\Cron\Model\ConfigInterface;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Feeds the Cron Jobs grid from cron config rather than a database table.
 *
 * There is no table of registered jobs — they come from every module's
 * crontab.xml, so filtering, sorting and paging all happen in PHP here. The set
 * is the number of jobs the installation declares (~100), which is why doing it
 * in memory is reasonable; it is bounded by the codebase, not by store data.
 */
class CronJobDataProvider extends AbstractDataProvider
{
    /**
     * @var Filter[]
     */
    private array $appliedFilters = [];

    /**
     * @var string|null
     */
    private ?string $orderField = null;

    /**
     * @var string
     */
    private string $orderDirection = 'ASC';

    /**
     * @var int
     */
    private int $currentPage = 1;

    /**
     * @var int
     */
    private int $pageSize = 0;

    /**
     * @var array|null
     */
    private ?array $rows = null;

    /**
     * Constructor
     *
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param ConfigInterface $cronConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        private ConfigInterface $cronConfig,
        private ScopeConfigInterface $scopeConfig,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Collect a filter to apply in getData()
     *
     * @param Filter $filter
     * @return void
     */
    public function addFilter(Filter $filter)
    {
        $this->appliedFilters[] = $filter;
    }

    /**
     * Record the requested sort
     *
     * @param string $field
     * @param string $direction
     * @return void
     */
    public function addOrder($field, $direction)
    {
        $this->orderField = (string) $field;
        $this->orderDirection = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
    }

    /**
     * Record the requested page
     *
     * Note $offset is the 1-based page number, not a row offset — that is what
     * Magento\Ui\Component\Paging::getOffset() passes, despite the name.
     *
     * @param int $offset
     * @param int $size
     * @return void
     */
    public function setLimit($offset, $size)
    {
        $this->currentPage = max(1, (int) $offset);
        $this->pageSize = max(0, (int) $size);
    }

    /**
     * Filtered, sorted and paged jobs
     *
     * @return array
     */
    public function getData()
    {
        $rows = $this->applyFilters($this->getRows());
        $total = count($rows);
        $rows = $this->applySort($rows);

        if ($this->pageSize > 0) {
            $rows = array_slice($rows, ($this->currentPage - 1) * $this->pageSize, $this->pageSize);
        }

        return [
            'totalRecords' => $total,
            'items' => array_values($rows),
        ];
    }

    /**
     * Not supported — this provider has no collection behind it
     *
     * Massactions and the CSV/XML exporters are the only callers, and the Cron
     * Jobs grid deliberately has none. Fail with a sentence rather than a null
     * dereference if one is ever added.
     *
     * @return void
     * @throws LocalizedException
     */
    public function getSearchResult()
    {
        throw new LocalizedException(
            __('The Cron Jobs grid is backed by cron config, not a collection, so it cannot be exported.')
        );
    }

    /**
     * Every registered job, flattened and sorted by group then code
     *
     * @return array
     */
    private function getRows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $rows = [];
        foreach ($this->cronConfig->getJobs() as $group => $groupJobs) {
            foreach ($groupJobs as $code => $job) {
                $rows[] = [
                    'job_code' => (string) $code,
                    'group' => (string) $group,
                    'schedule' => $this->resolveSchedule($job),
                    'instance' => (string) ($job['instance'] ?? ''),
                    'method' => (string) ($job['method'] ?? ''),
                ];
            }
        }

        usort($rows, static fn ($a, $b) => [$a['group'], $a['job_code']] <=> [$b['group'], $b['job_code']]);

        $this->rows = $rows;

        return $this->rows;
    }

    /**
     * Cron expression, or the config path it is read from
     *
     * @param array $job
     * @return string
     */
    private function resolveSchedule(array $job): string
    {
        if (!empty($job['schedule'])) {
            return (string) $job['schedule'];
        }

        // Jobs using <config_path> take their expression from store config instead.
        if (!empty($job['config_path'])) {
            $configured = $this->scopeConfig->getValue($job['config_path']);
            return $configured
                ? sprintf('%s (%s)', $configured, $job['config_path'])
                : (string) $job['config_path'];
        }

        return '';
    }

    /**
     * Keep only rows matching every applied filter
     *
     * @param array $rows
     * @return array
     */
    private function applyFilters(array $rows): array
    {
        foreach ($this->appliedFilters as $filter) {
            $rows = array_filter($rows, fn (array $row) => $this->matches($row, $filter));
        }

        return $rows;
    }

    /**
     * Does one row satisfy one filter
     *
     * @param array $row
     * @param Filter $filter
     * @return bool
     */
    private function matches(array $row, Filter $filter): bool
    {
        $actual = (string) ($row[$filter->getField()] ?? '');
        $value = $filter->getValue();

        switch ($filter->getConditionType()) {
            case 'like':
            case 'fulltext':
                // The UI wraps text-filter input in % before it reaches us.
                $needle = trim((string) $value, '%');
                return $needle === '' || stripos($actual, $needle) !== false;
            case 'in':
                return in_array($actual, array_map('strval', (array) $value), true);
            case 'neq':
                return $actual !== (string) $value;
            default:
                return $actual === (string) $value;
        }
    }

    /**
     * Sort rows by the requested column
     *
     * @param array $rows
     * @return array
     */
    private function applySort(array $rows): array
    {
        if ($this->orderField === null) {
            return $rows;
        }

        $field = $this->orderField;
        $sign = $this->orderDirection === 'DESC' ? -1 : 1;

        usort(
            $rows,
            static fn (array $a, array $b) => $sign * strnatcasecmp(
                (string) ($a[$field] ?? ''),
                (string) ($b[$field] ?? '')
            )
        );

        return $rows;
    }
}
