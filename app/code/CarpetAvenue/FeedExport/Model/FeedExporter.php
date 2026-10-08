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

namespace CarpetAvenue\FeedExport\Model;

use CarpetAvenue\FeedExport\Api\Data\ExportResultInterface;
use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\Export\CollectionBuilder;
use CarpetAvenue\FeedExport\Model\Export\ExportContextFactory;
use CarpetAvenue\FeedExport\Model\Export\ExportResultFactory;
use CarpetAvenue\FeedExport\Model\Export\FieldResolverFactory;
use CarpetAvenue\FeedExport\Model\Export\FileWriter;
use CarpetAvenue\FeedExport\Model\Export\ProductDataBuilder;
use CarpetAvenue\FeedExport\Model\Export\XmlBuilder;
use CarpetAvenue\FeedExport\Model\Export\XmlBuilderFactory;
use CarpetAvenue\FeedExport\Model\Export\XslRenderer;
use CarpetAvenue\FeedExport\Model\ResourceModel\Log as LogResource;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile as ProfileResource;
use Magento\Framework\App\Area;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * profile -> filtered collection -> per-product data -> intermediate XML -> XSL -> file(s) on disk.
 */
class FeedExporter implements FeedExporterInterface
{
    private const LOCK_PREFIX = 'carpetavenue_feedexport_profile_';

    public function __construct(
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly ProfileResource $profileResource,
        private readonly CollectionBuilder $collectionBuilder,
        private readonly ProductDataBuilder $productDataBuilder,
        private readonly FieldResolverFactory $fieldResolverFactory,
        private readonly ExportContextFactory $exportContextFactory,
        private readonly XmlBuilderFactory $xmlBuilderFactory,
        private readonly XslRenderer $xslRenderer,
        private readonly FileWriter $fileWriter,
        private readonly ExportResultFactory $exportResultFactory,
        private readonly LogFactory $logFactory,
        private readonly LogResource $logResource,
        private readonly LockManagerInterface $lockManager,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly EventManager $eventManager,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function export(int $profileId, string $trigger = self::TRIGGER_MANUAL): ExportResultInterface
    {
        $profile = $this->profileRepository->getById($profileId);
        $lockName = self::LOCK_PREFIX . $profileId;
        if (!$this->lockManager->lock($lockName, 0)) {
            throw new LocalizedException(__('Feed profile "%1" is already running.', $profile->getName()));
        }

        $started = microtime(true);
        $log = $this->logFactory->create();
        $log->setData([
            'profile_id' => $profileId,
            'trigger_type' => $trigger,
            'status' => Log::STATUS_RUNNING,
        ]);
        $this->logResource->save($log);

        try {
            $xmlBuilder = $this->buildXml($profile, $profile->getXslTemplate());
            $recordCount = $xmlBuilder->getCount();
            $files = $this->xslRenderer->render(
                $profile->getXslTemplate(),
                $xmlBuilder->getDocument(),
                $this->getTemplateVariables($profile, $recordCount, (int)$log->getId())
            );
            unset($xmlBuilder);
            $paths = $this->fileWriter->write($profile->getDestinationPath(), $files);

            $duration = round(microtime(true) - $started, 2);
            $result = $this->exportResultFactory->create([
                'profileId' => $profileId,
                'recordCount' => $recordCount,
                'files' => $paths,
                'duration' => $duration,
            ]);
            $this->profileResource->updateLastExecution($profileId, $this->dateTime->gmtDate());
            $log->addData([
                'status' => Log::STATUS_SUCCESS,
                'records_exported' => $result->getRecordCount(),
                'files' => implode("\n", $paths),
                'duration' => $duration,
                'message' => __('Exported %1 product(s).', $result->getRecordCount())->render(),
            ]);
            $this->logResource->save($log);

            $this->eventManager->dispatch('carpetavenue_feedexport_after_export', [
                'profile' => $profile,
                'result' => $result,
                'trigger' => $trigger,
            ]);
            return $result;
        } catch (\Throwable $e) {
            $log->addData([
                'status' => Log::STATUS_FAILED,
                'duration' => round(microtime(true) - $started, 2),
                'message' => mb_substr($e->getMessage(), 0, 60000),
            ]);
            $this->logResource->save($log);
            $this->logger->error(
                sprintf('[FeedExport] Profile %d (%s) failed: %s', $profileId, $profile->getName(), $e->getMessage()),
                ['exception' => $e]
            );
            throw $e instanceof LocalizedException
                ? $e
                : new LocalizedException(
                    __('Feed export failed: %1', $e->getMessage()),
                    $e instanceof \Exception ? $e : null
                );
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    public function test(ProfileInterface $profile, array $productIds): array
    {
        $xmlBuilder = $this->buildXml($profile, $profile->getXslTemplate(), $productIds);
        $this->assertExported($xmlBuilder, $productIds);
        return $this->xslRenderer->render(
            $profile->getXslTemplate(),
            $xmlBuilder->getDocument(),
            $this->getTemplateVariables($profile, $xmlBuilder->getCount(), 0)
        );
    }

    public function getSourceXml(ProfileInterface $profile, array $productIds): string
    {
        // Empty field template = unrestricted: every data provider runs.
        $xmlBuilder = $this->buildXml($profile, '', $productIds);
        $this->assertExported($xmlBuilder, $productIds);
        return $xmlBuilder->getDocument();
    }

    /**
     * @param int[] $productIds
     * @throws LocalizedException
     */
    private function assertExported(XmlBuilder $xmlBuilder, array $productIds): void
    {
        if ($xmlBuilder->getCount() === 0) {
            throw new LocalizedException(__(
                'Product ID %1 was not exported: it does not exist, or the profile filters'
                . ' (store, status, visibility, type, stock, SKUs) exclude it.',
                implode(', ', $productIds)
            ));
        }
    }

    /**
     * @return array<string, int>
     */
    private function getTemplateVariables(ProfileInterface $profile, int $recordCount, int $exportId): array
    {
        return [
            'collectioncount' => $recordCount,
            'exportid' => $exportId,
            'rootCategoryId' => $profile->getStoreId()
                ? (int)$this->storeManager->getStore($profile->getStoreId())->getRootCategoryId()
                : 0,
        ];
    }

    /**
     * Loads products page by page inside the profile's store environment
     * (currency, prices, URLs, labels) and streams them into the intermediate XML.
     *
     * @param string $fieldTemplate Template whose field references decide which data is computed
     * @param int[] $productIds Restrict to these products (test runs); empty = all matching the profile
     */
    private function buildXml(ProfileInterface $profile, string $fieldTemplate, array $productIds = []): XmlBuilder
    {
        $fieldResolver = $this->fieldResolverFactory->create(['xslTemplate' => $fieldTemplate]);
        $context = $this->exportContextFactory->create(['profile' => $profile, 'fieldResolver' => $fieldResolver]);
        $xmlBuilder = $this->xmlBuilderFactory->create();

        $storeId = $profile->getStoreId();
        if ($storeId) {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        }
        try {
            $collection = $this->collectionBuilder->build($profile, $fieldResolver);
            $this->eventManager->dispatch('carpetavenue_feedexport_collection_prepare', [
                'profile' => $profile,
                'collection' => $collection,
            ]);
            if ($productIds) {
                $collection->addIdFilter($productIds);
            }
            $collection->setPageSize($this->config->getBatchSize());
            $lastPage = null;
            $page = 1;
            do {
                $collection->clear()->setCurPage($page);
                $collection->load();
                $lastPage ??= (int)$collection->getLastPageNumber();
                foreach ($collection as $product) {
                    $xmlBuilder->addObject($this->productDataBuilder->build($product, $context));
                }
                $page++;
            } while ($page <= $lastPage && $collection->count() > 0);
        } finally {
            if ($storeId) {
                $this->emulation->stopEnvironmentEmulation();
            }
        }
        return $xmlBuilder;
    }
}
