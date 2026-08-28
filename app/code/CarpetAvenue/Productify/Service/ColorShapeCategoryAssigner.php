<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Service;

use CarpetAvenue\Productify\Helper\CategoryAssignLogger;
use Exception;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Creates colour/shape categories under "All Carpets" and assigns enabled
 * products to them.
 *
 * Tree built:
 *   All Carpets / Colors / <color>
 *   All Carpets / Shapes / <shape>
 *
 * Categories are created lazily (existing ones reused, never duplicated) and a
 * product already linked to its target category is skipped. Every action is
 * written to a dedicated daily log file
 * (var/log/carpetavenue/productify/category_assign_<date>.log).
 *
 * Shared by the CLI command and the daily cron job.
 */
class ColorShapeCategoryAssigner
{
    private const COLOR_ATTRIBUTE_CODE = 'color';
    private const SHAPE_ATTRIBUTE_CODE = 'shape';
    private const ALL_CARPETS_NAME = 'All Carpets';
    private const COLORS_GROUP_NAME = 'Colors';
    private const SHAPES_GROUP_NAME = 'Shapes';
    private const BATCH_SIZE = 200;

    /**
     * In-run cache of resolved categories, keyed "<parentId>|<name>" => category id.
     *
     * @var array<string, int>
     */
    private array $categoryCache = [];

    /**
     * Attribute option id => label maps, filled once per attribute code.
     *
     * @var array<string, array<string, string>>
     */
    private array $optionLabelMaps = [];

    /** @var array<string,int> Run counters. */
    private array $stats = [];

    /** @var callable|null Optional message sink for console output. */
    private $notify = null;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig,
        private readonly CategoryAssignLogger $logger
    ) {
    }

    /**
     * Run the assignment.
     *
     * @param bool          $dryRun When true, nothing is created/assigned; actions are only logged.
     * @param callable|null $notify Optional callback(string $message) for human-facing progress (CLI).
     * @return array<string,int> Run statistics.
     */
    public function execute(bool $dryRun = false, ?callable $notify = null): array
    {
        $this->resetState();
        $this->notify = $notify;
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        $this->logger->info($prefix . 'Starting colour/shape category assignment.');
        $this->emit($prefix . 'Starting colour/shape category assignment...');

        // 1. Resolve the "All Carpets" parent (level 2).
        $allCarpets = $this->findAllCarpetsCategory();
        if ($allCarpets === null) {
            $msg = 'Could not find the "All Carpets" category at level 2. Aborting.';
            $this->logger->error($msg);
            $this->emit($msg);
            $this->stats['aborted'] = 1;
            return $this->stats;
        }
        $allCarpetsId = (int)$allCarpets->getId();

        // 2. Ensure the two grouping parents exist.
        $colorsParentId = $this->getOrCreateCategory($allCarpetsId, self::COLORS_GROUP_NAME, $dryRun);
        $shapesParentId = $this->getOrCreateCategory($allCarpetsId, self::SHAPES_GROUP_NAME, $dryRun);
        if ($colorsParentId === null || $shapesParentId === null) {
            $msg = 'Failed to create the Colors/Shapes grouping categories. Aborting.';
            $this->logger->error($msg);
            $this->emit($msg);
            $this->stats['aborted'] = 1;
            return $this->stats;
        }

        // 3. Walk enabled products in batches.
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([self::COLOR_ATTRIBUTE_CODE, self::SHAPE_ATTRIBUTE_CODE, 'sku'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->setPageSize(self::BATCH_SIZE);

        $pages = $collection->getLastPageNumber();
        for ($page = 1; $page <= $pages; $page++) {
            $collection->setCurPage($page);
            $collection->clear();
            foreach ($collection as $product) {
                $this->processProduct($product, $colorsParentId, $shapesParentId, $dryRun);
            }
        }

        $summary = sprintf(
            '%sDone. Processed %d products | categories created %d | links added %d | '
            . 'skipped(already assigned) %d | skipped(no colour/shape) %d | errors %d.',
            $prefix,
            $this->stats['processed'],
            $this->stats['categories_created'],
            $this->stats['links_added'],
            $this->stats['skipped_assigned'],
            $this->stats['skipped_no_value'],
            $this->stats['errors']
        );
        $this->logger->info($summary);
        $this->emit($summary);

        return $this->stats;
    }

    private function resetState(): void
    {
        $this->categoryCache = [];
        $this->optionLabelMaps = [];
        $this->stats = [
            'processed' => 0,
            'categories_created' => 0,
            'links_added' => 0,
            'skipped_assigned' => 0,
            'skipped_no_value' => 0,
            'errors' => 0,
            'aborted' => 0,
        ];
    }

    /**
     * Send a human-facing message to the optional sink (no-op when unset).
     */
    private function emit(string $message): void
    {
        if ($this->notify !== null) {
            ($this->notify)($message);
        }
    }

    /**
     * Handle one product: resolve its colour/shape categories and link them.
     */
    private function processProduct(
        Product $product,
        int $colorsParentId,
        int $shapesParentId,
        bool $dryRun
    ): void {
        $this->stats['processed']++;
        $sku = (string)$product->getSku();

        try {
            $targetIds = [];
            $targetNames = [];

            // Colour is a multiselect: raw value is a comma separated list of option ids.
            foreach ($this->resolveLabels($product, self::COLOR_ATTRIBUTE_CODE) as $colorLabel) {
                $catId = $this->getOrCreateCategory($colorsParentId, $colorLabel, $dryRun);
                if ($catId !== null && $catId > 0) {
                    $targetIds[] = $catId;
                    $targetNames[] = 'Colors/' . $colorLabel;
                }
            }

            // Shape is a single select.
            foreach ($this->resolveLabels($product, self::SHAPE_ATTRIBUTE_CODE) as $shapeLabel) {
                $catId = $this->getOrCreateCategory($shapesParentId, $shapeLabel, $dryRun);
                if ($catId !== null && $catId > 0) {
                    $targetIds[] = $catId;
                    $targetNames[] = 'Shapes/' . $shapeLabel;
                }
            }

            if ($targetIds === []) {
                $this->stats['skipped_no_value']++;
                $this->logger->info(sprintf('Product "%s": no colour/shape value, skipped.', $sku));
                return;
            }

            $existingIds = array_map('intval', $product->getCategoryIds());
            $newIds = array_values(array_diff(array_unique($targetIds), $existingIds));

            if ($newIds === []) {
                $this->stats['skipped_assigned']++;
                $this->logger->info(sprintf(
                    'Product "%s": already assigned to [%s], skipped.',
                    $sku,
                    implode(', ', $targetNames)
                ));
                return;
            }

            $merged = array_values(array_unique(array_merge($existingIds, $targetIds)));

            if ($dryRun) {
                $this->logger->debug(sprintf(
                    '[DRY-RUN] Product "%s": would assign [%s] (category ids: %s).',
                    $sku,
                    implode(', ', $targetNames),
                    implode(', ', $newIds)
                ));
                $this->stats['links_added'] += count($newIds);
                return;
            }

            $this->categoryLinkManagement->assignProductToCategories($sku, $merged);
            $this->stats['links_added'] += count($newIds);
            $this->logger->info(sprintf(
                'Product "%s": assigned to [%s] (new category ids: %s).',
                $sku,
                implode(', ', $targetNames),
                implode(', ', $newIds)
            ));
        } catch (Exception $e) {
            $this->stats['errors']++;
            $msg = sprintf('Product "%s": assignment failed - %s', $sku, $e->getMessage());
            $this->logger->error($msg);
            $this->emit($msg);
        }
    }

    /**
     * Resolve the option labels set on a product for the given attribute.
     *
     * Works for both select (single id) and multiselect (comma separated ids).
     *
     * @return string[]
     */
    private function resolveLabels(Product $product, string $attributeCode): array
    {
        $raw = $product->getData($attributeCode);
        if ($raw === null || $raw === '' || $raw === false) {
            return [];
        }

        $map = $this->getOptionLabelMap($attributeCode);
        $labels = [];
        foreach (explode(',', (string)$raw) as $optionId) {
            $optionId = trim($optionId);
            if ($optionId === '') {
                continue;
            }
            $label = $map[$optionId] ?? null;
            if ($label !== null && $label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Option id => label map for an attribute, built once and cached in memory.
     *
     * @return array<string, string>
     */
    private function getOptionLabelMap(string $attributeCode): array
    {
        if (!isset($this->optionLabelMaps[$attributeCode])) {
            $map = [];
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
            foreach ($attribute->getSource()->getAllOptions() as $option) {
                if (($option['value'] ?? '') === '') {
                    continue;
                }
                $map[(string)$option['value']] = (string)$option['label'];
            }
            $this->optionLabelMaps[$attributeCode] = $map;
        }

        return $this->optionLabelMaps[$attributeCode];
    }

    /**
     * Return the id of the child category named $name under $parentId, creating
     * it if missing. Cached per run so it is queried/created only once. Returns
     * null on failure, or the sentinel 0 in dry-run when it would be created.
     */
    private function getOrCreateCategory(int $parentId, string $name, bool $dryRun): ?int
    {
        $cacheKey = $parentId . '|' . $name;
        if (isset($this->categoryCache[$cacheKey])) {
            return $this->categoryCache[$cacheKey];
        }

        // Look for an existing child with this exact name.
        $existing = $this->categoryCollectionFactory->create()
            ->addAttributeToFilter('parent_id', $parentId)
            ->addAttributeToFilter('name', $name)
            ->setPageSize(1)
            ->getFirstItem();

        if ($existing->getId()) {
            $id = (int)$existing->getId();
            $this->categoryCache[$cacheKey] = $id;
            $this->logger->info(sprintf('Category "%s" (id %d) under parent %d exists, reused.', $name, $id, $parentId));
            return $id;
        }

        if ($dryRun) {
            $this->logger->debug(sprintf('[DRY-RUN] Would create category "%s" under parent %d.', $name, $parentId));
            $this->emit(sprintf('[DRY-RUN] would create category "%s" under parent %d', $name, $parentId));
            // Cache a sentinel so we do not re-query the same missing category.
            $this->categoryCache[$cacheKey] = 0;
            return 0;
        }

        try {
            $parent = $this->categoryRepository->get($parentId);

            $category = $this->categoryFactory->create();
            $category->setName($name)
                ->setIsActive(true)
                ->setIsAnchor(true)
                ->setIncludeInMenu(true)
                ->setParentId($parentId)
                ->setPath($parent->getPath())
                ->setStoreId((int)$this->storeManager->getStore(0)->getId())
                ->setAvailableSortBy(['position'])
                ->setDefaultSortBy('position')
                ->setData('url_key', $this->buildUrlKey($category, $name));

            // NB: use the model's own save(), not CategoryRepositoryInterface::save().
            // The repository injects level=null into the entity before saving, which
            // makes the resource model's _beforeSave() skip its path/level build, so
            // repository-created categories end up with the parent's path and are
            // orphaned in the tree. The model save() builds path/level correctly.
            $category->save();
            $id = (int)$category->getId();
            $this->categoryCache[$cacheKey] = $id;
            $this->stats['categories_created']++;
            $this->logger->info(sprintf('Category "%s" (id %d) created under parent %d.', $name, $id, $parentId));
            $this->emit(sprintf('Created category "%s" (id %d)', $name, $id));
            return $id;
        } catch (Exception $e) {
            $this->stats['errors']++;
            $msg = sprintf('Failed to create category "%s" under parent %d - %s', $name, $parentId, $e->getMessage());
            $this->logger->error($msg);
            $this->emit($msg);
            return null;
        }
    }

    /**
     * Slugify a category name into a URL key (e.g. "White / Beige" => "white-beige").
     */
    private function buildUrlKey(Category $category, string $name): string
    {
        $urlKey = $category->formatUrlKey($name);
        return $urlKey !== '' ? $urlKey : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    }

    /**
     * Find the top-most "All Carpets" category at level 2 (deeper duplicates of
     * the same name exist and must be ignored).
     */
    private function findAllCarpetsCategory(): ?Category
    {
        $category = $this->categoryCollectionFactory->create()
            ->addAttributeToFilter('name', self::ALL_CARPETS_NAME)
            ->addAttributeToFilter('level', 2)
            ->setOrder('entity_id', 'ASC')
            ->setPageSize(1)
            ->getFirstItem();

        return $category->getId() ? $category : null;
    }
}
