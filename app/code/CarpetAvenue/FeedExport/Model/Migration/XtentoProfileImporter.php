<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Migration;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\ProfileFactory;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Copies product profiles from xtento_productexport_profile into carpetavenue_feedexport_profile.
 * Reads the Xtento tables directly, so it works whether or not Xtento code is still installed.
 * Idempotent: profiles already imported (by xtento_profile_id) are skipped.
 */
class XtentoProfileImporter
{
    private const XTENTO_PROFILE_TABLE = 'xtento_productexport_profile';
    private const XTENTO_DESTINATION_TABLE = 'xtento_productexport_destination';
    private const DEFAULT_DESTINATION = 'pub/media/feeds/';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProfileFactory $profileFactory,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param bool $dryRun Only report what would happen
     * @param bool $keepCron Copy Xtento cron state; off = imported profiles start with cron disabled
     * @return array<int, array{xtento_id: int, name: string, action: string, profile_id: int|null}>
     * @throws LocalizedException
     */
    public function import(bool $dryRun = true, bool $keepCron = false): array
    {
        $connection = $this->resourceConnection->getConnection();
        $profileTable = $this->resourceConnection->getTableName(self::XTENTO_PROFILE_TABLE);
        if (!$connection->isTableExists($profileTable)) {
            throw new LocalizedException(__('Table %1 not found, nothing to import.', $profileTable));
        }

        $alreadyImported = array_map('intval', $this->collectionFactory->create()
            ->addFieldToFilter(ProfileInterface::XTENTO_PROFILE_ID, ['notnull' => true])
            ->getColumnValues(ProfileInterface::XTENTO_PROFILE_ID));
        $destinations = $this->loadLocalDestinations();

        $rows = $connection->fetchAll(
            $connection->select()->from($profileTable)->where('entity = ?', 'product')->order('profile_id ASC')
        );

        $report = [];
        foreach ($rows as $row) {
            $xtentoId = (int)$row['profile_id'];
            $entry = ['xtento_id' => $xtentoId, 'name' => (string)$row['name'], 'action' => '', 'profile_id' => null];

            if (in_array($xtentoId, $alreadyImported, true)) {
                $report[] = ['action' => 'skipped (already imported)'] + $entry;
                continue;
            }
            if (($row['output_type'] ?? 'xsl') !== 'xsl' || trim((string)$row['xsl_template']) === '') {
                $report[] = ['action' => 'skipped (no XSL template)'] + $entry;
                continue;
            }

            $profile = $this->mapProfile($row, $destinations, $keepCron);
            if (!$dryRun) {
                $this->profileRepository->save($profile);
                $entry['profile_id'] = $profile->getProfileId();
            }
            $report[] = ['action' => $dryRun ? 'would import' : 'imported'] + $entry;
        }
        return $report;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $destinations
     */
    private function mapProfile(array $row, array $destinations, bool $keepCron): ProfileInterface
    {
        $destinationPath = self::DEFAULT_DESTINATION;
        foreach (array_filter(explode('&', (string)$row['destination_ids'])) as $destinationId) {
            if (isset($destinations[(int)$destinationId])) {
                $destinationPath = $destinations[(int)$destinationId];
                break;
            }
        }

        $profile = $this->profileFactory->create();
        $profile->setData([
            ProfileInterface::NAME => (string)$row['name'],
            ProfileInterface::ENABLED => (int)$row['enabled'],
            ProfileInterface::STORE_ID => (int)$row['store_id'],
            ProfileInterface::CUSTOMER_GROUP_ID => (int)$row['customer_group_id'],
            ProfileInterface::EXCLUDED_PRODUCT_TYPES => (string)$row['export_filter_product_type'],
            ProfileInterface::PRODUCT_STATUS => (string)$row['export_filter_product_status'],
            ProfileInterface::PRODUCT_VISIBILITY => (string)$row['export_filter_product_visibility'],
            ProfileInterface::INSTOCK_ONLY => (int)$row['export_filter_instock_only'],
            ProfileInterface::SKU_FILTER => $this->extractSkuCondition((string)$row['conditions_serialized']),
            ProfileInterface::REPLACE_NEWLINES => (int)$row['export_replace_nl_br'],
            ProfileInterface::STRIP_TAGS => (int)$row['export_strip_tags'],
            ProfileInterface::URL_REMOVE_STORE => (int)$row['export_url_remove_store'],
            ProfileInterface::REMOVE_PUB_FROM_URLS => (int)($row['remove_pub_folder_from_urls'] ?? 1),
            ProfileInterface::XSL_TEMPLATE => (string)$row['xsl_template'],
            ProfileInterface::CATEGORY_MAPPING => (string)($row['category_mapping'] ?? ''),
            ProfileInterface::TAXONOMY_SOURCE => (string)($row['taxonomy_source'] ?? ''),
            ProfileInterface::DESTINATION_PATH => $destinationPath,
            ProfileInterface::CRON_ENABLED => $keepCron ? (int)$row['cronjob_enabled'] : 0,
            ProfileInterface::CRON_EXPRESSION => $this->resolveCronExpression($row),
            ProfileInterface::XTENTO_PROFILE_ID => (int)$row['profile_id'],
        ]);
        return $profile;
    }

    /**
     * Xtento uses the custom field only when frequency is "custom" (or empty).
     *
     * @param array<string, mixed> $row
     */
    private function resolveCronExpression(array $row): string
    {
        $frequency = trim((string)$row['cronjob_frequency']);
        if ($frequency === 'custom' || $frequency === '') {
            return trim((string)$row['cronjob_custom_frequency']);
        }
        return $frequency;
    }

    /**
     * Only "SKU is one of" conditions are carried over; other rule conditions are not supported.
     */
    private function extractSkuCondition(string $serialized): ?string
    {
        $conditions = json_decode($serialized, true);
        if (!is_array($conditions)) {
            return null;
        }
        $skus = [];
        $stack = [$conditions];
        while ($stack) {
            $node = array_pop($stack);
            if (($node['attribute'] ?? null) === 'sku' && in_array($node['operator'] ?? '', ['()', '=='], true)) {
                $skus[] = (string)$node['value'];
            }
            foreach ($node['conditions'] ?? [] as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }
        return $skus ? implode(',', $skus) : null;
    }

    /**
     * @return array<int, string> destination_id => relative path
     */
    private function loadLocalDestinations(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::XTENTO_DESTINATION_TABLE);
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $paths = [];
        $rows = $connection->fetchAll($connection->select()->from($table)->where('type = ?', 'local'));
        foreach ($rows as $row) {
            $path = (string)preg_replace('#^\./#', '', trim((string)$row['path']));
            if ($path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..')) {
                $paths[(int)$row['destination_id']] = rtrim($path, '/') . '/';
            }
        }
        return $paths;
    }
}
