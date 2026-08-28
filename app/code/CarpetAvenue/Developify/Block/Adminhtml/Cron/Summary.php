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

namespace CarpetAvenue\Developify\Block\Adminhtml\Cron;

use CarpetAvenue\Developify\Model\Config\Source\CronStatus as StatusSource;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Status counts shown above the schedule grid.
 *
 * cron_schedule grows without bound between cron/purgehistory runs, so the count
 * is deliberately restricted to a fixed-size window of the most recent rows by
 * primary key. That keeps the query a PRIMARY range scan whose cost never moves
 * as the table grows.
 */
class Summary extends Template
{
    /**
     * How many of the most recent schedule rows the counts cover
     */
    public const WINDOW = 20000;

    /**
     * Seconds the computed counts stay cached
     */
    private const CACHE_LIFETIME = 60;

    /**
     * Cache key for the computed counts
     */
    private const CACHE_KEY = 'developify_cron_status_summary';

    /**
     * @var array|null
     */
    private ?array $counts = null;

    /**
     * Constructor
     *
     * @param Context $context
     * @param ResourceConnection $resourceConnection
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     * @param StatusSource $statusSource
     * @param array $data
     */
    public function __construct(
        Context $context,
        private ResourceConnection $resourceConnection,
        private CacheInterface $cache,
        private SerializerInterface $serializer,
        private StatusSource $statusSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Status => count, every status present, ordered as in the source model
     *
     * @return array
     */
    public function getCounts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            $this->counts = $this->serializer->unserialize($cached);
            return $this->counts;
        }

        $this->counts = $this->fetchCounts();
        $this->cache->save(
            $this->serializer->serialize($this->counts),
            self::CACHE_KEY,
            [],
            self::CACHE_LIFETIME
        );

        return $this->counts;
    }

    /**
     * Size of the window the counts cover
     *
     * @return int
     */
    public function getWindow(): int
    {
        return self::WINDOW;
    }

    /**
     * Label for a status
     *
     * @param string $status
     * @return \Magento\Framework\Phrase|string
     */
    public function getStatusLabel(string $status)
    {
        return $this->statusSource->getLabels()[$status] ?? $status;
    }

    /**
     * Count rows per status within the most recent WINDOW schedule ids
     *
     * Deliberately a primary-key window rather than a time window. cron_schedule's only
     * index is (job_code, status, scheduled_at), so a `scheduled_at >= ...` predicate
     * cannot use it and would degrade into a full scan as the table grows. If an exact
     * 24h figure is ever needed, add an index on scheduled_at first, then switch the
     * WHERE clause.
     *
     * ponytail: PK-window count, not a true time window; upgrade path above.
     *
     * @return array
     */
    private function fetchCounts(): array
    {
        $counts = array_fill_keys(array_keys($this->statusSource->getLabels()), 0);

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');

        // O(1) index lookup — gives us the window floor without touching the rows.
        $maxId = (int) $connection->fetchOne(
            $connection->select()->from($table, new Expression('MAX(schedule_id)'))
        );

        if ($maxId === 0) {
            return $counts;
        }

        $select = $connection->select()
            ->from($table, ['status', 'total' => new Expression('COUNT(*)')])
            ->where('schedule_id > ?', $maxId - self::WINDOW)
            ->group('status');

        foreach ($connection->fetchPairs($select) as $status => $total) {
            $counts[$status] = (int) $total;
        }

        return $counts;
    }
}
